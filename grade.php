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
 * Page to trigger AI grading for all submissions.
 *
 * @package    local_dreamu_ai
 * @copyright  2026 Dream-U / AMU / IUT Aix-en-Provence
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$cmid = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

$cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('local/dreamu_ai:grade', $context);

$assign = new assign($context, $cm, $course);
$config = $DB->get_record('local_dreamu_ai_config', ['assignid' => $cm->instance]);

if (!$config || !$config->enabled) {
    throw new moodle_exception('AI grading is not enabled for this assignment.');
}

$PAGE->set_url(new moodle_url('/local/dreamu_ai/grade.php', ['id' => $cmid]));
$PAGE->set_title(get_string('grade_submissions', 'local_dreamu_ai'));
$PAGE->set_heading($course->fullname);

if ($confirm && confirm_sesskey()) {
    // Count submissions.
    $submissions = $DB->get_records('assign_submission', [
        'assignment' => $cm->instance,
        'status' => 'submitted',
        'latest' => 1,
    ]);

    if (empty($submissions)) {
        redirect(
            new moodle_url('/mod/assign/view.php', ['id' => $cmid]),
            get_string('no_submissions', 'local_dreamu_ai'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    // Queue the adhoc task.
    $task = new \local_dreamu_ai\task\grade_submissions();
    $task->set_custom_data((object)[
        'assignid' => $cm->instance,
        'teacherid' => $USER->id,
    ]);
    $task->set_userid($USER->id);
    \core\task\manager::queue_adhoc_task($task);

    redirect(
        new moodle_url('/mod/assign/view.php', ['id' => $cmid]),
        get_string('grading_started', 'local_dreamu_ai'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Show confirmation page.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('grade_submissions', 'local_dreamu_ai'));

// Show info about what will be graded.
$submissioncount = $DB->count_records('assign_submission', [
    'assignment' => $cm->instance,
    'status' => 'submitted',
    'latest' => 1,
]);

echo html_writer::tag('p', "Assignment: <strong>{$assign->get_instance()->name}</strong>");
echo html_writer::tag('p', "Submissions to grade: <strong>{$submissioncount}</strong>");
echo html_writer::tag('p', "Max grade: <strong>{$config->maxgrade}</strong>");
echo html_writer::tag('p', "Feedback language: <strong>{$config->language}</strong>");

if (!empty($config->prompt)) {
    echo html_writer::tag('p', "Grading instructions:");
    echo html_writer::tag('pre', s($config->prompt), ['style' => 'background:#f5f5f5; padding:10px; border-radius:5px;']);
}

$confirmurl = new moodle_url('/local/dreamu_ai/grade.php', [
    'id' => $cmid,
    'confirm' => 1,
    'sesskey' => sesskey(),
]);
$cancelurl = new moodle_url('/mod/assign/view.php', ['id' => $cmid]);

echo $OUTPUT->confirm(
    get_string('confirm_grade_all', 'local_dreamu_ai'),
    $confirmurl,
    $cancelurl
);

echo $OUTPUT->footer();
