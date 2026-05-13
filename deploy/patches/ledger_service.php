<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_completionhistory\local;

use stdClass;

/**
 * Central service for inserting immutable achievement ledger rows.
 *
 * All inserts are idempotent via a deterministic SHA-256 event hash.
 * Rows are never mutated or deleted after insertion.
 *
 * When a course completion is triggered by a tracked exam attempt, callers
 * should pass the optional $exam_context parameter to record which track
 * and attempt count led to completion.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ledger_service {

    /**
     * Capture an achievement from a course completion record.
     *
     * @param stdClass    $completion      course_completions row (userid, course, timecompleted).
     * @param string      $sourcecomponent Component that triggered capture.
     * @param string      $sourceevent     Event class name or CLI identifier.
     * @param string|null $exam_track      Exam track that completed the course, or null.
     * @param int|null    $attempts_used   Attempts consumed on the completing track.
     * @param int|null    $attempts_allowed Max attempts allowed on that track (0 = unlimited).
     * @return int|null New achievement ID, or null if already captured (idempotent skip).
     */
    public static function capture_achievement(
        stdClass $completion,
        string $sourcecomponent,
        string $sourceevent,
        ?string $exam_track = null,
        ?int $attempts_used = null,
        ?int $attempts_allowed = null
    ): ?int {
        global $DB;

        $userid        = (int) $completion->userid;
        $courseid      = (int) $completion->course;
        $timecompleted = (int) $completion->timecompleted;

        // Compute deterministic dedup hash.
        $hash = self::compute_event_hash($userid, $courseid, $timecompleted, $sourcecomponent);

        // Idempotency check.
        if ($DB->record_exists('local_completionhistory_achievement', ['source_event_hash' => $hash])) {
            return null;
        }

        // Snapshot course metadata.
        $course          = $DB->get_record('course', ['id' => $courseid]);
        $coursename      = $course ? $course->fullname : '[deleted]';
        $courseshortname = $course ? $course->shortname : null;
        $courseidnumber  = $course ? $course->idnumber  : null;

        // Snapshot user idnumber.
        $user         = $DB->get_record('user', ['id' => $userid], 'id, idnumber');
        $useridnumber = $user ? $user->idnumber : null;

        // Snapshot grade if enabled.
        $gradedata = null;
        if (get_config('local_completionhistory', 'capturegrades')) {
            $gradedata = grade_snapshot_service::get_course_total($userid, $courseid);
        }

        // Resolve program context.
        $programs = program_context_resolver::resolve($userid, $courseid);

        // If no exam_track was explicitly provided, try to auto-detect from course config.
        if ($exam_track === null) {
            $config = course_config_service::get_config($courseid);
            if ($config->course_type !== course_config_service::TYPE_STANDARD) {
                // Infer from the most recent completing attempt if available.
                $completing = $DB->get_record_select(
                    'local_completionhistory_exam_attempt',
                    'userid = :uid AND courseid = :cid AND resulted_in_completion = 1',
                    ['uid' => $userid, 'cid' => $courseid],
                    '*',
                    IGNORE_MULTIPLE
                );
                if ($completing) {
                    $exam_track       = $completing->exam_track;
                    $attempts_used    = (int) $completing->attempt_number;
                    $attempts_allowed = (int) $completing->attempts_allowed;
                }
            }
        }

        // Build the achievement record.
        $record                    = new stdClass();
        $record->ledgeruuid        = self::generate_uuid();
        $record->userid            = $userid;
        $record->useridnumber_snapshot     = $useridnumber ?: null;
        $record->courseid                  = $courseid;
        $record->courseidnumber_snapshot   = $courseidnumber ?: null;
        $record->courseshortname_snapshot  = $courseshortname;
        $record->coursename_snapshot       = $coursename;
        $record->completiontime            = $timecompleted;
        $record->grade_decimal             = $gradedata ? $gradedata->finalgrade : null;
        $record->grade_passed              = $gradedata ? $gradedata->passed     : null;
        $record->grade_source              = $gradedata ? 'gradebook'            : null;
        $record->exam_track                = $exam_track;
        $record->attempts_used             = $attempts_used;
        $record->attempts_allowed          = $attempts_allowed;
        $record->artifacturl               = null;
        $record->artifactstorage           = null;
        $record->source_component          = $sourcecomponent;
        $record->source_event              = $sourceevent;
        $record->source_event_hash         = $hash;
        $record->timecreated               = time();

        // Wrap in transaction: achievement + program rows.
        $transaction = $DB->start_delegated_transaction();
        try {
            $achievementid = $DB->insert_record('local_completionhistory_achievement', $record);

            foreach ($programs as $program) {
                $progrecord                           = new stdClass();
                $progrecord->achievementid            = $achievementid;
                $progrecord->allocationid             = $program->allocationid ?? null;
                $progrecord->programid                = $program->programid    ?? null;
                $progrecord->programidnumber_snapshot = $program->idnumber     ?? null;
                $progrecord->programname_snapshot     = $program->fullname;
                $progrecord->timecreated              = time();
                $DB->insert_record('local_completionhistory_ach_program', $progrecord);
            }

            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
            throw $e;
        }

        return $achievementid;
    }

    /**
     * Update the exam context on an existing achievement row.
     * Used when the completing attempt is identified after the achievement
     * was already written (e.g. backfill scenarios).
     *
     * @param int    $achievementid
     * @param string $exam_track
     * @param int    $attempts_used
     * @param int    $attempts_allowed
     */
    public static function set_exam_context(
        int $achievementid,
        string $exam_track,
        int $attempts_used,
        int $attempts_allowed
    ): void {
        global $DB;

        $DB->set_field('local_completionhistory_achievement', 'exam_track',       $exam_track,       ['id' => $achievementid]);
        $DB->set_field('local_completionhistory_achievement', 'attempts_used',    $attempts_used,    ['id' => $achievementid]);
        $DB->set_field('local_completionhistory_achievement', 'attempts_allowed', $attempts_allowed, ['id' => $achievementid]);
    }

    /**
     * Record a purge audit entry.
     */
    public static function record_purge_audit(int $userid, ?int $programid, string $reason, ?string $detailsjson = null): int {
        global $DB;

        $record              = new stdClass();
        $record->userid      = $userid;
        $record->programid   = $programid;
        $record->reason      = $reason;
        $record->detailsjson = $detailsjson;
        $record->timecreated = time();

        return $DB->insert_record('local_completionhistory_purge_audit', $record);
    }

    /**
     * Compute deterministic SHA-256 hash for deduplication.
     */
    public static function compute_event_hash(int $userid, int $courseid, int $timecompleted, string $sourcecomponent): string {
        return hash('sha256', $userid . '|' . $courseid . '|' . $timecompleted . '|' . $sourcecomponent);
    }

    /**
     * Generate a UUID v4.
     */
    public static function generate_uuid(): string {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
