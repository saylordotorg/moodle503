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
 * local_completionhistory upgrade steps.
 *
 * @package    local_completionhistory
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_completionhistory_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026041600) {

        // ── 1. Add exam_track, attempts_used, attempts_allowed to achievement ──

        $table = new xmldb_table('local_completionhistory_achievement');

        $field = new xmldb_field('exam_track', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'grade_source');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('attempts_used', XMLDB_TYPE_INTEGER, '2', null, null, null, null, 'exam_track');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('attempts_allowed', XMLDB_TYPE_INTEGER, '2', null, null, null, null, 'attempts_used');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add index on exam_track for filtering.
        $index = new xmldb_index('exam_track_ix', XMLDB_INDEX_NOTUNIQUE, ['exam_track']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // ── 2. Create local_completionhistory_exam_attempt ────────────────────

        $table = new xmldb_table('local_completionhistory_exam_attempt');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('exam_track', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
            $table->add_field('attempt_number', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, null);
            $table->add_field('attempts_allowed', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '3');
            $table->add_field('grade_decimal', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
            $table->add_field('grade_passed', XMLDB_TYPE_INTEGER, '1', null, null, null, null);
            $table->add_field('resulted_in_completion', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('achievementid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timetaken', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

            $table->add_index('userid_courseid_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            $table->add_index('userid_courseid_track_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid', 'exam_track']);
            $table->add_index('achievementid_ix', XMLDB_INDEX_NOTUNIQUE, ['achievementid']);
            $table->add_index('timetaken_ix', XMLDB_INDEX_NOTUNIQUE, ['timetaken']);

            $dbman->create_table($table);
        }

        // ── 3. Create local_completionhistory_course_exam_config ──────────────

        $table = new xmldb_table('local_completionhistory_course_exam_config');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('course_type', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'standard');
            $table->add_field('program_final_quizid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('dc_quizid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('cert_quizid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('program_attempts_allowed', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '3');
            $table->add_field('dc_attempts_allowed', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '3');
            $table->add_field('cert_attempts_allowed', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('notes', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

            $table->add_index('courseid_uq', XMLDB_INDEX_UNIQUE, ['courseid']);
            $table->add_index('course_type_ix', XMLDB_INDEX_NOTUNIQUE, ['course_type']);
            $table->add_index('program_final_quizid_ix', XMLDB_INDEX_NOTUNIQUE, ['program_final_quizid']);
            $table->add_index('dc_quizid_ix', XMLDB_INDEX_NOTUNIQUE, ['dc_quizid']);
            $table->add_index('cert_quizid_ix', XMLDB_INDEX_NOTUNIQUE, ['cert_quizid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026041600, 'local', 'completionhistory');
    }

    return true;
}
