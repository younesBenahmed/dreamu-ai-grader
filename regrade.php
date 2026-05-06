<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$cmid = required_param('id', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
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

$student = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

$PAGE->set_url(new moodle_url('/local/dreamu_ai/regrade.php', ['id' => $cmid, 'userid' => $userid]));
$PAGE->set_title('Re-grade ' . fullname($student));
$PAGE->set_heading($course->fullname);

if ($confirm && confirm_sesskey()) {
    // Get the student's submission.
    $submission = $DB->get_record('assign_submission', [
        'assignment' => $cm->instance,
        'userid' => $userid,
        'status' => 'submitted',
        'latest' => 1,
    ]);

    if (!$submission) {
        redirect(
            new moodle_url('/local/dreamu_ai/validate.php', ['id' => $cmid]),
            'No submission found for this student.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Delete old pending grades for this user.
    $DB->delete_records_select(
        'local_dreamu_ai_grades',
        'assignid = :assignid AND userid = :userid AND validated = 0',
        ['assignid' => $cm->instance, 'userid' => $userid]
    );

    $prompt = $config->prompt ?: 'Grade the following student submission.';
    $maxgrade = floatval($assign->get_instance()->grade);
    if ($maxgrade <= 0) {
        $maxgrade = floatval($config->maxgrade);
    }
    $language = $config->language ?: 'fr';

    // Create pending record.
    $logrecord = new \stdClass();
    $logrecord->assignid = $cm->instance;
    $logrecord->userid = $userid;
    $logrecord->submissionid = $submission->id;
    $logrecord->status = 'pending';
    $logrecord->validated = 0;
    $logrecord->timecreated = time();
    $logid = $DB->insert_record('local_dreamu_ai_grades', $logrecord);

    try {
        $submissiontext = \local_dreamu_ai\ai_grader::get_submission_text($assign, $submission, $userid);

        if (empty(trim($submissiontext))) {
            throw new \moodle_exception('empty_submission', 'local_dreamu_ai', '', null,
                'Submission is empty or contains only binary files.');
        }

        $grader = new \local_dreamu_ai\ai_grader();
        $result = $grader->grade_submission($submissiontext, $prompt, $maxgrade, $language);

        $DB->update_record('local_dreamu_ai_grades', (object)[
            'id' => $logid,
            'grade' => $result->grade,
            'feedback' => $result->feedback,
            'rawresponse' => substr(json_encode($result), 0, 65535),
            'status' => 'graded',
            'validated' => 0,
        ]);

        redirect(
            new moodle_url('/local/dreamu_ai/validate.php', ['id' => $cmid]),
            'Re-grading complete for ' . fullname($student) . ': ' . $result->grade . '/' . $maxgrade,
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\Exception $e) {
        $DB->update_record('local_dreamu_ai_grades', (object)[
            'id' => $logid,
            'status' => 'error',
            'errormessage' => substr($e->getMessage(), 0, 65535),
        ]);

        redirect(
            new moodle_url('/local/dreamu_ai/validate.php', ['id' => $cmid]),
            'Re-grading failed: ' . $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

// Confirmation page.
echo $OUTPUT->header();
echo $OUTPUT->heading('Re-grade: ' . fullname($student));

echo html_writer::tag('p', 'This will re-run the AI grading for <strong>' . fullname($student) . '</strong>.');
echo html_writer::tag('p', 'Any previous unvalidated grade for this student will be replaced.');

$confirmurl = new moodle_url('/local/dreamu_ai/regrade.php', [
    'id' => $cmid,
    'userid' => $userid,
    'confirm' => 1,
    'sesskey' => sesskey(),
]);
$cancelurl = new moodle_url('/local/dreamu_ai/validate.php', ['id' => $cmid]);

echo $OUTPUT->confirm('Re-grade ' . fullname($student) . ' with AI?', $confirmurl, $cancelurl);
echo $OUTPUT->footer();
