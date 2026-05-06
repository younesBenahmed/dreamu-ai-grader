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
    // Get selected user IDs from the form
    $selecteduserids = optional_param_array('userids', [], PARAM_INT);

    if (empty($selecteduserids)) {
        redirect(
            new moodle_url('/local/dreamu_ai/grade.php', ['id' => $cmid]),
            'Aucun étudiant sélectionné.',
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

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

    // Queue the adhoc task with selected user IDs.
    $task = new \local_dreamu_ai\task\grade_submissions();
    $task->set_custom_data((object)[
        'assignid' => $cm->instance,
        'teacherid' => $USER->id,
        'userids' => $selecteduserids,
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

// Get all submitted submissions with user info.
$submissions = $DB->get_records('assign_submission', [
    'assignment' => $cm->instance,
    'status' => 'submitted',
    'latest' => 1,
]);

$submissioncount = count($submissions);
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

// Build the form with student checkboxes
$confirmurl = new moodle_url('/local/dreamu_ai/grade.php');

echo '<form method="post" action="' . $confirmurl->out(false) . '">';
echo '<input type="hidden" name="id" value="' . $cmid . '">';
echo '<input type="hidden" name="confirm" value="1">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

// Student selection section
echo '<div class="card mb-3"><div class="card-body">';
echo '<h5>Sélection des étudiants</h5>';

// Select All / Deselect All toggle
echo '<div class="mb-2">';
echo '<button type="button" id="btn-select-all" class="btn btn-sm btn-outline-primary mr-2" onclick="toggleAllStudents(true)">Tout sélectionner</button>';
echo '<button type="button" id="btn-deselect-all" class="btn btn-sm btn-outline-secondary" onclick="toggleAllStudents(false)">Tout désélectionner</button>';
echo '<span id="selected-count" class="ml-3 text-muted"></span>';
echo '</div>';

echo '<div style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 4px; padding: 8px;">';

if (!empty($submissions)) {
    // Get user details for all submitted users
    $userids = array_map(function($s) { return $s->userid; }, $submissions);
    $userids = array_filter($userids, function($uid) { return $uid > 0; });

    if (!empty($userids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($userids);
        $users = $DB->get_records_select('user', "id {$insql}", $inparams, 'lastname, firstname');

        foreach ($submissions as $sub) {
            if ($sub->userid <= 0) continue;
            $user = $users[$sub->userid] ?? null;
            if (!$user) continue;

            $fullname = fullname($user);
            $timesubmitted = userdate($sub->timemodified, '%d/%m/%Y %H:%M');

            // Check if already graded (pending validation)
            $existing = $DB->get_record('local_dreamu_ai_grades', [
                'assignid' => $cm->instance,
                'userid' => $sub->userid,
                'status' => 'graded',
                'validated' => 0,
            ]);
            $badge = '';
            if ($existing) {
                $badge = ' <span class="badge badge-warning">Déjà noté (' . round($existing->grade, 1) . '/' . $maxgrade . ')</span>';
            }

            echo '<div class="form-check">';
            echo '<input class="form-check-input student-checkbox" type="checkbox" name="userids[]" value="' . $sub->userid . '" id="user-' . $sub->userid . '" checked>';
            echo '<label class="form-check-label" for="user-' . $sub->userid . '">';
            echo htmlspecialchars($fullname) . ' <small class="text-muted">(' . $timesubmitted . ')</small>' . $badge;
            echo '</label>';
            echo '</div>';
        }
    }
} else {
    echo '<p class="text-muted mb-0">Aucune soumission trouvée.</p>';
}

echo '</div>'; // end scrollable div
echo '</div></div>'; // end card

// Confirm / Cancel buttons
if ($submissioncount > 0) {
    echo '<div class="mt-3">';
    echo '<button type="submit" class="btn btn-primary mr-2">Lancer la correction IA</button>';
    echo '<a href="' . (new moodle_url('/mod/assign/view.php', ['id' => $cmid]))->out(false) . '" class="btn btn-secondary">Annuler</a>';
    echo '</div>';
}

echo '</form>';

// JavaScript for Select All / Deselect All
echo '<script>
function toggleAllStudents(checked) {
    var checkboxes = document.querySelectorAll(".student-checkbox");
    checkboxes.forEach(function(cb) { cb.checked = checked; });
    updateSelectedCount();
}
function updateSelectedCount() {
    var total = document.querySelectorAll(".student-checkbox").length;
    var selected = document.querySelectorAll(".student-checkbox:checked").length;
    var el = document.getElementById("selected-count");
    if (el) { el.textContent = selected + " / " + total + " sélectionné(s)"; }
}
// Init count and listen for changes
document.addEventListener("DOMContentLoaded", function() {
    updateSelectedCount();
    document.querySelectorAll(".student-checkbox").forEach(function(cb) {
        cb.addEventListener("change", updateSelectedCount);
    });
});
</script>';

echo $OUTPUT->footer();
