<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AI Grading history page — shows all AI grading results for an assignment.
 *
 * @package    local_dreamu_ai
 * @copyright  2026 Dream-U / AMU / IUT Aix-en-Provence
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('local/dreamu_ai:grade', $context);

$PAGE->set_url(new moodle_url('/local/dreamu_ai/history.php', ['id' => $cmid]));
$PAGE->set_title(get_string('ai_grade_history', 'local_dreamu_ai'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('ai_grade_history', 'local_dreamu_ai'));

// Get all AI grading records for this assignment.
$records = $DB->get_records('local_dreamu_ai_grades', ['assignid' => $cm->instance], 'timecreated DESC');

if (empty($records)) {
    echo $OUTPUT->notification('No AI grading history found for this assignment.', 'info');
} else {
    $table = new html_table();
    $table->head = ['Student', 'Grade', 'Status', 'Feedback', 'Date'];
    $table->attributes['class'] = 'generaltable';

    foreach ($records as $record) {
        $user = $DB->get_record('user', ['id' => $record->userid]);
        $username = $user ? fullname($user) : "User #{$record->userid}";

        $statusmap = [
            'validated' => 'badge-success',
            'graded' => 'badge-info',
            'success' => 'badge-success',
            'rejected' => 'badge-warning',
            'error' => 'badge-danger',
            'pending' => 'badge-secondary',
        ];
        $statusclass = $statusmap[$record->status] ?? 'badge-secondary';
        $statusbadge = html_writer::tag('span', $record->status, [
            'class' => "badge {$statusclass}",
        ]);

        $feedback = in_array($record->status, ['graded', 'validated', 'success'])
            ? shorten_text(strip_tags($record->feedback), 100)
            : shorten_text($record->errormessage, 100);

        $table->data[] = [
            $username,
            $record->grade !== null ? number_format($record->grade, 2) : '-',
            $statusbadge,
            $feedback,
            userdate($record->timecreated),
        ];
    }

    echo html_writer::table($table);
}

$backurl = new moodle_url('/mod/assign/view.php', ['id' => $cmid]);
echo $OUTPUT->single_button($backurl, get_string('back'), 'get');

echo $OUTPUT->footer();
