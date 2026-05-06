<?php
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
    throw new moodle_exception('La correction IA n\'est pas activée pour ce devoir.');
}

$PAGE->set_url(new moodle_url('/local/dreamu_ai/grade.php', ['id' => $cmid]));
$PAGE->set_title(get_string('grade_submissions', 'local_dreamu_ai'));
$PAGE->set_heading($course->fullname);

if ($confirm && confirm_sesskey()) {
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

    // Trigger cron in background to start the task immediately.
    $cronphp = $CFG->dirroot . '/admin/cli/cron.php';
    @exec("php {$cronphp} > /dev/null 2>&1 &");

    // Redirect to progress page.
    redirect(
        new moodle_url('/local/dreamu_ai/progress.php', ['id' => $cmid]),
    );
}

// Show confirmation page.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('grade_submissions', 'local_dreamu_ai'));

$submissioncount = $DB->count_records('assign_submission', [
    'assignment' => $cm->instance,
    'status' => 'submitted',
    'latest' => 1,
]);

$maxgrade = floatval($assign->get_instance()->grade);
$langmap = ['fr' => 'Français', 'en' => 'Anglais'];

echo '<div class="card mb-3"><div class="card-body">';
echo '<h4>' . format_string($assign->get_instance()->name) . '</h4>';
echo '<table class="table table-sm" style="max-width:400px;">';
echo '<tr><td>Soumissions à corriger</td><td><strong>' . $submissioncount . '</strong></td></tr>';
echo '<tr><td>Note maximale</td><td><strong>' . $maxgrade . '</strong></td></tr>';
echo '<tr><td>Langue du feedback</td><td><strong>' . ($langmap[$config->language] ?? $config->language) . '</strong></td></tr>';
echo '<tr><td>Temps estimé</td><td><strong>~' . ($submissioncount * 2) . ' minutes</strong></td></tr>';
echo '</table>';

if (!empty($config->prompt)) {
    echo '<p><strong>Consignes de correction :</strong></p>';
    echo '<pre style="background:#f5f5f5; padding:10px; border-radius:5px; max-height:150px; overflow-y:auto;">' . s($config->prompt) . '</pre>';
}
echo '</div></div>';

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
