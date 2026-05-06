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
 * Teacher validation page for AI-generated grades.
 * Teachers can review, modify, approve or reject each AI grade before it is applied.
 *
 * @package    local_dreamu_ai
 * @copyright  2026 Dream-U / AMU / IUT Aix-en-Provence
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$cmid = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('local/dreamu_ai:grade', $context);

$assign = new assign($context, $cm, $course);
$maxgrade = floatval($assign->get_instance()->grade);

$PAGE->set_url(new moodle_url('/local/dreamu_ai/validate.php', ['id' => $cmid]));
$PAGE->set_title(get_string('validate_grades', 'local_dreamu_ai'));
$PAGE->set_heading($course->fullname);

// Handle form submissions.
if ($action === 'approve' && confirm_sesskey()) {
    $gradeid = required_param('gradeid', PARAM_INT);
    $newgrade = required_param('grade', PARAM_FLOAT);
    $newfeedback = required_param('feedback', PARAM_RAW);

    $record = $DB->get_record('local_dreamu_ai_grades', ['id' => $gradeid], '*', MUST_EXIST);

    // Clamp grade.
    $newgrade = max(0, min($maxgrade, $newgrade));

    // Apply to Moodle gradebook.
    $submission = $DB->get_record('assign_submission', ['id' => $record->submissionid], '*', MUST_EXIST);

    $gradedata = new \stdClass();
    $gradedata->attemptnumber = $submission->attemptnumber;
    $gradedata->grade = $newgrade;
    $gradedata->assignfeedbackcomments_editor = [
        'text' => $newfeedback,
        'format' => FORMAT_HTML,
    ];

    $assign->save_grade($record->userid, $gradedata);

    // Update record.
    $DB->update_record('local_dreamu_ai_grades', (object)[
        'id' => $gradeid,
        'grade' => $newgrade,
        'feedback' => $newfeedback,
        'status' => 'validated',
        'validated' => 1,
    ]);

    redirect(
        new moodle_url('/local/dreamu_ai/validate.php', ['id' => $cmid]),
        get_string('grade_approved', 'local_dreamu_ai'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'reject' && confirm_sesskey()) {
    $gradeid = required_param('gradeid', PARAM_INT);

    $DB->update_record('local_dreamu_ai_grades', (object)[
        'id' => $gradeid,
        'status' => 'rejected',
        'validated' => 2,
    ]);

    redirect(
        new moodle_url('/local/dreamu_ai/validate.php', ['id' => $cmid]),
        get_string('grade_rejected', 'local_dreamu_ai'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

if ($action === 'approveall' && confirm_sesskey()) {
    $records = $DB->get_records('local_dreamu_ai_grades', [
        'assignid' => $cm->instance,
        'status' => 'graded',
        'validated' => 0,
    ]);

    $approved = 0;
    foreach ($records as $record) {
        $submission = $DB->get_record('assign_submission', ['id' => $record->submissionid]);
        if (!$submission) {
            continue;
        }

        $gradedata = new \stdClass();
        $gradedata->attemptnumber = $submission->attemptnumber;
        $gradedata->grade = $record->grade;
        $gradedata->assignfeedbackcomments_editor = [
            'text' => $record->feedback,
            'format' => FORMAT_HTML,
        ];

        $assign->save_grade($record->userid, $gradedata);

        $DB->update_record('local_dreamu_ai_grades', (object)[
            'id' => $record->id,
            'status' => 'validated',
            'validated' => 1,
        ]);
        $approved++;
    }

    redirect(
        new moodle_url('/local/dreamu_ai/validate.php', ['id' => $cmid]),
        get_string('all_grades_approved', 'local_dreamu_ai', $approved),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Display validation page.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('validate_grades', 'local_dreamu_ai'));

// Action bar: Export CSV, Stats.
echo html_writer::start_div('mb-3');
$csvurl = new moodle_url('/local/dreamu_ai/export_csv.php', ['id' => $cmid]);
echo html_writer::link($csvurl, 'Exporter CSV', ['class' => 'btn btn-outline-primary mr-2']);
$statsurl = new moodle_url('/local/dreamu_ai/stats.php', ['id' => $cmid]);
echo html_writer::link($statsurl, 'Statistiques', ['class' => 'btn btn-outline-info mr-2']);
echo html_writer::end_div();

// Get pending AI grades for this assignment.
$records = $DB->get_records('local_dreamu_ai_grades', [
    'assignid' => $cm->instance,
], 'timecreated DESC');

// Separate pending and processed.
$pending = [];
$processed = [];
foreach ($records as $record) {
    if ($record->status === 'graded' && $record->validated == 0) {
        $pending[] = $record;
    } else {
        $processed[] = $record;
    }
}

// Show pending grades for validation.
if (!empty($pending)) {
    echo html_writer::tag('h3', get_string('pending_validation', 'local_dreamu_ai') .
        ' (' . count($pending) . ')');

    // Approve all button.
    $approveallurl = new moodle_url('/local/dreamu_ai/validate.php', [
        'id' => $cmid,
        'action' => 'approveall',
        'sesskey' => sesskey(),
    ]);
    echo html_writer::tag('p',
        html_writer::link($approveallurl,
            get_string('approve_all', 'local_dreamu_ai'),
            ['class' => 'btn btn-success mb-3', 'onclick' => "return confirm('" .
                get_string('confirm_approve_all', 'local_dreamu_ai') . "');"]
        )
    );

    foreach ($pending as $record) {
        $user = $DB->get_record('user', ['id' => $record->userid]);
        $username = $user ? fullname($user) : "User #{$record->userid}";

        echo html_writer::start_div('card mb-3');
        echo html_writer::start_div('card-header d-flex justify-content-between align-items-center');
        echo html_writer::tag('strong', $username);
        echo html_writer::tag('span',
            get_string('ai_suggested_grade', 'local_dreamu_ai') . ': ' .
            html_writer::tag('strong', number_format($record->grade, 2) . ' / ' . $maxgrade),
            ['class' => 'badge badge-info bg-info text-white p-2']
        );
        echo html_writer::end_div();

        echo html_writer::start_div('card-body');

        // Show feedback preview.
        echo html_writer::tag('p', html_writer::tag('strong',
            get_string('ai_feedback', 'local_dreamu_ai') . ':'));
        echo html_writer::div($record->feedback, 'alert alert-secondary',
            ['style' => 'max-height:200px; overflow-y:auto; white-space:pre-wrap;']);

        // Approve form with editable grade and feedback.
        $approveurl = new moodle_url('/local/dreamu_ai/validate.php', [
            'id' => $cmid,
            'action' => 'approve',
            'sesskey' => sesskey(),
            'gradeid' => $record->id,
        ]);

        echo html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $approveurl->out(false),
            'class' => 'mt-2',
        ]);

        echo html_writer::start_div('row');

        // Grade input.
        echo html_writer::start_div('col-md-3');
        echo html_writer::tag('label', 'Note (/ ' . $maxgrade . ')',
            ['for' => 'grade_' . $record->id, 'class' => 'font-weight-bold']);
        echo html_writer::empty_tag('input', [
            'type' => 'number',
            'name' => 'grade',
            'id' => 'grade_' . $record->id,
            'value' => number_format($record->grade, 2, '.', ''),
            'min' => 0,
            'max' => $maxgrade,
            'step' => '0.5',
            'class' => 'form-control',
        ]);
        echo html_writer::end_div();

        echo html_writer::end_div(); // row

        // Feedback textarea.
        echo html_writer::tag('label', 'Feedback',
            ['for' => 'feedback_' . $record->id, 'class' => 'font-weight-bold mt-2']);
        echo html_writer::tag('textarea', s($record->feedback), [
            'name' => 'feedback',
            'id' => 'feedback_' . $record->id,
            'rows' => 4,
            'class' => 'form-control',
        ]);

        // Action buttons.
        echo html_writer::start_div('mt-3');
        echo html_writer::tag('button', get_string('approve_grade', 'local_dreamu_ai'), [
            'type' => 'submit',
            'class' => 'btn btn-success mr-2',
        ]);

        $rejecturl = new moodle_url('/local/dreamu_ai/validate.php', [
            'id' => $cmid,
            'action' => 'reject',
            'sesskey' => sesskey(),
            'gradeid' => $record->id,
        ]);
        echo html_writer::link($rejecturl, get_string('reject_grade', 'local_dreamu_ai'), [
            'class' => 'btn btn-danger',
            'onclick' => "return confirm('" . get_string('confirm_reject', 'local_dreamu_ai') . "');",
        ]);

        // Re-grade button.
        $regradeurl = new moodle_url('/local/dreamu_ai/regrade.php', [
            'id' => $cmid,
            'userid' => $record->userid,
        ]);
        echo ' ';
        echo html_writer::link($regradeurl, 'Re-corriger', [
            'class' => 'btn btn-warning',
        ]);

        echo html_writer::end_div();
        echo html_writer::end_tag('form');

        echo html_writer::end_div(); // card-body
        echo html_writer::end_div(); // card
    }
}

// Show processed grades.
if (!empty($processed)) {
    echo html_writer::tag('h3', get_string('processed_grades', 'local_dreamu_ai') .
        ' (' . count($processed) . ')', ['class' => 'mt-4']);

    $table = new html_table();
    $table->head = [
        'Student',
        'Note',
        'Status',
        'Feedback',
        'Date',
    ];
    $table->attributes['class'] = 'generaltable';

    foreach ($processed as $record) {
        $user = $DB->get_record('user', ['id' => $record->userid]);
        $username = $user ? fullname($user) : "User #{$record->userid}";

        $statusmap = [
            'validated' => ['text' => get_string('status_validated', 'local_dreamu_ai'), 'class' => 'badge-success'],
            'rejected' => ['text' => get_string('status_rejected', 'local_dreamu_ai'), 'class' => 'badge-danger'],
            'error' => ['text' => get_string('status_error', 'local_dreamu_ai'), 'class' => 'badge-warning'],
            'pending' => ['text' => get_string('status_pending', 'local_dreamu_ai'), 'class' => 'badge-secondary'],
        ];
        $st = $statusmap[$record->status] ?? ['text' => $record->status, 'class' => 'badge-secondary'];
        $statusbadge = html_writer::tag('span', $st['text'], ['class' => 'badge ' . $st['class']]);

        $feedback = $record->status === 'error'
            ? shorten_text($record->errormessage, 100)
            : shorten_text(strip_tags($record->feedback), 100);

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

if (empty($pending) && empty($processed)) {
    echo $OUTPUT->notification(get_string('no_ai_grades', 'local_dreamu_ai'), 'info');
}

// Back button.
$backurl = new moodle_url('/mod/assign/view.php', ['id' => $cmid]);
echo $OUTPUT->single_button($backurl, get_string('back'), 'get');

echo $OUTPUT->footer();
