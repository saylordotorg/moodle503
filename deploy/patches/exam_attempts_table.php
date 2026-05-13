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

namespace local_completionhistory\table;

use table_sql;
use html_writer;
use moodle_url;
use local_completionhistory\local\course_config_service;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/tablelib.php');

/**
 * Table for the Exam Attempt Log page.
 *
 * One row per exam attempt. Joined to mdl_user and mdl_course for display.
 * The base SQL (fields + from + where) is set by the calling page so that
 * filter conditions can be applied before the table renders.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exam_attempts_table extends table_sql {

    private const TRACK_BADGE = [
        course_config_service::TRACK_PROGRAM_FINAL => ['Program Final', 'badge-primary'],
        course_config_service::TRACK_DIRECT_CREDIT => ['Direct Credit', 'badge-info'],
        course_config_service::TRACK_CERTIFICATE   => ['Certificate',   'badge-success'],
    ];

    public function __construct(string $uniqueid) {
        parent::__construct($uniqueid);

        $this->define_columns([
            'user_firstname',
            'user_lastname',
            'useridnumber',
            'course_fullname',
            'course_shortname',
            'exam_track',
            'attempt_number',
            'grade_decimal',
            'grade_passed',
            'timetaken',
            'achievement',
        ]);

        $this->define_headers([
            get_string('col_firstname',      'local_completionhistory'),
            get_string('col_lastname',       'local_completionhistory'),
            get_string('col_useridnumber',   'local_completionhistory'),
            get_string('col_coursename',     'local_completionhistory'),
            get_string('col_courseshortname','local_completionhistory'),
            get_string('col_exam_track',     'local_completionhistory'),
            get_string('col_attempt_number', 'local_completionhistory'),
            get_string('col_grade',          'local_completionhistory'),
            get_string('col_attempt_result', 'local_completionhistory'),
            get_string('col_attempt_date',   'local_completionhistory'),
            get_string('col_achievement_link','local_completionhistory'),
        ]);

        $this->no_sorting('achievement');
        $this->sortable(true, 'timetaken', SORT_DESC);
    }

    // ── User identity ────────────────────────────────────────────────────────

    public function col_user_firstname($row): string {
        if ((int) $row->userid === 0) {
            return html_writer::tag('em', 'anonymized', ['class' => 'text-muted']);
        }
        return s($row->user_firstname ?? '');
    }

    public function col_user_lastname($row): string {
        if ((int) $row->userid === 0) return '';
        return s($row->user_lastname ?? '');
    }

    public function col_useridnumber($row): string {
        return s($row->useridnumber ?? '');
    }

    // ── Course ───────────────────────────────────────────────────────────────

    public function col_course_fullname($row): string {
        return format_string($row->course_fullname ?? '');
    }

    public function col_course_shortname($row): string {
        return s($row->course_shortname ?? '');
    }

    // ── Exam track ───────────────────────────────────────────────────────────

    public function col_exam_track($row): string {
        [$label, $cls] = self::TRACK_BADGE[$row->exam_track] ?? [$row->exam_track, 'badge-secondary'];
        return html_writer::tag('span', $label, [
            'class' => "badge {$cls}",
            'style' => 'font-size:0.82em',
        ]);
    }

    // ── Attempt number ───────────────────────────────────────────────────────

    public function col_attempt_number($row): string {
        $n       = (int) $row->attempt_number;
        $allowed = (int) $row->attempts_allowed;
        $label   = $allowed === 0 ? '∞' : $allowed;

        $html = html_writer::tag('span', "{$n} of {$label}", ['class' => 'font-weight-bold']);

        // "Final attempt" warning badge.
        if ($allowed > 0 && $n === $allowed) {
            $html .= ' ' . html_writer::tag('span', 'Final', [
                'class' => 'badge badge-warning',
                'style' => 'font-size:0.75em',
            ]);
        }
        return $html;
    }

    // ── Grade ────────────────────────────────────────────────────────────────

    public function col_grade_decimal($row): string {
        if ($row->grade_decimal === null) return '-';
        return format_float((float) $row->grade_decimal, 1) . '%';
    }

    // ── Result ───────────────────────────────────────────────────────────────

    public function col_grade_passed($row): string {
        $n       = (int) $row->attempt_number;
        $allowed = (int) $row->attempts_allowed;
        $passed  = $row->grade_passed;

        if ($passed === null || $passed === '') {
            return html_writer::tag('span', '— N/A',
                ['class' => 'badge badge-secondary', 'style' => 'font-size:0.85em']);
        }

        if ((int) $passed === 1) {
            // Passed — did it complete the course?
            $icon = (int) $row->resulted_in_completion
                ? '&#10003; Passed &#127775;'
                : '&#10003; Passed';
            return html_writer::tag('span', $icon,
                ['class' => 'badge badge-success', 'style' => 'font-size:0.85em']);
        }

        // Failed — was this the last allowed attempt (track exhausted)?
        $exhausted = ($allowed > 0 && $n >= $allowed);
        $label = $exhausted
            ? '&#10007; Failed — track exhausted'
            : '&#10007; Failed';
        return html_writer::tag('span', $label,
            ['class' => 'badge badge-danger', 'style' => 'font-size:0.85em']);
    }

    // ── Date ─────────────────────────────────────────────────────────────────

    public function col_timetaken($row): string {
        if (empty($row->timetaken)) return '-';
        $ts   = (int) $row->timetaken;
        $days = (int) floor((time() - $ts) / 86400);

        $date = userdate($ts, '%m/%d/%Y');
        $ago  = $days === 0 ? 'today'
              : ($days === 1 ? '1 day ago'
              : number_format($days) . ' days ago');

        return $date . html_writer::tag('span', ' (' . $ago . ')',
            ['class' => 'text-muted small']);
    }

    // ── Achievement link ─────────────────────────────────────────────────────

    public function col_achievement($row): string {
        if ((int) $row->userid === 0) return '-';
        if (empty($row->achievementid)) return '-';

        $url = new moodle_url('/local/completionhistory/achievement_ledger.php', [
            'filteruserid'     => (int) $row->userid,
            'filtercoursename' => $row->course_fullname ?? '',
        ]);
        return html_writer::link($url->out(false), '&#8594; Ledger',
            ['class' => 'btn btn-outline-secondary btn-sm',
             'style' => 'font-size:0.75em; padding:2px 8px;']);
    }
}
