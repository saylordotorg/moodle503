<?php
/**
 * Generate 25 demo students with varied completions for Moodle 4.5
 * Run: sudo -u www-data php /var/www/moodle45/deploy_generate_demo.php
 */
define('CLI_SCRIPT', true);
require('/var/www/moodle45/config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');
require_once($CFG->dirroot . '/lib/gradelib.php');

$now = time();

// ============================================================================
// 1. CREATE 25 STUDENTS
// ============================================================================
echo "=== Creating 25 demo students ===\n";

$students_data = [
    ['alex.chen',      'Alex',    'Chen'],
    ['maria.santos',   'Maria',   'Santos'],
    ['james.wilson',   'James',   'Wilson'],
    ['priya.patel',    'Priya',   'Patel'],
    ['omar.hassan',    'Omar',    'Hassan'],
    ['sarah.johnson',  'Sarah',   'Johnson'],
    ['kenji.tanaka',   'Kenji',   'Tanaka'],
    ['emma.davis',     'Emma',    'Davis'],
    ['lucas.silva',    'Lucas',   'Silva'],
    ['aisha.mohammed', 'Aisha',   'Mohammed'],
    ['ryan.oconnor',   'Ryan',    'OConnor'],
    ['yuki.nakamura',  'Yuki',    'Nakamura'],
    ['carlos.garcia',  'Carlos',  'Garcia'],
    ['nina.petrov',    'Nina',    'Petrov'],
    ['david.kim',      'David',   'Kim'],
    ['fatima.ali',     'Fatima',  'Ali'],
    ['marcus.brown',   'Marcus',  'Brown'],
    ['sofia.rossi',    'Sofia',   'Rossi'],
    ['wei.zhang',      'Wei',     'Zhang'],
    ['olivia.taylor',  'Olivia',  'Taylor'],
    ['ahmed.ibrahim',  'Ahmed',   'Ibrahim'],
    ['hannah.mueller', 'Hannah',  'Mueller'],
    ['diego.lopez',    'Diego',   'Lopez'],
    ['mei.lin',        'Mei',     'Lin'],
    ['jack.thompson',  'Jack',    'Thompson'],
];

$studentids = [];
foreach ($students_data as $i => $s) {
    $user = new stdClass();
    $user->username  = $s[0];
    $user->firstname = $s[1];
    $user->lastname  = $s[2];
    $user->email     = $s[0] . '@demo.sylr.org';
    $user->password  = 'Demo1234!';
    $user->confirmed = 1;
    $user->mnethostid = $CFG->mnet_localhost_id;
    $user->idnumber  = 'DEMO' . str_pad($i + 1, 3, '0', STR_PAD_LEFT);
    $user->auth      = 'manual';

    $existing = $DB->get_record('user', ['username' => $user->username]);
    if ($existing) {
        $studentids[] = $existing->id;
        echo "  Exists: {$user->username} (id={$existing->id})\n";
    } else {
        $id = user_create_user($user, true, false);
        $studentids[] = $id;
        echo "  Created: {$user->username} (id={$id})\n";
    }
}

// ============================================================================
// 2. CREATE MBA PROGRAM
// ============================================================================
echo "\n=== Creating MBA Program ===\n";

$mba_courses = [10, 11, 12, 13, 14, 15, 16, 17, 18]; // MBA601-MBA609
$general_courses = [3, 4, 7, 8, 9]; // SOC101, COMM001, BUS101, BUS200, PHYS101

$program = $DB->get_record('enrol_programs_programs', ['idnumber' => 'MBA-PROG']);
if (!$program) {
    $program = new stdClass();
    $program->fullname = 'Master of Business Administration';
    $program->idnumber = 'MBA-PROG';
    $program->description = 'Saylor Academy MBA Program';
    $program->descriptionformat = FORMAT_HTML;
    $program->presentationjson = '{}';
    $program->contextid = context_system::instance()->id;
    $program->public = 1;
    $program->archived = 0;
    $program->creategroups = 0;
    $program->timeallocationstart = null;
    $program->timeallocationend = null;
    $program->startdatejson = json_encode(["type" => "allocation"]);
    $program->duedatejson = json_encode(["type" => "notset"]);
    $program->enddatejson = json_encode(["type" => "notset"]);
    $program->timecreated = $now;
    $program->id = $DB->insert_record('enrol_programs_programs', $program);
    echo "  Created MBA program (id={$program->id})\n";

    // Manual allocation source.
    $source = new stdClass();
    $source->programid = $program->id;
    $source->type = 'manual';
    $source->datajson = '{}';
    $source->id = $DB->insert_record('enrol_programs_sources', $source);

    // Top-level set (topitem=1 means this IS the top item).
    $topitem = new stdClass();
    $topitem->programid = $program->id;
    $topitem->topitem = 1;
    $topitem->previtemid = null;
    $topitem->courseid = null;
    $topitem->fullname = 'MBA Core Courses';
    $topitem->sequencejson = json_encode(["type" => "allinanyorder"]);
    $topitem->minprerequisites = 0;
    $topitem->points = 1;
    $topitem->minpoints = null;
    $topitem->completiondelay = 0;
    $topitem->id = $DB->insert_record('enrol_programs_items', $topitem);

    // Add each MBA course.
    $previd = null;
    foreach ($mba_courses as $cid) {
        $item = new stdClass();
        $item->programid = $program->id;
        $item->topitem = 0;
        $item->previtemid = $previd;
        $item->courseid = $cid;
        $item->fullname = $DB->get_field('course', 'fullname', ['id' => $cid]);
        $item->sequencejson = '{}';
        $item->minprerequisites = 0;
        $item->points = 1;
        $item->minpoints = null;
        $item->completiondelay = 0;
        $item->id = $DB->insert_record('enrol_programs_items', $item);
        $previd = $item->id;
    }
    echo "  Added " . count($mba_courses) . " courses to MBA program\n";
} else {
    $source = $DB->get_record('enrol_programs_sources', ['programid' => $program->id, 'type' => 'manual']);
    echo "  MBA program exists (id={$program->id})\n";
}

// ============================================================================
// 3. ENROL & GENERATE COMPLETIONS
// ============================================================================
echo "\n=== Enrolling students & generating completions ===\n";

$enrol_manual = enrol_get_plugin('manual');
$studentrole = $DB->get_record('role', ['shortname' => 'student']);

/*
 * Student profiles:
 *  0-4  (A) MBA completed & passed    — all 9 MBA done, grades 70-98
 *  5-9  (B) MBA in-progress           — 3-6 MBA done, still going
 * 10-14 (C) MBA withdrawn/failed      — 1-4 MBA done, low grades, archived
 * 15-19 (D) General courses only       — 2-4 general done, no MBA
 * 20-24 (E) Mixed early-stage          — 1-3 general + 0-2 MBA started
 */
foreach ($studentids as $idx => $uid) {
    $username = $students_data[$idx][0];

    if ($idx < 5) {
        $profile = 'MBA-COMPLETED';
        $courses_to_complete = $mba_courses;
        $courses_to_enrol = array_merge($mba_courses, array_slice($general_courses, 0, rand(2, 4)));
        $pass_rate = 1.0;
        $grade_min = 70; $grade_max = 98;
        $allocate_mba = true;
        $mba_completed = true;
    } elseif ($idx < 10) {
        $profile = 'MBA-IN-PROGRESS';
        $done_count = rand(3, 6);
        $courses_to_complete = array_slice($mba_courses, 0, $done_count);
        $courses_to_enrol = $mba_courses;
        $pass_rate = 0.9;
        $grade_min = 60; $grade_max = 95;
        $allocate_mba = true;
        $mba_completed = false;
    } elseif ($idx < 15) {
        $profile = 'MBA-WITHDRAWN';
        $done_count = rand(1, 4);
        $courses_to_complete = array_slice($mba_courses, 0, $done_count);
        $courses_to_enrol = array_slice($mba_courses, 0, $done_count + rand(1, 2));
        $pass_rate = 0.4;
        $grade_min = 25; $grade_max = 72;
        $allocate_mba = true;
        $mba_completed = false;
    } elseif ($idx < 20) {
        $profile = 'GENERAL-ONLY';
        $done_count = rand(2, 4);
        $courses_to_complete = array_slice($general_courses, 0, $done_count);
        $courses_to_enrol = $general_courses;
        $pass_rate = 0.8;
        $grade_min = 55; $grade_max = 95;
        $allocate_mba = false;
        $mba_completed = false;
    } else {
        $profile = 'MIXED';
        $gen_done = rand(1, 3);
        $mba_done = rand(0, 2);
        $courses_to_complete = array_merge(
            array_slice($general_courses, 0, $gen_done),
            array_slice($mba_courses, 0, $mba_done)
        );
        $courses_to_enrol = array_merge(
            $general_courses,
            array_slice($mba_courses, 0, max($mba_done, rand(1, 3)))
        );
        $pass_rate = 0.7;
        $grade_min = 45; $grade_max = 90;
        $allocate_mba = ($mba_done > 0);
        $mba_completed = false;
    }

    echo "  [{$profile}] {$username} (id={$uid}): enrol=" . count($courses_to_enrol)
       . " complete=" . count($courses_to_complete) . "\n";

    // --- Enrol ---
    foreach ($courses_to_enrol as $cid) {
        $instance = $DB->get_record('enrol', ['courseid' => $cid, 'enrol' => 'manual']);
        if (!$instance) {
            continue;
        }
        if (!$DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $uid])) {
            $timestart = $now - rand(90, 365) * 86400;
            $enrol_manual->enrol_user($instance, $uid, $studentrole->id, $timestart, 0);
        }
    }

    // --- Complete courses & grade ---
    foreach ($courses_to_complete as $cid) {
        $passed = (mt_rand(0, 100) / 100.0) < $pass_rate;
        $grade  = $passed ? rand(max($grade_min, 65), $grade_max) : rand($grade_min, 59);
        $completion_time = $now - rand(7, 300) * 86400;

        // course_completions
        $existing = $DB->get_record('course_completions', ['userid' => $uid, 'course' => $cid]);
        if ($existing) {
            $existing->timecompleted = $completion_time;
            $existing->timestarted = $completion_time - rand(14, 120) * 86400;
            $DB->update_record('course_completions', $existing);
        } else {
            $cc = new stdClass();
            $cc->userid = $uid;
            $cc->course = $cid;
            $cc->timeenrolled = $completion_time - rand(30, 180) * 86400;
            $cc->timestarted  = $completion_time - rand(14, 120) * 86400;
            $cc->timecompleted = $completion_time;
            $cc->reaggregate = 0;
            $DB->insert_record('course_completions', $cc);
        }

        // grade
        $grade_item = grade_item::fetch_course_item($cid);
        if ($grade_item) {
            $grade_item->update_final_grade($uid, $grade, 'manual');
            if (empty($grade_item->gradepass)) {
                $grade_item->gradepass = 60.0;
                $grade_item->update();
            }
        }

        // --- Achievement ledger ---
        $course_rec = $DB->get_record('course', ['id' => $cid]);
        $user_rec   = $DB->get_record('user', ['id' => $uid]);

        $hash_input = "{$uid}|{$cid}|{$completion_time}|cli_demo_generator";
        $event_hash = hash('sha256', $hash_input);

        if (!$DB->record_exists('local_completionhistory_achievement', ['source_event_hash' => $event_hash])) {
            $ach = new stdClass();
            $ach->ledgeruuid = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
            $ach->userid = $uid;
            $ach->useridnumber_snapshot = $user_rec->idnumber;
            $ach->courseid = $cid;
            $ach->courseidnumber_snapshot = $course_rec->idnumber ?? '';
            $ach->courseshortname_snapshot = $course_rec->shortname;
            $ach->coursename_snapshot = $course_rec->fullname;
            $ach->completiontime = $completion_time;
            $ach->grade_decimal = round($grade, 5);
            $ach->grade_passed = $passed ? 1 : 0;
            $ach->grade_source = 'gradebook';
            $ach->artifacturl = null;
            $ach->artifactstorage = 'none';
            $ach->source_component = 'cli_demo_generator';
            $ach->source_event = 'local_completionhistory\\cli\\generate_demo';
            $ach->source_event_hash = $event_hash;
            $ach->timecreated = $now;
            $achid = $DB->insert_record('local_completionhistory_achievement', $ach);

            // Link to MBA program if applicable.
            if ($allocate_mba && in_array($cid, $mba_courses)) {
                $prog_ach = new stdClass();
                $prog_ach->achievementid = $achid;
                $prog_ach->allocationid = null;
                $prog_ach->programid = $program->id;
                $prog_ach->programidnumber_snapshot = 'MBA-PROG';
                $prog_ach->programname_snapshot = 'Master of Business Administration';
                $prog_ach->timecreated = $now;
                $DB->insert_record('local_completionhistory_ach_program', $prog_ach);
            }
        }
    }

    // --- MBA program allocation ---
    if ($allocate_mba) {
        $existing_alloc = $DB->get_record('enrol_programs_allocations', [
            'programid' => $program->id,
            'userid' => $uid,
        ]);
        if (!$existing_alloc) {
            $alloc = new stdClass();
            $alloc->programid = $program->id;
            $alloc->userid = $uid;
            $alloc->sourceid = $source->id;
            $alloc->archived = ($profile === 'MBA-WITHDRAWN') ? 1 : 0;
            $alloc->sourcedatajson = '{}';
            $alloc->timeallocated = $now - rand(100, 365) * 86400;
            $alloc->timestart = $alloc->timeallocated;
            $alloc->timedue = null;
            $alloc->timeend = null;
            $alloc->timecompleted = $mba_completed ? ($now - rand(7, 60) * 86400) : null;
            $alloc->timecreated = $alloc->timeallocated;
            $alloc->id = $DB->insert_record('enrol_programs_allocations', $alloc);

            // Back-fill allocationid on program achievement links.
            $DB->execute(
                "UPDATE {local_completionhistory_ach_program} SET allocationid = ?
                  WHERE programid = ? AND achievementid IN (
                    SELECT id FROM {local_completionhistory_achievement} WHERE userid = ?
                  )",
                [$alloc->id, $program->id, $uid]
            );

            $status = $alloc->archived ? 'ARCHIVED/WITHDRAWN' : ($alloc->timecompleted ? 'COMPLETED' : 'ACTIVE');
            echo "    -> MBA allocation id={$alloc->id} [{$status}]\n";
        }
    }
}

// ============================================================================
// 4. SUMMARY
// ============================================================================
echo "\n=== SUMMARY ===\n";
echo "  Students: " . $DB->count_records_select('user', 'deleted=0 AND id > 2') . "\n";
echo "  Course completions: " . $DB->count_records('course_completions') . "\n";
echo "  Achievement ledger: " . $DB->count_records('local_completionhistory_achievement') . "\n";
echo "  Program achievements: " . $DB->count_records('local_completionhistory_ach_program') . "\n";
echo "  MBA allocations: " . $DB->count_records('enrol_programs_allocations') . "\n";

$passed = $DB->count_records('local_completionhistory_achievement', ['grade_passed' => 1]);
$failed = $DB->count_records('local_completionhistory_achievement', ['grade_passed' => 0]);
echo "  Passed: {$passed} | Failed: {$failed}\n";
echo "\nDone!\n";
