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
 * Achievement Ledger — staff view with filters.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$dir = dirname(dirname(dirname($_SERVER['SCRIPT_FILENAME'] ?? __DIR__)));
require($dir . '/config.php');
require_login();

use local_completionhistory\table\achievements_table;

$systemcontext = context_system::instance();
require_capability('local/completionhistory:viewall', $systemcontext);

if (!get_config('local_completionhistory', 'enabled')) {
    throw new moodle_exception('plugindisabled', 'local_completionhistory');
}

// ---------------------------------------------------------------------------
// Filter parameters.
// ---------------------------------------------------------------------------
$filteruserid      = optional_param('filteruserid', 0, PARAM_INT);
$filtercoursename  = optional_param('filtercoursename', '', PARAM_TEXT);
$filtersource      = optional_param('filtersource', '', PARAM_TEXT);
$filterpassed      = optional_param('filterpassed', '', PARAM_ALPHA);
$filterhasprograms = optional_param('filterhasprograms', 0, PARAM_INT);
$filterprogramids  = optional_param('filterprogramids', '', PARAM_TEXT);
$filterdatefrom    = optional_param('filterdatefrom', '', PARAM_TEXT);
$filterdateto      = optional_param('filterdateto', '', PARAM_TEXT);

// Column order: comma-separated list of column names from the drag-and-drop UI.
$colorderraw = optional_param('colorder', '', PARAM_TEXT);
$colorder    = array_values(array_filter(array_map('trim', explode(',', $colorderraw))));

// Optional extra columns.
$optionalcoldefs = [
    'courseidnumber_snapshot' => get_string('col_courseidnumber', 'local_completionhistory'),
    'source_event'            => get_string('col_source_event', 'local_completionhistory'),
    'artifacturl'             => get_string('col_artifact', 'local_completionhistory'),
];
$visibleoptionalcols = [];
foreach (array_keys($optionalcoldefs) as $colname) {
    if (optional_param('showcol_' . $colname, 0, PARAM_INT)) {
        $visibleoptionalcols[] = $colname;
    }
}

// Parse comma-separated program IDs.
$programids = array_values(array_filter(array_map('intval', explode(',', $filterprogramids))));

// ---------------------------------------------------------------------------
// Page setup.
// ---------------------------------------------------------------------------
$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/completionhistory/achievement_ledger.php'));
$PAGE->set_title(get_string('achievementledger', 'local_completionhistory'));
$PAGE->set_heading(get_string('achievementledger', 'local_completionhistory'));
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

// ---------------------------------------------------------------------------
// Fetch programs for the multi-select dropdown.
// ---------------------------------------------------------------------------
$availableprograms = $DB->get_records_sql(
    'SELECT programid, MIN(programname_snapshot) AS programname_snapshot
       FROM {local_completionhistory_ach_program}
      WHERE programid IS NOT NULL
      GROUP BY programid
      ORDER BY MIN(programname_snapshot)'
);

// ---------------------------------------------------------------------------
// Build all visible column labels for the column-order UI.
// These are the staff default columns plus any active optional extras.
// ---------------------------------------------------------------------------
$allcollabels = achievements_table::staff_col_labels();
foreach ($visibleoptionalcols as $col) {
    if (isset($optionalcoldefs[$col])) {
        $allcollabels[$col] = $optionalcoldefs[$col];
    }
}

// If a custom order is stored, reorder $allcollabels to match for the UI.
if (!empty($colorder)) {
    $reordered = [];
    foreach ($colorder as $col) {
        if (isset($allcollabels[$col])) {
            $reordered[$col] = $allcollabels[$col];
            unset($allcollabels[$col]);
        }
    }
    foreach ($allcollabels as $col => $lbl) {
        $reordered[$col] = $lbl;
    }
    $allcollabels = $reordered;
}

// ---------------------------------------------------------------------------
// Filter form.
// ---------------------------------------------------------------------------
$reseturl = new moodle_url('/local/completionhistory/achievement_ledger.php');

echo html_writer::start_div('card mb-4');
echo html_writer::div(get_string('filter_heading', 'local_completionhistory'), 'card-header font-weight-bold');
echo html_writer::start_div('card-body');

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $PAGE->url->out_omit_querystring(),
    'id'     => 'ledger-filter-form',
]);

// ── Row 1: basic filters ────────────────────────────────────────────────────
echo html_writer::start_div('form-row align-items-end mb-3');

echo html_writer::start_div('form-group col-md-2');
echo html_writer::label(get_string('col_user', 'local_completionhistory'), 'filteruserid', true, ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'filteruserid', 'id' => 'filteruserid',
    'value' => $filteruserid ?: '', 'class' => 'form-control form-control-sm', 'placeholder' => 'User ID',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-3');
echo html_writer::label(get_string('col_coursename', 'local_completionhistory'), 'filtercoursename', true, ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'filtercoursename', 'id' => 'filtercoursename',
    'value' => $filtercoursename, 'class' => 'form-control form-control-sm', 'placeholder' => 'Course name',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-2');
echo html_writer::label(get_string('col_source', 'local_completionhistory'), 'filtersource', true, ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'filtersource', 'id' => 'filtersource',
    'value' => $filtersource, 'class' => 'form-control form-control-sm', 'placeholder' => 'Source component',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-2');
echo html_writer::label(get_string('filter_passed', 'local_completionhistory'), 'filterpassed', true, ['class' => 'small font-weight-bold']);
$passedoptions = [
    ''     => get_string('filter_passed_any', 'local_completionhistory'),
    '1'    => get_string('filter_passed_yes', 'local_completionhistory'),
    '0'    => get_string('filter_passed_no', 'local_completionhistory'),
    'null' => get_string('filter_passed_unknown', 'local_completionhistory'),
];
echo html_writer::select($passedoptions, 'filterpassed', $filterpassed, false,
    ['id' => 'filterpassed', 'class' => 'form-control form-control-sm']);
echo html_writer::end_div();

echo html_writer::end_div(); // row 1

// ── Row 2: date range ───────────────────────────────────────────────────────
echo html_writer::start_div('form-row align-items-end mb-3');

echo html_writer::start_div('form-group col-md-3');
echo html_writer::label(get_string('filter_datefrom', 'local_completionhistory'), 'filterdatefrom', true, ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'filterdatefrom', 'id' => 'filterdatefrom',
    'value' => $filterdatefrom, 'class' => 'form-control form-control-sm',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-3');
echo html_writer::label(get_string('filter_dateto', 'local_completionhistory'), 'filterdateto', true, ['class' => 'small font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'filterdateto', 'id' => 'filterdateto',
    'value' => $filterdateto, 'class' => 'form-control form-control-sm',
]);
echo html_writer::end_div();

echo html_writer::end_div(); // row 2

// ── Row 3: program filters ──────────────────────────────────────────────────
echo html_writer::start_div('form-row align-items-start mb-3');

echo html_writer::start_div('form-group col-md-3');
echo html_writer::tag('p', get_string('filter_programs_heading', 'local_completionhistory'), ['class' => 'small font-weight-bold mb-1']);
echo html_writer::start_div('form-check');
$hasprogattrs = [
    'type' => 'checkbox', 'name' => 'filterhasprograms', 'id' => 'filterhasprograms',
    'value' => '1', 'class' => 'form-check-input',
];
if ($filterhasprograms) $hasprogattrs['checked'] = 'checked';
echo html_writer::empty_tag('input', $hasprogattrs);
echo html_writer::label(get_string('filter_hasprograms', 'local_completionhistory'), 'filterhasprograms', true, ['class' => 'form-check-label small']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('form-group col-md-5');
echo html_writer::label(get_string('filter_programs', 'local_completionhistory'), 'filterprogramselector', true, ['class' => 'small font-weight-bold']);
echo html_writer::tag('small', get_string('filter_programs_help', 'local_completionhistory'), ['class' => 'd-block text-muted mb-1']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filterprogramids', 'id' => 'filterprogramids', 'value' => $filterprogramids]);
$selopts = ['id' => 'filterprogramselector', 'multiple' => 'multiple', 'class' => 'form-control form-control-sm', 'size' => '4'];
if (empty($availableprograms)) $selopts['disabled'] = 'disabled';
echo html_writer::start_tag('select', $selopts);
foreach ($availableprograms as $prog) {
    $optattrs = ['value' => $prog->programid];
    if (in_array((int)$prog->programid, $programids)) $optattrs['selected'] = 'selected';
    echo html_writer::tag('option', format_string($prog->programname_snapshot), $optattrs);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

echo html_writer::end_div(); // row 3

// ── Row 4: extra columns ────────────────────────────────────────────────────
echo html_writer::start_div('form-row mb-3');
echo html_writer::start_div('col-12');
echo html_writer::tag('p', get_string('filter_columns', 'local_completionhistory'), ['class' => 'small font-weight-bold mb-1']);
echo html_writer::start_div('d-flex flex-wrap');
foreach ($optionalcoldefs as $colname => $label) {
    $cbattrs = [
        'type' => 'checkbox', 'name' => 'showcol_' . $colname, 'id' => 'showcol_' . $colname,
        'value' => '1', 'class' => 'form-check-input',
    ];
    if (in_array($colname, $visibleoptionalcols)) $cbattrs['checked'] = 'checked';
    echo html_writer::start_div('form-check mr-4');
    echo html_writer::empty_tag('input', $cbattrs);
    echo html_writer::label($label, 'showcol_' . $colname, true, ['class' => 'form-check-label small']);
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div(); // row 4

// ── Row 5: column order drag-and-drop ───────────────────────────────────────
echo html_writer::start_div('form-row mb-3');
echo html_writer::start_div('col-12');
echo html_writer::tag('p', get_string('filter_colorder', 'local_completionhistory'), ['class' => 'small font-weight-bold mb-1']);
echo html_writer::tag('p', get_string('filter_colorder_help', 'local_completionhistory'), ['class' => 'small text-muted mb-2']);

// Render draggable column badges.
echo html_writer::start_tag('div', [
    'id'    => 'col-order-list',
    'class' => 'd-flex flex-wrap',
    'style' => 'gap:6px; min-height:36px; padding:6px; border:1px dashed #ccc; border-radius:4px; background:#fafafa; cursor:grab;',
]);
foreach ($allcollabels as $col => $label) {
    echo html_writer::tag('span', '&#8597; ' . s($label), [
        'class'         => 'badge badge-light border',
        'draggable'     => 'true',
        'data-col'      => $col,
        'style'         => 'font-size:0.85em; padding:5px 10px; cursor:grab; user-select:none;',
    ]);
}
echo html_writer::end_tag('div');

// Hidden input holds the final order as a comma-separated list.
echo html_writer::empty_tag('input', [
    'type'  => 'hidden',
    'name'  => 'colorder',
    'id'    => 'colorder-input',
    'value' => implode(',', array_keys($allcollabels)),
]);

echo html_writer::end_div();
echo html_writer::end_div(); // row 5

// ── Submit / reset ──────────────────────────────────────────────────────────
echo html_writer::start_div('form-row');
echo html_writer::start_div('col-12');
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('search'), 'class' => 'btn btn-primary btn-sm mr-2']);
echo html_writer::tag('a', get_string('reset'), ['href' => $reseturl->out(false), 'class' => 'btn btn-secondary btn-sm']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_tag('form');
echo html_writer::end_div(); // card-body
echo html_writer::end_div(); // card

// ---------------------------------------------------------------------------
// JavaScript: drag-and-drop column reorder + program multi-select sync.
// ---------------------------------------------------------------------------
echo html_writer::script('
(function () {
    // ── Column drag-and-drop ──────────────────────────────────────────────
    var list   = document.getElementById("col-order-list");
    var hidden = document.getElementById("colorder-input");
    if (list && hidden) {
        var dragging = null;

        list.addEventListener("dragstart", function (e) {
            dragging = e.target.closest("[data-col]");
            if (dragging) {
                dragging.style.opacity = "0.4";
                e.dataTransfer.effectAllowed = "move";
            }
        });

        list.addEventListener("dragend", function () {
            if (dragging) dragging.style.opacity = "";
            dragging = null;
            syncOrder();
        });

        list.addEventListener("dragover", function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = "move";
            var target = e.target.closest("[data-col]");
            if (!target || target === dragging) return;
            var rect = target.getBoundingClientRect();
            var after = (e.clientX - rect.left) > rect.width / 2;
            list.insertBefore(dragging, after ? target.nextSibling : target);
        });

        list.addEventListener("drop", function (e) {
            e.preventDefault();
        });

        function syncOrder() {
            var cols = Array.from(list.querySelectorAll("[data-col]"))
                           .map(function (el) { return el.dataset.col; });
            hidden.value = cols.join(",");
        }
    }

    // ── Program multi-select → hidden input ──────────────────────────────
    var sel    = document.getElementById("filterprogramselector");
    var proghidden = document.getElementById("filterprogramids");
    var form   = document.getElementById("ledger-filter-form");
    if (sel && proghidden && form) {
        var saved = (proghidden.value || "").split(",").filter(Boolean);
        Array.from(sel.options).forEach(function (o) {
            if (saved.indexOf(o.value) !== -1) o.selected = true;
        });
        form.addEventListener("submit", function () {
            var ids = Array.from(sel.selectedOptions).map(function (o) { return o.value; });
            proghidden.value = ids.join(",");
        });
    }
})();
');

// ---------------------------------------------------------------------------
// Build SQL WHERE conditions.
// ---------------------------------------------------------------------------
$conditions = ['1 = 1'];
$params = [];

if ($filteruserid > 0) {
    $conditions[] = 'a.userid = :filteruserid';
    $params['filteruserid'] = $filteruserid;
}
if (!empty($filtercoursename)) {
    $conditions[] = $DB->sql_like('a.coursename_snapshot', ':filtercoursename', false);
    $params['filtercoursename'] = '%' . $DB->sql_like_escape($filtercoursename) . '%';
}
if (!empty($filtersource)) {
    $conditions[] = $DB->sql_like('a.source_component', ':filtersource', false);
    $params['filtersource'] = '%' . $DB->sql_like_escape($filtersource) . '%';
}
if ($filterpassed === '1') {
    $conditions[] = 'a.grade_passed = 1';
} elseif ($filterpassed === '0') {
    $conditions[] = 'a.grade_passed = 0';
} elseif ($filterpassed === 'null') {
    $conditions[] = 'a.grade_passed IS NULL';
}
if (!empty($programids)) {
    [$insql, $inparams] = $DB->get_in_or_equal($programids, SQL_PARAMS_NAMED, 'progid');
    $conditions[] = "EXISTS (SELECT 1 FROM {local_completionhistory_ach_program} ap WHERE ap.achievementid = a.id AND ap.programid $insql)";
    $params = array_merge($params, $inparams);
} elseif ($filterhasprograms) {
    $conditions[] = "EXISTS (SELECT 1 FROM {local_completionhistory_ach_program} ap WHERE ap.achievementid = a.id)";
}
if (!empty($filterdatefrom)) {
    $ts = strtotime($filterdatefrom);
    if ($ts !== false) {
        $conditions[] = 'a.completiontime >= :filterdatefrom';
        $params['filterdatefrom'] = $ts;
    }
}
if (!empty($filterdateto)) {
    $ts = strtotime($filterdateto . ' 23:59:59');
    if ($ts !== false) {
        $conditions[] = 'a.completiontime <= :filterdateto';
        $params['filterdateto'] = $ts;
    }
}

$where = implode(' AND ', $conditions);

// ---------------------------------------------------------------------------
// Build table base URL (carries all filter + column-order state).
// ---------------------------------------------------------------------------
$urlparams = array_filter([
    'filteruserid'      => $filteruserid ?: null,
    'filtercoursename'  => $filtercoursename ?: null,
    'filtersource'      => $filtersource ?: null,
    'filterpassed'      => $filterpassed ?: null,
    'filterhasprograms' => $filterhasprograms ?: null,
    'filterprogramids'  => $filterprogramids ?: null,
    'filterdatefrom'    => $filterdatefrom ?: null,
    'filterdateto'      => $filterdateto ?: null,
    'colorder'          => $colorderraw ?: null,
], fn($v) => $v !== null && $v !== '');
foreach (array_keys($optionalcoldefs) as $colname) {
    if (in_array($colname, $visibleoptionalcols)) {
        $urlparams['showcol_' . $colname] = 1;
    }
}
$tableurl = new moodle_url('/local/completionhistory/achievement_ledger.php', $urlparams);

// ---------------------------------------------------------------------------
// Render table.
// ---------------------------------------------------------------------------
$table = new achievements_table(
    'local_completionhistory_ledger',
    true,                    // showuser (staff view)
    $visibleoptionalcols,
    $colorder
);

$table->set_sql(
    'a.*,
     cc.timeenrolled                         AS enroldate,
     u.firstname                             AS user_firstname,
     u.lastname                              AS user_lastname,
     u.email                                 AS user_email',
    '{local_completionhistory_achievement} a
     LEFT JOIN {course_completions} cc ON cc.userid = a.userid AND cc.course = a.courseid
     LEFT JOIN {user} u                ON u.id = a.userid',
    $where,
    $params
);

$table->define_baseurl($tableurl);
$table->out(50, true);

echo $OUTPUT->footer();
