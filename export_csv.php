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
$maxgrade = floatval($assign->get_instance()->grade);
$assignname = $assign->get_instance()->name;

// Get all AI grades for this assignment.
$records = $DB->get_records('local_dreamu_ai_grades', [
    'assignid' => $cm->instance,
], 'timecreated DESC');

if (empty($records)) {
    redirect(
        new moodle_url('/local/dreamu_ai/validate.php', ['id' => $cmid]),
        'Aucune note à exporter.',
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Generate CSV.
$filename = clean_filename('ai_grades_' . $assignname . '_' . date('Y-m-d')) . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// BOM for Excel UTF-8.
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Header row.
fputcsv($output, [
    'Étudiant',
    'Identifiant',
    'Email',
    'Note',
    'Note maximale',
    'Statut',
    'Feedback',
    'Date',
], ';');

foreach ($records as $record) {
    $user = $DB->get_record('user', ['id' => $record->userid]);
    if (!$user) {
        continue;
    }

    $statusmap = [
        'graded' => 'En attente de validation',
        'validated' => 'Validée',
        'rejected' => 'Rejetée',
        'error' => 'Erreur',
        'pending' => 'En cours',
    ];

    // Clean feedback for CSV (remove HTML, limit length).
    $feedback = strip_tags($record->feedback ?? '');
    $feedback = str_replace(["\r\n", "\r", "\n"], ' ', $feedback);
    if (strlen($feedback) > 2000) {
        $feedback = substr($feedback, 0, 2000) . '...';
    }

    fputcsv($output, [
        fullname($user),
        $user->username,
        $user->email,
        $record->grade !== null ? number_format($record->grade, 2, '.', '') : '',
        $maxgrade,
        $statusmap[$record->status] ?? $record->status,
        $feedback,
        userdate($record->timecreated, '%Y-%m-%d %H:%M'),
    ], ';');
}

fclose($output);
exit;
