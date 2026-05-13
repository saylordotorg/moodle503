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

/**
 * Exam Attempt Log — per-attempt operational view.
 *
 * One row per quiz attempt across all configured exam tracks.
 * Separate from the Achievement Ledger (course-level completion records).
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$dir = dirname(dirname(dirname($_SERVER['SCRIPT_FILENAME'] ?? __DIR__)));
require($dir . '/config.php');
require_login();

use local_completionhistory\table\exam_attempts_table;
use local_completionhistory\local\course_config_service;

$systemcontext = context_system::instance();
require_capability('local/completionhistory:viewall', $systemcontext);

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

// ---------------------------------------------------------------------------
// Filter parameters.
// ---------------------------------------------------------------------------
$filteruser       = optional_param('filteruser',     '',  PARAM_TEXT);   // Name or idnumber search.
$filtercoursename = optional_param('filtercoursename','', PARAM_TEXT);
$filtertrack      = optional_param('filtertrack',    '',  PARAM_ALPHA);   // program_final|direct_credit|certificate
$filterresult     = optional_param('filterresult',   '',  PARAM_ALPHA);   // passed|failed
$filterdatefrom   = optional_param('filterdatefrom', '',  PARAM_TEXT);
$filterdateto     = optional_param('filterdateto',   '',  PARAM_TEXT);
$filterexhausted  = optional_param('filterexhausted', 0,  PARAM_INT);    // 1 = show only exhausted-track rows
$filtercompletion = optional_param('filtercompletion', 0, PARAM_INT);    // 1 = completing attempt only

// ---------------------------------------------------------------------------
// Page setup.
// ---------------------------------------------------------------------------
$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/completionhistory/exam_attempt_log.php'));
$PAGE->set_title(get_string('examattemptlog', 'local_completionhistory'));
$PAGE->set_heading(get_string('examattemptlog', 'local_completionhistory'));
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

// ---------------------------------------------------------------------------
// Stats bar — quick counts across the full (unfiltered) dataset.
// ---------------------------------------------------------------------------
$stat_total    = $DB->count_records('local_completionhistory_exam_attempt');
$stat_passed   = $DB->count_records('local_completionhistory_exam_attempt', ['grade_passed' => 1]);
$stat_failed   = $DB->count_records('local_completionhistory_exam_attempt', ['grade_passed' => 0]);
$stat_completing = $DB->count_records('local_completionhistory_exam_attempt', ['resulted_in_completion' => 1]);
$stat_exhausted  = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {local_completionhistory_exam_attempt}
      WHERE attempts_allowed > 0
        AND attempt_number >= attempts_allowed
        AND grade_passed = 0"
);

// Per-track counts.
$track_counts = $DB->get_records_sql(
    "SELECT exam_track, COUNT(*) AS cnt
       FROM {local_completionhistory_exam_attempt}
      GROUP BY exam_track"
);
$by_track = [];
foreach ($track_counts as $r) { $by_track[$r->exam_track] = (int) $r->cnt; }

echo html_writer::start_div('row mb-4');

$stats = [
    ['total',       $stat_total,      'Total Attempts',    'secondary', ''],
    ['passed',      $stat_passed,     'Passed',            'success',   ''],
    ['failed',      $stat_failed,     'Failed',            'danger',    ''],
    ['exhausted',   $stat_exhausted,  'Track Exhausted',   'warning',   ''],
    ['completing',  $stat_completing, 'Completing Attempt','info',      ''],
];

foreach ($stats as [$key, $val, $label, $color, $extra]) {
    echo html_writer::start_div('col-md-2 col-sm-4 mb-2');
    echo html_writer::start_div("card border-{$color}");
    echo html_writer::start_div("card-body text-center p-2");
    echo html_writer::tag('div', number_format($val),
        ['class' => "h3 mb-0 text-{$color} font-weight-bold"]);
    echo html_writer::tag('div', $label, ['class' => 'small text-muted']);
    echo html_writer::end_div(); // card-body
    echo html_writer::end_div(); // card
    echo html_writer::end_div(); // col
}

// Per-track mini cards.
$track_display = [
    course_config_service::TRACK_PROGRAM_FINAL => ['Program Final', 'primary'],
    course_config_service::TRACK_DIRECT_CREDIT => ['Direct Credit', 'info'],
    course_config_service::TRACK_CERTIFICATE   => ['Certificate',   'success'],
];
foreach ($track_display as $track => [$tlabel, $tcolor]) {
    $cnt = $by_track[$track] ?? 0;
    echo html_writer::start_div('col-md-2 col-sm-4 mb-2');
    echo html_writer::start_div("card border-{$tcolor}");
    echo html_writer::start_div("card-body text-center p-2");
    echo html_writer::tag('div', number_format($cnt),
        ['class' => "h3 mb-0 text-{$tcolor} font-weight-bold"]);
    echo html_writer::tag('div', $tlabel, ['class' => 'small text-muted']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::end_div(); // row

// ---------------------------------------------------------------------------
// Filter form.
// ---------------------------------------------------------------------------
$reseturl = new moodle_url('/local/completionhistory/exam_attempt_log.php');
$ledgerurl = new moodle_url('/local/completionhistory/achievement_ledger.php');

// Back to ledger link.
echo html_writer::tag('a',
    '&#8592; ' . get_string('achievementledger', 'local_completionhistory'),
    ['href' => $ledgerurl->out(false), 'class' => 'btn btn-outline-secondary btn-sm mb-3']
);

echo html_writer::start_div('card mb-4');
echo html_writer::div(get_string('filter_heading', 'local_completionhistory'), 'card-header font-weight-bold');
echo html_writer::start_div('card-body');

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $PAGE->url->out_omit_querystring(),
    'id'     => 'attempt-filter-form',
]);

// ── Row 1: identity + course ─────────────────────────────────────────────────
echo html_writer::start_div('form-row align-items-end mb-3');

echo html_writer::start_div('form-group col-md-3');
echo html_writer::label(get_string('filter_user_search', 'local_completionhistory'), 'filteruser', true,
    ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'filteruser', 'id' => 'filteruser',
    'value' => $filteruser, 'class' => 'form-control form-control-sm',
    'placeholder' => 'Name or User ID#',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-3');
echo html_writer::label(get_string('col_coursename', 'local_completionhistory'), 'filtercoursename', true,
    ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'filtercoursename', 'id' => 'filtercoursename',
    'value' => $filtercoursename, 'class' => 'form-control form-control-sm',
    'placeholder' => 'Course name',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-2');
echo html_writer::label(get_string('col_exam_track', 'local_completionhistory'), 'filtertrack', true,
    ['class' => 'small font-weight-bold']);
$track_options = [
    '' => get_string('filter_examtrack_any', 'local_completionhistory'),
    course_config_service::TRACK_PROGRAM_FINAL => get_string('track_program_final', 'local_completionhistory'),
    course_config_service::TRACK_DIRECT_CREDIT => get_string('track_direct_credit', 'local_completionhistory'),
    course_config_service::TRACK_CERTIFICATE   => get_string('track_certificate',   'local_completionhistory'),
];
echo html_writer::select($track_options, 'filtertrack', $filtertrack, false,
    ['id' => 'filtertrack', 'class' => 'form-control form-control-sm']);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-2');
echo html_writer::label(get_string('col_attempt_result', 'local_completionhistory'), 'filterresult', true,
    ['class' => 'small font-weight-bold']);
$result_options = [
    ''       => get_string('filter_passed_any', 'local_completionhistory'),
    'passed' => get_string('filter_passed_yes', 'local_completionhistory'),
    'failed' => get_string('filter_passed_no',  'local_completionhistory'),
];
echo html_writer::select($result_options, 'filterresult', $filterresult, false,
    ['id' => 'filterresult', 'class' => 'form-control form-control-sm']);
echo html_writer::end_div();

echo html_writer::end_div(); // row 1

// ── Row 2: date range + boolean flags ────────────────────────────────────────
echo html_writer::start_div('form-row align-items-end mb-3');

echo html_writer::start_div('form-group col-md-2');
echo html_writer::label(get_string('filter_datefrom', 'local_completionhistory'), 'filterdatefrom', true,
    ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'filterdatefrom', 'id' => 'filterdatefrom',
    'value' => $filterdatefrom, 'class' => 'form-control form-control-sm',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-2');
echo html_writer::label(get_string('filter_dateto', 'local_completionhistory'), 'filterdateto', true,
    ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'filterdateto', 'id' => 'filterdateto',
    'value' => $filterdateto, 'class' => 'form-control form-control-sm',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-3 d-flex align-items-end');
echo html_writer::start_div('d-flex flex-column');

echo html_writer::start_div('form-check mb-1');
$exhattrs = ['type' => 'checkbox', 'name' => 'filterexhausted', 'id' => 'filterexhausted',
    'value' => '1', 'class' => 'form-check-input'];
if ($filterexhausted) $exhattrs['checked'] = 'checked';
echo html_writer::empty_tag('input', $exhattrs);
echo html_writer::label(
    get_string('filter_exhausted_only', 'local_completionhistory'),
    'filterexhausted', true, ['class' => 'form-check-label small']);
echo html_writer::end_div();

echo html_writer::start_div('form-check');
$compattrs = ['type' => 'checkbox', 'name' => 'filtercompletion', 'id' => 'filtercompletion',
    'value' => '1', 'class' => 'form-check-input'];
if ($filtercompletion) $compattrs['checked'] = 'checked';
echo html_writer::empty_tag('input', $compattrs);
echo html_writer::label(
    get_string('filter_completing_only', 'local_completionhistory'),
    'filtercompletion', true, ['class' => 'form-check-label small']);
echo html_writer::end_div();

echo html_writer::end_div(); // flex-column
echo html_writer::end_div(); // col

echo html_writer::end_div(); // row 2

// Submit / reset.
echo html_writer::start_div('form-row');
echo html_writer::start_div('col-12');
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('search'),
    'class' => 'btn btn-primary btn-sm mr-2']);
echo html_writer::tag('a', get_string('reset'), ['href' => $reseturl->out(false),
    'class' => 'btn btn-secondary btn-sm']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_tag('form');
echo html_writer::end_div(); // card-body
echo html_writer::end_div(); // card

// ---------------------------------------------------------------------------
// Build SQL WHERE conditions.
// ---------------------------------------------------------------------------
$conditions = ['1 = 1'];
$params     = [];

if (!empty($filteruser)) {
    $likeval = '%' . $DB->sql_like_escape($filteruser) . '%';
    $conditions[] = '(
        ' . $DB->sql_like('u.firstname', ':fname', false) . '
     OR ' . $DB->sql_like('u.lastname',  ':lname', false) . '
     OR ' . $DB->sql_like('u.idnumber',  ':idnum', false) . '
    )';
    $params['fname'] = $likeval;
    $params['lname'] = $likeval;
    $params['idnum'] = $likeval;
}

if (!empty($filtercoursename)) {
    $conditions[] = $DB->sql_like('c.fullname', ':filtercoursename', false);
    $params['filtercoursename'] = '%' . $DB->sql_like_escape($filtercoursename) . '%';
}

if (!empty($filtertrack)) {
    $conditions[] = 'ea.exam_track = :filtertrack';
    $params['filtertrack'] = $filtertrack;
}

if ($filterresult === 'passed') {
    $conditions[] = 'ea.grade_passed = 1';
} elseif ($filterresult === 'failed') {
    $conditions[] = 'ea.grade_passed = 0';
}

if (!empty($filterdatefrom)) {
    $ts = strtotime($filterdatefrom);
    if ($ts !== false) {
        $conditions[] = 'ea.timetaken >= :filterdatefrom';
        $params['filterdatefrom'] = $ts;
    }
}
if (!empty($filterdateto)) {
    $ts = strtotime($filterdateto . ' 23:59:59');
    if ($ts !== false) {
        $conditions[] = 'ea.timetaken <= :filterdateto';
        $params['filterdateto'] = $ts;
    }
}

if ($filterexhausted) {
    $conditions[] = 'ea.attempts_allowed > 0 AND ea.attempt_number >= ea.attempts_allowed AND ea.grade_passed = 0';
}

if ($filtercompletion) {
    $conditions[] = 'ea.resulted_in_completion = 1';
}

$where = implode(' AND ', $conditions);

// ---------------------------------------------------------------------------
// Build base URL (carries all filter state through pagination + sorting).
// ---------------------------------------------------------------------------
$urlparams = array_filter([
    'filteruser'       => $filteruser      ?: null,
    'filtercoursename' => $filtercoursename ?: null,
    'filtertrack'      => $filtertrack     ?: null,
    'filterresult'     => $filterresult    ?: null,
    'filterdatefrom'   => $filterdatefrom  ?: null,
    'filterdateto'     => $filterdateto    ?: null,
    'filterexhausted'  => $filterexhausted ?: null,
    'filtercompletion' => $filtercompletion ?: null,
], fn($v) => $v !== null && $v !== '');
$tableurl = new moodle_url('/local/completionhistory/exam_attempt_log.php', $urlparams);

// ---------------------------------------------------------------------------
// Render table.
// ---------------------------------------------------------------------------
$table = new exam_attempts_table('local_completionhistory_attempts');

$table->set_sql(
    'ea.*,
     u.firstname  AS user_firstname,
     u.lastname   AS user_lastname,
     u.idnumber   AS useridnumber,
     c.fullname   AS course_fullname,
     c.shortname  AS course_shortname',
    '{local_completionhistory_exam_attempt} ea
     LEFT JOIN {user}   u ON u.id  = ea.userid
     LEFT JOIN {course} c ON c.id  = ea.courseid',
    $where,
    $params
);

$table->define_baseurl($tableurl);
$table->out(50, true);

// ---------------------------------------------------------------------------
// Inline JS: highlight rows on hover, no other interactions needed.
// ---------------------------------------------------------------------------
echo html_writer::script('
(function() {
    // Highlight entire student group on row hover.
    document.querySelectorAll(".generaltable tbody tr").forEach(function(row) {
        row.addEventListener("mouseenter", function() { this.style.background = "#fffbea"; });
        row.addEventListener("mouseleave", function() { this.style.background = ""; });
    });
})();
');

echo $OUTPUT->footer();
