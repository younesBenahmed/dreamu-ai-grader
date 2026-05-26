<?php
require_once(__DIR__ . '/../../config.php');

$cmid = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('local/dreamu_ai:grade', $context);

$PAGE->set_url(new moodle_url('/local/dreamu_ai/history.php', ['id' => $cmid]));
$PAGE->set_title('Historique des corrections IA');
$PAGE->set_heading($course->fullname);

$maxgrade = floatval($DB->get_field('local_dreamu_ai_config', 'maxgrade', ['assignid' => $cm->instance]) ?: 20);

echo $OUTPUT->header();
echo $OUTPUT->heading('Historique des corrections IA');

// Navigation buttons.
$gradeurl = new moodle_url('/local/dreamu_ai/grade.php', ['id' => $cmid]);
$validateurl = new moodle_url('/local/dreamu_ai/validate.php', ['id' => $cmid]);
$statsurl = new moodle_url('/local/dreamu_ai/stats.php', ['id' => $cmid]);
echo '<div class="mb-3">';
echo html_writer::link($gradeurl, 'Corriger avec l\'IA', ['class' => 'btn btn-primary mr-2 mb-1']);
echo html_writer::link($validateurl, 'Valider les notes', ['class' => 'btn btn-success mr-2 mb-1']);
echo html_writer::link($statsurl, 'Statistiques', ['class' => 'btn btn-info mr-2 mb-1']);
echo '</div>';

// Get all AI grading records.
$records = $DB->get_records('local_dreamu_ai_grades', ['assignid' => $cm->instance], 'timecreated DESC');

if (empty($records)) {
    echo $OUTPUT->notification('Aucun historique de correction IA pour ce devoir.', 'info');
} else {
    $total = count($records);
    $graded = 0; $validated = 0; $errors = 0;
    foreach ($records as $r) {
        if ($r->status === 'graded') $graded++;
        if ($r->status === 'validated') $validated++;
        if ($r->status === 'error') $errors++;
    }
    echo '<div class="alert alert-secondary">';
    echo "<strong>{$total}</strong> correction(s) | ";
    echo "<span class='badge badge-warning bg-warning text-dark'>{$graded} en attente</span> ";
    echo "<span class='badge badge-success bg-success'>{$validated} valid&eacute;e(s)</span> ";
    if ($errors > 0) echo "<span class='badge badge-danger bg-danger'>{$errors} erreur(s)</span>";
    echo '</div>';

    foreach ($records as $record) {
        $user = $DB->get_record('user', ['id' => $record->userid]);
        $username = $user ? fullname($user) : "User #{$record->userid}";

        $statusmap = [
            'validated' => ['badge-success bg-success', 'Valid&eacute;e'],
            'graded'    => ['badge-warning bg-warning text-dark', 'En attente'],
            'rejected'  => ['badge-secondary bg-secondary', 'Rejet&eacute;e'],
            'error'     => ['badge-danger bg-danger', 'Erreur'],
            'pending'   => ['badge-secondary bg-secondary', 'En cours'],
        ];
        $st = $statusmap[$record->status] ?? ['badge-secondary bg-secondary', $record->status];

        $gradecolor = '';
        if ($record->grade !== null) {
            $ratio = $maxgrade > 0 ? $record->grade / $maxgrade : 0;
            if ($ratio >= 0.7) $gradecolor = 'color: #28a745; font-weight: bold;';
            elseif ($ratio >= 0.5) $gradecolor = 'color: #e67e00; font-weight: bold;';
            else $gradecolor = 'color: #dc3545; font-weight: bold;';
        }

        $uid = 'fb_' . $record->id;

        echo '<div class="card mb-3">';
        echo '<div class="card-header d-flex justify-content-between align-items-center flex-wrap">';
        echo '<div>';
        echo "<strong>{$username}</strong> ";
        echo "<span class='badge {$st[0]}'>{$st[1]}</span> ";
        if ($record->grade !== null) {
            echo "<span style='{$gradecolor}'>" . number_format($record->grade, 2) . " / {$maxgrade}</span>";
        }
        echo '</div>';
        echo '<div>';
        echo '<small class="text-muted mr-2">' . userdate($record->timecreated) . '</small>';
        echo "<button class='btn btn-sm btn-outline-primary' onclick=\"var el=document.getElementById('{$uid}');el.style.display=el.style.display==='none'?'block':'none'\">Voir le feedback</button>";
        echo '</div>';
        echo '</div>';

        echo "<div id='{$uid}' class='card-body' style='display:none;'>";
        if (in_array($record->status, ['graded', 'validated', 'success']) && !empty($record->feedback)) {
            echo '<div style="max-height:600px;overflow-y:auto;border:1px solid #eee;padding:12px;border-radius:4px;">';
            echo $record->feedback;
            echo '</div>';
        } elseif ($record->status === 'error') {
            echo '<div class="alert alert-danger">' . s($record->errormessage ?? 'Erreur inconnue') . '</div>';
        } else {
            echo '<p class="text-muted">Pas de feedback disponible.</p>';
        }
        echo '</div>';
        echo '</div>';
    }
}

$backurl = new moodle_url('/mod/assign/view.php', ['id' => $cmid]);
echo $OUTPUT->single_button($backurl, 'Retour au devoir', 'get');

echo $OUTPUT->footer();
