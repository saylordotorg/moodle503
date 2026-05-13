<?php
/**
 * Generate 10 demo students for the "Prerequisite to MBA" program (Moodle 4.5)
 * Run: sudo -u www-data php /var/www/moodle45/deploy_generate_prereq.php
 *
 * Creates a new program with the 5 general/prerequisite courses:
 *   SOC101 (3), COMM001 (4), BUS101 (7), BUS200 (8), PHYS101 (9)
 *
 * Student profiles:
 *   0-2  PREREQ-COMPLETED  — All 5 prereqs done, high grades, ready for MBA
 *   3-5  PREREQ-IN-PROGRESS — 2-3 prereqs done, still working
 *   6-7  PREREQ-STRUGGLING  — 1-2 prereqs done, low grades, some failures
 *   8-9  PREREQ-JUST-STARTED — 0-1 prereqs done, freshly enrolled
 */
define('CLI_SCRIPT', true);
require('/var/www/moodle45/config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');
require_once($CFG->dirroot . '/lib/gradelib.php');

$now = time();

// ============================================================================
// 1. CREATE 10 NEW STUDENTS
// ============================================================================
echo "=== Creating 10 prereq demo students ===\n";

$students_data = [
    ['rachel.green',    'Rachel',   'Green'],
    ['tom.nakamura',    'Tom',      'Nakamura'],
    ['lina.okafor',     'Lina',     'Okafor'],
    ['jason.park',      'Jason',    'Park'],
    ['nadia.kowalski',  'Nadia',    'Kowalski'],
    ['sam.rivera',      'Sam',      'Rivera'],
    ['elena.volkov',    'Elena',    'Volkov'],
    ['derek.osei',      'Derek',    'Osei'],
    ['mia.chang',       'Mia',      'Chang'],
    ['andre.dupont',    'Andre',    'Dupont'],
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
    $user->idnumber  = 'PREREQ' . str_pad($i + 1, 3, '0', STR_PAD_LEFT);
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
// 2. CREATE "PREREQUISITE TO MBA" PROGRAM
// ============================================================================
echo "\n=== Creating Prerequisite to MBA Program ===\n";

$prereq_courses = [3, 4, 7, 8, 9]; // SOC101, COMM001, BUS101, BUS200, PHYS101

$program = $DB->get_record('enrol_programs_programs', ['idnumber' => 'PREREQ-MBA']);
if (!$program) {
    $program = new stdClass();
    $program->fullname = 'Prerequisite to the MBA Program';
    $program->idnumber = 'PREREQ-MBA';
    $program->description = 'Complete these 5 foundational courses before entering the MBA program.';
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
    echo "  Created Prerequisite program (id={$program->id})\n";

    // Manual allocation source
    $source = new stdClass();
    $source->programid = $program->id;
    $source->type = 'manual';
    $source->datajson = '{}';
    $source->id = $DB->insert_record('enrol_programs_sources', $source);
    echo "  Created manual source (id={$source->id})\n";

    // Top-level set
    $topitem = new stdClass();
    $topitem->programid = $program->id;
    $topitem->topitem = 1;
    $topitem->previtemid = null;
    $topitem->courseid = null;
    $topitem->fullname = 'MBA Prerequisite Courses';
    $topitem->sequencejson = json_encode(["type" => "allinanyorder"]);
    $topitem->minprerequisites = 0;
    $topitem->points = 1;
    $topitem->minpoints = null;
    $topitem->completiondelay = 0;
    $topitem->id = $DB->insert_record('enrol_programs_items', $topitem);

    // Add each prereq course
    $previd = null;
    foreach ($prereq_courses as $cid) {
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
    echo "  Added " . count($prereq_courses) . " courses to program\n";
} else {
    $source = $DB->get_record('enrol_programs_sources', ['programid' => $program->id, 'type' => 'manual']);
    echo "  Prerequisite program exists (id={$program->id})\n";
}

// ============================================================================
// 3. ENROL & GENERATE COMPLETIONS
// ============================================================================
echo "\n=== Enrolling students & generating completions ===\n";

$enrol_manual = enrol_get_plugin('manual');
$studentrole = $DB->get_record('role', ['shortname' => 'student']);

foreach ($studentids as $idx => $uid) {
    $username = $students_data[$idx][0];

    if ($idx < 3) {
        // PREREQ-COMPLETED: All 5 courses done, high grades
        $profile = 'PREREQ-COMPLETED';
        $courses_to_enrol = $prereq_courses;
        $courses_to_complete = $prereq_courses;
        $pass_rate = 1.0;
        $grade_min = 75;
        $grade_max = 98;
        $program_completed = true;
    } elseif ($idx < 6) {
        // PREREQ-IN-PROGRESS: 2-3 courses done, decent grades
        $profile = 'PREREQ-IN-PROGRESS';
        $courses_to_enrol = $prereq_courses;
        $done_count = rand(2, 3);
        $courses_to_complete = array_slice($prereq_courses, 0, $done_count);
        $pass_rate = 0.85;
        $grade_min = 62;
        $grade_max = 92;
        $program_completed = false;
    } elseif ($idx < 8) {
        // PREREQ-STRUGGLING: 1-2 done, low grades, some failures
        $profile = 'PREREQ-STRUGGLING';
        $courses_to_enrol = array_slice($prereq_courses, 0, rand(3, 4));
        $done_count = rand(1, 2);
        $courses_to_complete = array_slice($prereq_courses, 0, $done_count);
        $pass_rate = 0.35;
        $grade_min = 28;
        $grade_max = 68;
        $program_completed = false;
    } else {
        // PREREQ-JUST-STARTED: 0-1 done, freshly enrolled
        $profile = 'PREREQ-JUST-STARTED';
        $courses_to_enrol = array_slice($prereq_courses, 0, rand(2, 3));
        $done_count = rand(0, 1);
        $courses_to_complete = array_slice($prereq_courses, 0, $done_count);
        $pass_rate = 0.6;
        $grade_min = 50;
        $grade_max = 80;
        $program_completed = false;
    }

    echo "  [{$profile}] {$username} (id={$uid}): enrol=" . count($courses_to_enrol)
       . " complete=" . count($courses_to_complete) . "\n";

    // --- Enrol in courses ---
    foreach ($courses_to_enrol as $cid) {
        $instance = $DB->get_record('enrol', ['courseid' => $cid, 'enrol' => 'manual']);
        if (!$instance) {
            continue;
        }
        if (!$DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $uid])) {
            $timestart = $now - rand(30, 180) * 86400;
            $enrol_manual->enrol_user($instance, $uid, $studentrole->id, $timestart, 0);
        }
    }

    // --- Complete courses & grade ---
    foreach ($courses_to_complete as $cid) {
        $passed = (mt_rand(0, 100) / 100.0) < $pass_rate;
        $grade  = $passed ? rand(max($grade_min, 65), $grade_max) : rand($grade_min, 59);
        $completion_time = $now - rand(7, 150) * 86400;

        // course_completions
        $existing = $DB->get_record('course_completions', ['userid' => $uid, 'course' => $cid]);
        if ($existing) {
            $existing->timecompleted = $completion_time;
            $existing->timestarted = $completion_time - rand(14, 90) * 86400;
            $DB->update_record('course_completions', $existing);
        } else {
            $cc = new stdClass();
            $cc->userid = $uid;
            $cc->course = $cid;
            $cc->timeenrolled = $completion_time - rand(30, 120) * 86400;
            $cc->timestarted  = $completion_time - rand(14, 90) * 86400;
            $cc->timecompleted = $completion_time;
            $cc->reaggregate = 0;
            $DB->insert_record('course_completions', $cc);
        }

        // Grade
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

        $hash_input = "{$uid}|{$cid}|{$completion_time}|cli_prereq_generator";
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
            $ach->source_component = 'cli_prereq_generator';
            $ach->source_event = 'local_completionhistory\\cli\\generate_prereq_demo';
            $ach->source_event_hash = $event_hash;
            $ach->timecreated = $now;
            $achid = $DB->insert_record('local_completionhistory_achievement', $ach);

            // Link to Prerequisite program
            $prog_ach = new stdClass();
            $prog_ach->achievementid = $achid;
            $prog_ach->allocationid = null;
            $prog_ach->programid = $program->id;
            $prog_ach->programidnumber_snapshot = 'PREREQ-MBA';
            $prog_ach->programname_snapshot = 'Prerequisite to the MBA Program';
            $prog_ach->timecreated = $now;
            $DB->insert_record('local_completionhistory_ach_program', $prog_ach);
        }
    }

    // --- Program allocation ---
    $existing_alloc = $DB->get_record('enrol_programs_allocations', [
        'programid' => $program->id,
        'userid' => $uid,
    ]);
    if (!$existing_alloc) {
        $alloc = new stdClass();
        $alloc->programid = $program->id;
        $alloc->userid = $uid;
        $alloc->sourceid = $source->id;
        $alloc->archived = 0;
        $alloc->sourcedatajson = '{}';
        $alloc->timeallocated = $now - rand(30, 200) * 86400;
        $alloc->timestart = $alloc->timeallocated;
        $alloc->timedue = null;
        $alloc->timeend = null;
        $alloc->timecompleted = $program_completed ? ($now - rand(3, 30) * 86400) : null;
        $alloc->timecreated = $alloc->timeallocated;
        $alloc->id = $DB->insert_record('enrol_programs_allocations', $alloc);

        // Back-fill allocationid on program achievement links
        $DB->execute(
            "UPDATE {local_completionhistory_ach_program} SET allocationid = ?
              WHERE programid = ? AND achievementid IN (
                SELECT id FROM {local_completionhistory_achievement} WHERE userid = ?
              )",
            [$alloc->id, $program->id, $uid]
        );

        $status = $alloc->timecompleted ? 'COMPLETED' : 'ACTIVE';
        echo "    -> Prereq allocation id={$alloc->id} [{$status}]\n";
    }
}

// ============================================================================
// 4. SUMMARY
// ============================================================================
echo "\n=== SUMMARY ===\n";
echo "  Total students (all): " . $DB->count_records_select('user', 'deleted=0 AND id > 2') . "\n";
echo "  Prereq program allocations: " . $DB->count_records('enrol_programs_allocations', ['programid' => $program->id]) . "\n";
echo "  Total course completions: " . $DB->count_records('course_completions') . "\n";
echo "  Total achievement ledger: " . $DB->count_records('local_completionhistory_achievement') . "\n";
echo "  Total program achievements: " . $DB->count_records('local_completionhistory_ach_program') . "\n";

$passed = $DB->count_records_select('local_completionhistory_achievement',
    "grade_passed = 1 AND source_component = 'cli_prereq_generator'");
$failed = $DB->count_records_select('local_completionhistory_achievement',
    "grade_passed = 0 AND source_component = 'cli_prereq_generator'");
echo "  Prereq passed: {$passed} | Prereq failed: {$failed}\n";
echo "\nDone!\n";
