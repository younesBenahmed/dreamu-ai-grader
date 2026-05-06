<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$cmid = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('local/dreamu_ai:grade', $context);

$assign = new assign($context, $cm, $course);
$assignname = format_string($assign->get_instance()->name);

// AJAX endpoint: return progress as JSON.
$ajax = optional_param('ajax', 0, PARAM_INT);
if ($ajax) {
    header('Content-Type: application/json');

    $totalsubmissions = $DB->count_records('assign_submission', [
        'assignment' => $cm->instance,
        'status' => 'submitted',
        'latest' => 1,
    ]);

    $graded = $DB->count_records_select('local_dreamu_ai_grades',
        'assignid = :assignid AND status IN (:s1, :s2) AND validated = 0',
        ['assignid' => $cm->instance, 's1' => 'graded', 's2' => 'error']
    );

    $pending = $DB->count_records_select('local_dreamu_ai_grades',
        'assignid = :assignid AND status = :status AND validated = 0',
        ['assignid' => $cm->instance, 'status' => 'pending']
    );

    $errors = $DB->count_records_select('local_dreamu_ai_grades',
        'assignid = :assignid AND status = :status AND validated = 0',
        ['assignid' => $cm->instance, 'status' => 'error']
    );

    $done = ($pending == 0 && $graded > 0);

    // Get last graded student name.
    $lastgraded = $DB->get_record_sql(
        'SELECT g.*, u.firstname, u.lastname FROM {local_dreamu_ai_grades} g
         JOIN {user} u ON u.id = g.userid
         WHERE g.assignid = :assignid AND g.status IN (:s1, :s2) AND g.validated = 0
         ORDER BY g.timecreated DESC LIMIT 1',
        ['assignid' => $cm->instance, 's1' => 'graded', 's2' => 'error']
    );
    $lastname = $lastgraded ? ($lastgraded->firstname . ' ' . $lastgraded->lastname) : '';
    $lastgrade = $lastgraded && $lastgraded->grade ? number_format($lastgraded->grade, 1) : '';

    echo json_encode([
        'total' => $totalsubmissions,
        'graded' => $graded,
        'pending' => $pending,
        'errors' => $errors,
        'done' => $done,
        'last_student' => $lastname,
        'last_grade' => $lastgrade,
    ]);
    exit;
}

$PAGE->set_url(new moodle_url('/local/dreamu_ai/progress.php', ['id' => $cmid]));
$PAGE->set_title('Correction IA en cours - ' . $assignname);
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

$totalsubmissions = $DB->count_records('assign_submission', [
    'assignment' => $cm->instance,
    'status' => 'submitted',
    'latest' => 1,
]);

$validateurl = new moodle_url('/local/dreamu_ai/validate.php', ['id' => $cmid]);
$ajaxurl = new moodle_url('/local/dreamu_ai/progress.php', ['id' => $cmid, 'ajax' => 1]);

echo '
<div style="max-width:700px; margin:30px auto;">
    <div class="card">
        <div class="card-body text-center">
            <h3 id="title">Correction IA en cours...</h3>
            <p class="text-muted">' . $assignname . ' &mdash; ' . $totalsubmissions . ' soumissions</p>

            <div style="background:#e9ecef; border-radius:12px; height:40px; overflow:hidden; margin:25px 0;">
                <div id="bar" style="background:linear-gradient(90deg, #198754, #20c997); height:100%; width:0%; border-radius:12px; transition:width 0.8s ease;"></div>
            </div>

            <div style="font-size:28px; font-weight:bold; color:#198754; margin:10px 0;" id="counter">0 / ' . $totalsubmissions . '</div>
            <div style="font-size:14px; color:#666; margin-bottom:5px;" id="status">Démarrage de la correction...</div>
            <div style="font-size:13px; color:#999;" id="eta">Temps estimé : ~' . ($totalsubmissions * 2) . ' minutes</div>
            <div style="font-size:13px; color:#aaa; margin-top:5px;" id="last-student"></div>

            <div id="spinner" style="margin:20px 0;">
                <div class="spinner-border text-success" role="status" style="width:2rem; height:2rem;">
                    <span class="sr-only">Chargement...</span>
                </div>
            </div>

            <div id="done-section" style="display:none; margin-top:20px;">
                <div class="alert alert-success" style="font-size:16px;">
                    <strong>Correction terminée !</strong>
                    <span id="done-summary"></span>
                </div>
                <a href="' . $validateurl . '" class="btn btn-success btn-lg">Valider les notes</a>
            </div>

            <p style="font-size:12px; color:#bbb; margin-top:20px;">
                Vous pouvez quitter cette page. Une notification vous sera envoyée quand la correction sera terminée.
            </p>
        </div>
    </div>
</div>

<script>
var ajaxUrl = "' . $ajaxurl->out(false) . '";
var total = ' . $totalsubmissions . ';
var startTime = Date.now();
var pollInterval = 5000; // 5 seconds

function updateProgress() {
    fetch(ajaxUrl)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var graded = data.graded;
            var pct = total > 0 ? Math.round((graded / total) * 100) : 0;

            document.getElementById("bar").style.width = pct + "%";
            document.getElementById("counter").textContent = graded + " / " + total;

            // ETA calculation.
            var elapsed = (Date.now() - startTime) / 1000;
            if (graded > 0 && !data.done) {
                var secsPerItem = elapsed / graded;
                var remaining = Math.round(secsPerItem * (total - graded));
                var mins = Math.floor(remaining / 60);
                var secs = remaining % 60;
                document.getElementById("eta").textContent = "Temps restant : " + (mins > 0 ? mins + " min " : "") + secs + " s";
            }

            // Status text.
            if (data.pending > 0) {
                document.getElementById("status").textContent = "Correction en cours... (" + data.pending + " en attente)";
            } else if (graded > 0) {
                document.getElementById("status").textContent = "Finalisation...";
            }

            // Last student.
            if (data.last_student) {
                var txt = "Dernier corrigé : " + data.last_student;
                if (data.last_grade) txt += " (" + data.last_grade + "/" + total + ")";
                document.getElementById("last-student").textContent = txt;
            }

            if (data.errors > 0) {
                document.getElementById("status").textContent += " (" + data.errors + " erreur" + (data.errors > 1 ? "s" : "") + ")";
            }

            if (data.done) {
                // Done!
                document.getElementById("bar").style.width = "100%";
                document.getElementById("bar").style.background = "linear-gradient(90deg, #198754, #0dcaf0)";
                document.getElementById("counter").textContent = graded + " / " + total;
                document.getElementById("title").textContent = "Correction terminée !";
                document.getElementById("status").textContent = "";
                document.getElementById("eta").textContent = "";
                document.getElementById("spinner").style.display = "none";

                var summary = " " + graded + " soumissions corrigées.";
                if (data.errors > 0) summary += " " + data.errors + " erreur(s).";
                document.getElementById("done-summary").textContent = summary;
                document.getElementById("done-section").style.display = "block";

                return; // Stop polling.
            }

            setTimeout(updateProgress, pollInterval);
        })
        .catch(function() {
            // Network error, retry.
            setTimeout(updateProgress, pollInterval * 2);
        });
}

// Start polling after 2 seconds.
setTimeout(updateProgress, 2000);
</script>
';

echo $OUTPUT->footer();
