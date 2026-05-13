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
use moodle_url;
use html_writer;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/tablelib.php');

/**
 * Table for displaying achievement records.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class achievements_table extends table_sql {

    /** @var bool Whether to show the user columns (staff view). */
    protected bool $showuser;

    /** @var array Optional extra column names to include. */
    protected array $optionalcols;

    /** @var array Cached program associations keyed by achievementid. */
    protected array $programcache = [];

    /**
     * Columns that cannot be sorted (computed or non-SQL fields).
     */
    private const NOSORT_COLS = ['programs', 'enroldays', 'artifacturl'];

    /**
     * Default ordered column definitions for the staff (showuser) view.
     * Each entry: [column_name, lang_string_key].
     */
    private const STAFF_COLS = [
        ['user_firstname',          'col_firstname'],
        ['user_lastname',           'col_lastname'],
        ['user_email',              'col_email'],
        ['useridnumber_snapshot',   'col_useridnumber'],
        ['coursename_snapshot',     'col_coursename'],
        ['courseshortname_snapshot','col_courseshortname'],
        ['enroldate',               'col_enroldate'],
        ['enroldays',               'col_enroldays'],
        ['completiontime',          'col_completiondate'],
        ['grade_decimal',           'col_grade'],
        ['grade_passed',            'col_passed'],
        ['programs',                'col_programs'],
        ['source_component',        'col_source'],
        ['timecreated',             'col_captured'],
    ];

    /**
     * Default ordered column definitions for the student (self) view.
     */
    private const STUDENT_COLS = [
        ['coursename_snapshot',     'col_coursename'],
        ['courseshortname_snapshot','col_courseshortname'],
        ['enroldate',               'col_enroldate'],
        ['enroldays',               'col_enroldays'],
        ['completiontime',          'col_completiondate'],
        ['grade_decimal',           'col_grade'],
        ['grade_passed',            'col_passed'],
        ['programs',                'col_programs'],
        ['source_component',        'col_source'],
        ['timecreated',             'col_captured'],
    ];

    /**
     * Optional columns available via the "extra columns" picker.
     * (useridnumber_snapshot and courseshortname_snapshot are now default staff columns.)
     */
    private const OPTIONAL_COLS = [
        'courseidnumber_snapshot' => 'col_courseidnumber',
        'source_event'            => 'col_source_event',
        'artifacturl'             => 'col_artifact',
    ];

    /**
     * Constructor.
     *
     * @param string $uniqueid
     * @param bool   $showuser     Whether to show user identity columns (staff view).
     * @param array  $optionalcols Optional column names to append.
     * @param array  $colorder     Explicit column name order (subset or full). Empty = default.
     */
    public function __construct(
        string $uniqueid,
        bool $showuser = false,
        array $optionalcols = [],
        array $colorder = []
    ) {
        parent::__construct($uniqueid);
        $this->showuser     = $showuser;
        $this->optionalcols = $optionalcols;

        // Build the full default map: colname → header string.
        $basedefs = $showuser ? self::STAFF_COLS : self::STUDENT_COLS;
        $defaultmap = [];
        foreach ($basedefs as [$col, $key]) {
            $defaultmap[$col] = get_string($key, 'local_completionhistory');
        }

        // Append optional extras.
        foreach ($optionalcols as $col) {
            if (isset(self::OPTIONAL_COLS[$col])) {
                $defaultmap[$col] = get_string(self::OPTIONAL_COLS[$col], 'local_completionhistory');
            }
        }

        // Apply custom column order if supplied.
        // Only keep columns that exist in $defaultmap; anything missing falls to the end.
        if (!empty($colorder)) {
            $ordered = [];
            foreach ($colorder as $col) {
                if (isset($defaultmap[$col])) {
                    $ordered[$col] = $defaultmap[$col];
                    unset($defaultmap[$col]);
                }
            }
            // Append any remaining (new) columns not yet in the saved order.
            foreach ($defaultmap as $col => $hdr) {
                $ordered[$col] = $hdr;
            }
            $defaultmap = $ordered;
        }

        $this->define_columns(array_keys($defaultmap));
        $this->define_headers(array_values($defaultmap));

        foreach (self::NOSORT_COLS as $col) {
            if (in_array($col, array_keys($defaultmap))) {
                $this->no_sorting($col);
            }
        }

        $this->sortable(true, 'completiontime', SORT_DESC);
    }

    /**
     * Return the canonical staff column list (col => label) for the column-order UI.
     */
    public static function staff_col_labels(): array {
        $map = [];
        foreach (self::STAFF_COLS as [$col, $key]) {
            $map[$col] = get_string($key, 'local_completionhistory');
        }
        return $map;
    }

    // -----------------------------------------------------------------------
    // User identity column formatters (joined from mdl_user).
    // -----------------------------------------------------------------------

    public function col_user_firstname($row): string {
        if ((int) $row->userid === 0) {
            return html_writer::tag('em', 'anonymized', ['class' => 'text-muted']);
        }
        return s($row->user_firstname ?? '');
    }

    public function col_user_lastname($row): string {
        if ((int) $row->userid === 0) {
            return '';
        }
        return s($row->user_lastname ?? '');
    }

    public function col_user_email($row): string {
        if ((int) $row->userid === 0) {
            return '';
        }
        $email = $row->user_email ?? '';
        if (empty($email)) {
            return '-';
        }
        return html_writer::link('mailto:' . s($email), s($email));
    }

    // -----------------------------------------------------------------------
    // Core column formatters.
    // -----------------------------------------------------------------------

    public function col_useridnumber_snapshot($row): string {
        return s($row->useridnumber_snapshot ?? '');
    }

    public function col_coursename_snapshot($row): string {
        return format_string($row->coursename_snapshot);
    }

    public function col_courseshortname_snapshot($row): string {
        return s($row->courseshortname_snapshot ?? '');
    }

    public function col_enroldate($row): string {
        if (empty($row->enroldate)) {
            return '-';
        }
        return userdate((int) $row->enroldate, '%m/%d/%Y');
    }

    public function col_enroldays($row): string {
        if (empty($row->enroldate)) {
            return '-';
        }
        $days = (int) floor((time() - (int) $row->enroldate) / 86400);
        if ($days < 0) {
            return '-';
        }
        if ($days === 0) {
            return html_writer::tag('span', 'Today', [
                'class' => 'badge badge-primary',
                'style' => 'font-size:0.82em',
            ]);
        }
        $label = $days === 1 ? '1 day ago' : number_format($days) . ' days ago';
        return html_writer::tag('span', $label, ['class' => 'text-muted small']);
    }

    public function col_completiontime($row): string {
        return userdate($row->completiontime, '%m/%d/%Y');
    }

    public function col_grade_decimal($row): string {
        if ($row->grade_decimal === null) {
            return '-';
        }
        return format_float($row->grade_decimal, 2);
    }

    public function col_grade_passed($row): string {
        if ($row->grade_passed === null) {
            return html_writer::tag('span', '&#8212; ' . get_string('gradeunknown', 'local_completionhistory'),
                ['class' => 'badge badge-secondary', 'style' => 'font-size:0.85em']);
        }
        if ($row->grade_passed) {
            return html_writer::tag('span', '&#10003; ' . get_string('gradepassed', 'local_completionhistory'),
                ['class' => 'badge badge-success', 'style' => 'font-size:0.85em']);
        }
        return html_writer::tag('span', '&#10007; ' . get_string('gradefailed', 'local_completionhistory'),
            ['class' => 'badge badge-danger', 'style' => 'font-size:0.85em']);
    }

    public function col_programs($row): string {
        global $DB;
        if (!isset($this->programcache[$row->id])) {
            $this->programcache[$row->id] = $DB->get_records(
                'local_completionhistory_ach_program', ['achievementid' => $row->id]
            );
        }
        $programs = $this->programcache[$row->id];
        if (empty($programs)) {
            return '-';
        }
        $names = [];
        foreach ($programs as $p) {
            $names[] = html_writer::tag('span', format_string($p->programname_snapshot), ['class' => 'badge badge-info']);
        }
        return implode(' ', $names);
    }

    public function col_source_component($row): string {
        return s($row->source_component);
    }

    public function col_timecreated($row): string {
        return userdate($row->timecreated, '%m/%d/%Y');
    }

    // -----------------------------------------------------------------------
    // Optional column formatters.
    // -----------------------------------------------------------------------

    public function col_courseidnumber_snapshot($row): string {
        return s($row->courseidnumber_snapshot ?? '');
    }

    public function col_source_event($row): string {
        return s($row->source_event ?? '');
    }

    public function col_artifacturl($row): string {
        if (empty($row->artifacturl)) {
            return '-';
        }
        return html_writer::link($row->artifacturl, get_string('col_artifact', 'local_completionhistory'), [
            'target' => '_blank',
            'rel'    => 'noopener noreferrer',
        ]);
    }
}
