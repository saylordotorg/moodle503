<?php
/**
 * Seed exam attempt data for all existing demo achievement records.
 *
 * Run: sudo -u www-data php /var/www/moodle45/seed_exam_attempts.php
 *
 * What this does:
 *  1. Creates course_exam_config records for all relevant courses.
 *  2. For each achievement record (non-anonymized), generates exam attempt
 *     rows that are consistent with the recorded grade and pass/fail outcome.
 *  3. Updates achievements with exam_track / attempts_used / attempts_allowed.
 *  4. Links the completing attempt back to its achievement.
 *
 * Course type rules:
 *   open_dual  → SOC101(3), COMM001(4), BUS101(7), BUS200(8), PHYS101(9)
 *                DC track: 3 attempts max.  Cert track: unlimited.
 *   program    → MBA601-MBA609 (10-18)
 *                Program Final track: 3 attempts max.
 */
define('CLI_SCRIPT', true);
require('/var/www/moodle45/config.php');

use local_completionhistory\local\course_config_service;
use local_completionhistory\local\exam_attempt_service;

$now = time();
mt_srand(42); // Deterministic randomness for reproducibility.

// ─────────────────────────────────────────────────────────────────────────────
// 1.  COURSE EXAM CONFIGS
// ─────────────────────────────────────────────────────────────────────────────
echo "=== Setting up course exam configs ===\n";

$open_dual_courses = [3, 4, 7, 8, 9];   // SOC101, COMM001, BUS101, BUS200, PHYS101
$program_courses   = [10, 11, 12, 13, 14, 15, 16, 17, 18]; // MBA601-609

foreach ($open_dual_courses as $cid) {
    $cfg = new stdClass();
    $cfg->courseid              = $cid;
    $cfg->course_type           = course_config_service::TYPE_OPEN_DUAL;
    $cfg->program_final_quizid  = null;
    $cfg->dc_quizid             = null; // No real quiz mapped yet.
    $cfg->cert_quizid           = null;
    $cfg->program_attempts_allowed = 3;
    $cfg->dc_attempts_allowed      = 3;
    $cfg->cert_attempts_allowed    = 0; // Unlimited.
    $cfg->notes = 'Auto-seeded: open dual-track course (DC + Certificate).';
    course_config_service::save_config($cfg);
    $course = $DB->get_field('course', 'shortname', ['id' => $cid]);
    echo "  [open_dual] {$course} (id={$cid})\n";
}

foreach ($program_courses as $cid) {
    $cfg = new stdClass();
    $cfg->courseid              = $cid;
    $cfg->course_type           = course_config_service::TYPE_PROGRAM;
    $cfg->program_final_quizid  = null;
    $cfg->dc_quizid             = null;
    $cfg->cert_quizid           = null;
    $cfg->program_attempts_allowed = 3;
    $cfg->dc_attempts_allowed      = 3;
    $cfg->cert_attempts_allowed    = 0;
    $cfg->notes = 'Auto-seeded: program course (Program Final exam).';
    course_config_service::save_config($cfg);
    $course = $DB->get_field('course', 'shortname', ['id' => $cid]);
    echo "  [program]   {$course} (id={$cid})\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// 2.  SEED ATTEMPTS FOR EVERY NON-ANONYMIZED ACHIEVEMENT
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== Generating attempt records ===\n";

$achievements = $DB->get_records_select(
    'local_completionhistory_achievement',
    'userid != 0 AND exam_track IS NULL',
    [],
    'courseid, userid'
);

$total_attempts   = 0;
$total_updated    = 0;
$skipped_no_config = 0;

foreach ($achievements as $ach) {
    $uid      = (int) $ach->userid;
    $cid      = (int) $ach->courseid;
    $passed   = (int) $ach->grade_passed; // 1 = passed, 0 = failed
    $comptime = (int) $ach->completiontime;

    // ── Determine course type ──────────────────────────────────────────────
    if (in_array($cid, $open_dual_courses)) {
        $course_type = 'open_dual';
    } elseif (in_array($cid, $program_courses)) {
        $course_type = 'program';
    } else {
        $skipped_no_config++;
        continue; // Course not configured — skip.
    }

    // ── Build attempt sequence ─────────────────────────────────────────────
    // Returns array of attempt descriptors to insert, plus the completing
    // exam_track, attempts_used, attempts_allowed for the achievement.

    $attempts_to_insert = [];
    $completing_track   = null;
    $attempts_used_on_completing = 0;
    $completing_allowed = 0;

    if ($course_type === 'program') {
        $allowed = 3;
        $track   = course_config_service::TRACK_PROGRAM_FINAL;

        if ($passed) {
            // Passed: 1–3 attempts, last one passes.
            $num_attempts = mt_rand(1, 3);
            for ($n = 1; $n <= $num_attempts; $n++) {
                $is_last    = ($n === $num_attempts);
                $att_passed = $is_last ? true : false;
                $att_grade  = $is_last
                    ? (float) $ach->grade_decimal   // Use the recorded grade for the pass.
                    : round(mt_rand(25, 59) + mt_rand(0, 99) / 100, 2);
                // Space attempts ~7-21 days apart, working backwards from comptime.
                $days_before = ($num_attempts - $n) * mt_rand(7, 21);
                $att_time    = $comptime - $days_before * 86400;

                $attempts_to_insert[] = [
                    'track'    => $track,
                    'n'        => $n,
                    'allowed'  => $allowed,
                    'grade'    => $att_grade,
                    'passed'   => $att_passed,
                    'time'     => max($att_time, $comptime - 120 * 86400),
                    'completing' => $is_last,
                ];
            }
            $completing_track            = $track;
            $attempts_used_on_completing = $num_attempts;
            $completing_allowed          = $allowed;
        } else {
            // Failed: all 3 attempts fail (track exhausted).
            for ($n = 1; $n <= 3; $n++) {
                $att_grade = round(mt_rand(20, 58) + mt_rand(0, 99) / 100, 2);
                $days_before = (3 - $n) * mt_rand(7, 21);
                $att_time    = $comptime - $days_before * 86400;

                $attempts_to_insert[] = [
                    'track'    => $track,
                    'n'        => $n,
                    'allowed'  => $allowed,
                    'grade'    => $att_grade,
                    'passed'   => false,
                    'time'     => max($att_time, $comptime - 120 * 86400),
                    'completing' => false, // None complete.
                ];
            }
            $completing_track            = $track;
            $attempts_used_on_completing = 3;
            $completing_allowed          = $allowed;
        }

    } elseif ($course_type === 'open_dual') {
        $dc_allowed   = 3;
        $cert_allowed = 0; // Unlimited.

        if ($passed) {
            // 65% chance passed via Direct Credit, 35% passed via Certificate
            // (after failing DC 3×).
            $via_dc = (mt_rand(1, 100) <= 65);

            if ($via_dc) {
                // Passed DC: 1–3 DC attempts, last passes.
                $num_dc = mt_rand(1, 3);
                for ($n = 1; $n <= $num_dc; $n++) {
                    $is_last  = ($n === $num_dc);
                    $att_grade = $is_last
                        ? (float) $ach->grade_decimal
                        : round(mt_rand(30, 59) + mt_rand(0, 99) / 100, 2);
                    $days_before = ($num_dc - $n) * mt_rand(7, 14);
                    $att_time    = $comptime - $days_before * 86400;

                    $attempts_to_insert[] = [
                        'track'    => course_config_service::TRACK_DIRECT_CREDIT,
                        'n'        => $n,
                        'allowed'  => $dc_allowed,
                        'grade'    => $att_grade,
                        'passed'   => $is_last,
                        'time'     => max($att_time, $comptime - 90 * 86400),
                        'completing' => $is_last,
                    ];
                }
                $completing_track            = course_config_service::TRACK_DIRECT_CREDIT;
                $attempts_used_on_completing = $num_dc;
                $completing_allowed          = $dc_allowed;

            } else {
                // Failed DC 3×, then passed Certificate.
                // ─ 3 failed DC attempts.
                $dc_start_offset = mt_rand(30, 80) * 86400; // DC attempts started N days before cert pass.
                for ($n = 1; $n <= 3; $n++) {
                    $att_grade   = round(mt_rand(20, 58) + mt_rand(0, 99) / 100, 2);
                    $days_before = (3 - $n) * mt_rand(7, 14);
                    $att_time    = ($comptime - $dc_start_offset) + ($n - 1) * mt_rand(7, 14) * 86400;

                    $attempts_to_insert[] = [
                        'track'    => course_config_service::TRACK_DIRECT_CREDIT,
                        'n'        => $n,
                        'allowed'  => $dc_allowed,
                        'grade'    => $att_grade,
                        'passed'   => false,
                        'time'     => max($att_time, $comptime - 150 * 86400),
                        'completing' => false,
                    ];
                }
                // ─ 1–3 cert attempts, last passes.
                $num_cert = mt_rand(1, 3);
                for ($n = 1; $n <= $num_cert; $n++) {
                    $is_last   = ($n === $num_cert);
                    $att_grade = $is_last
                        ? (float) $ach->grade_decimal
                        : round(mt_rand(35, 59) + mt_rand(0, 99) / 100, 2);
                    $days_before = ($num_cert - $n) * mt_rand(3, 10);
                    $att_time    = $comptime - $days_before * 86400;

                    $attempts_to_insert[] = [
                        'track'    => course_config_service::TRACK_CERTIFICATE,
                        'n'        => $n,
                        'allowed'  => $cert_allowed,
                        'grade'    => $att_grade,
                        'passed'   => $is_last,
                        'time'     => max($att_time, $comptime - 30 * 86400),
                        'completing' => $is_last,
                    ];
                }
                $completing_track            = course_config_service::TRACK_CERTIFICATE;
                $attempts_used_on_completing = $num_cert;
                $completing_allowed          = $cert_allowed;
            }

        } else {
            // Failed: exhausted DC (3×) AND failed cert (2–3 attempts).
            for ($n = 1; $n <= 3; $n++) {
                $att_grade   = round(mt_rand(20, 58) + mt_rand(0, 99) / 100, 2);
                $days_before = (5 - $n) * mt_rand(7, 14);
                $att_time    = $comptime - $days_before * 86400;

                $attempts_to_insert[] = [
                    'track'    => course_config_service::TRACK_DIRECT_CREDIT,
                    'n'        => $n,
                    'allowed'  => $dc_allowed,
                    'grade'    => $att_grade,
                    'passed'   => false,
                    'time'     => max($att_time, $comptime - 120 * 86400),
                    'completing' => false,
                ];
            }
            $num_cert_fails = mt_rand(2, 3);
            for ($n = 1; $n <= $num_cert_fails; $n++) {
                $att_grade   = round(mt_rand(20, 58) + mt_rand(0, 99) / 100, 2);
                $days_before = ($num_cert_fails - $n) * mt_rand(3, 10);
                $att_time    = $comptime - $days_before * 86400;

                $attempts_to_insert[] = [
                    'track'    => course_config_service::TRACK_CERTIFICATE,
                    'n'        => $n,
                    'allowed'  => $cert_allowed,
                    'grade'    => $att_grade,
                    'passed'   => false,
                    'time'     => max($att_time, $comptime - 60 * 86400),
                    'completing' => false,
                ];
            }
            $completing_track            = course_config_service::TRACK_CERTIFICATE;
            $attempts_used_on_completing = $num_cert_fails;
            $completing_allowed          = $cert_allowed;
        }
    }

    // ── Insert attempt records ─────────────────────────────────────────────
    $completing_attempt_id = null;
    foreach ($attempts_to_insert as $a) {
        $record                      = new stdClass();
        $record->userid              = $uid;
        $record->courseid            = $cid;
        $record->quizid              = null;
        $record->exam_track          = $a['track'];
        $record->attempt_number      = $a['n'];
        $record->attempts_allowed    = $a['allowed'];
        $record->grade_decimal       = round($a['grade'], 5);
        $record->grade_passed        = $a['passed'] ? 1 : 0;
        $record->resulted_in_completion = $a['completing'] ? 1 : 0;
        $record->achievementid       = $a['completing'] ? $ach->id : null;
        $record->timetaken           = (int) $a['time'];
        $record->timecreated         = $now;

        $att_id = $DB->insert_record('local_completionhistory_exam_attempt', $record);

        if ($a['completing']) {
            $completing_attempt_id = $att_id;
        }
        $total_attempts++;
    }

    // ── Update achievement with exam context ───────────────────────────────
    $DB->set_field('local_completionhistory_achievement', 'exam_track',
        $completing_track, ['id' => $ach->id]);
    $DB->set_field('local_completionhistory_achievement', 'attempts_used',
        $attempts_used_on_completing, ['id' => $ach->id]);
    $DB->set_field('local_completionhistory_achievement', 'attempts_allowed',
        $completing_allowed, ['id' => $ach->id]);

    $total_updated++;
}

// ─────────────────────────────────────────────────────────────────────────────
// 3.  SUMMARY
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== SUMMARY ===\n";
printf("  Achievements processed : %d\n", $total_updated);
printf("  Skipped (no config)    : %d\n", $skipped_no_config);
printf("  Exam attempts inserted : %d\n", $total_attempts);

// Track breakdown.
$rows = $DB->get_records_sql("
    SELECT exam_track, COUNT(*) AS cnt
    FROM {local_completionhistory_exam_attempt}
    GROUP BY exam_track
    ORDER BY exam_track
");
echo "\n  Attempts by track:\n";
foreach ($rows as $r) {
    printf("    %-20s %d\n", $r->exam_track, $r->cnt);
}

// Achievement exam_track breakdown.
$rows2 = $DB->get_records_sql("
    SELECT exam_track, attempts_used, COUNT(*) AS cnt
    FROM {local_completionhistory_achievement}
    WHERE exam_track IS NOT NULL
    GROUP BY exam_track, attempts_used
    ORDER BY exam_track, attempts_used
");
echo "\n  Achievements by track + attempts_used:\n";
foreach ($rows2 as $r) {
    printf("    %-20s  used=%-3s  count=%d\n",
        $r->exam_track, $r->attempts_used ?? '?', $r->cnt);
}

// Open-dual drama: students who failed DC then passed cert.
$drama = $DB->count_records_sql("
    SELECT COUNT(DISTINCT a.id)
    FROM {local_completionhistory_achievement} a
    WHERE a.exam_track = 'certificate'
      AND EXISTS (
          SELECT 1 FROM {local_completionhistory_exam_attempt} ea
          WHERE ea.userid = a.userid AND ea.courseid = a.courseid
            AND ea.exam_track = 'direct_credit' AND ea.grade_passed = 0
      )
");
printf("\n  'Failed DC → passed Cert' stories: %d\n", $drama);

echo "\nDone!\n";
