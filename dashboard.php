<?php
require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/dreamu_ai:grade', $context);

$PAGE->set_url(new moodle_url('/local/dreamu_ai/dashboard.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title('Dashboard IA - ' . $course->fullname);
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('Dashboard IA');

// Get all assignments in this course that have AI grading configured.
$assignments = $DB->get_records_sql(
    "SELECT a.id, a.name, a.grade AS maxgrade, cm.id AS cmid, c.enabled
       FROM {assign} a
       JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = :courseid1
       JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
       LEFT JOIN {local_dreamu_ai_config} c ON c.assignid = a.id
      WHERE a.course = :courseid2
        AND c.enabled = 1
   ORDER BY a.name",
    ['courseid1' => $courseid, 'courseid2' => $courseid]
);

if (empty($assignments)) {
    echo $OUTPUT->notification('Aucun devoir avec correction IA activée dans ce cours.', 'info');
    $backurl = new moodle_url('/course/view.php', ['id' => $courseid]);
    echo $OUTPUT->single_button($backurl, get_string('back'), 'get');
    echo $OUTPUT->footer();
    exit;
}

// Collect stats for each assignment.
$allstats = [];
$globalsum = 0;
$globalcount = 0;
$globalmin = null;
$globalmax = null;

foreach ($assignments as $assign) {
    $records = $DB->get_records_select('local_dreamu_ai_grades',
        'assignid = :assignid AND grade IS NOT NULL AND status IN (:s1, :s2)',
        ['assignid' => $assign->id, 's1' => 'graded', 's2' => 'validated']
    );

    $stat = new stdClass();
    $stat->name = $assign->name;
    $stat->cmid = $assign->cmid;
    $stat->maxgrade = floatval($assign->maxgrade);
    $stat->count = count($records);
    $stat->average = 0;
    $stat->min = 0;
    $stat->max = 0;

    // Count validated vs pending.
    $validatedcount = 0;
    $pendingcount = 0;
    foreach ($records as $r) {
        if ($r->status === 'validated') {
            $validatedcount++;
        } else {
            $pendingcount++;
        }
    }
    $stat->validated = $validatedcount;
    $stat->pending = $pendingcount;

    if ($stat->count > 0) {
        $grades = array_map(fn($r) => floatval($r->grade), $records);
        $stat->average = array_sum($grades) / $stat->count;
        $stat->min = min($grades);
        $stat->max = max($grades);

        $globalsum += array_sum($grades);
        $globalcount += $stat->count;
        if ($globalmin === null || $stat->min < $globalmin) {
            $globalmin = $stat->min;
        }
        if ($globalmax === null || $stat->max > $globalmax) {
            $globalmax = $stat->max;
        }
    }

    $stat->status = ($pendingcount > 0) ? 'pending' : 'validated';
    $allstats[] = $stat;
}

$globalaverage = $globalcount > 0 ? $globalsum / $globalcount : 0;

// Summary cards.
echo '<div class="row mb-4">';

$summaryCards = [
    ['Devoirs IA', count($allstats), 'primary'],
    ['Total soumissions', $globalcount, 'secondary'],
    ['Moyenne globale', number_format($globalaverage, 2), 'info'],
    ['Min global', $globalmin !== null ? number_format($globalmin, 2) : '-', 'danger'],
    ['Max global', $globalmax !== null ? number_format($globalmax, 2) : '-', 'success'],
];

foreach ($summaryCards as [$label, $value, $color]) {
    echo '<div class="col-md-2">';
    echo '<div class="card text-white bg-' . $color . ' mb-3">';
    echo '<div class="card-body text-center">';
    echo '<h5 class="card-title">' . $value . '</h5>';
    echo '<p class="card-text">' . $label . '</p>';
    echo '</div></div></div>';
}

echo '</div>';

// Assignments table.
echo '<div class="card mb-4"><div class="card-body">';
echo '<h4>Devoirs avec correction IA</h4>';

$table = new html_table();
$table->head = ['Devoir', 'Soumissions', 'Moyenne', 'Min', 'Max', 'Statut', 'Actions'];
$table->attributes['class'] = 'generaltable';

foreach ($allstats as $stat) {
    $statusbadge = ($stat->status === 'validated')
        ? '<span class="badge badge-success bg-success">Validé</span>'
        : '<span class="badge badge-warning bg-warning">En attente (' . $stat->pending . ')</span>';

    $avgcolor = '';
    if ($stat->count > 0 && $stat->maxgrade > 0) {
        $ratio = $stat->average / $stat->maxgrade;
        $avgcolor = $ratio >= 0.6 ? 'text-success' : ($ratio >= 0.4 ? 'text-warning' : 'text-danger');
    }

    $actions = '';
    $statsurl = new moodle_url('/local/dreamu_ai/stats.php', ['id' => $stat->cmid]);
    $validateurl = new moodle_url('/local/dreamu_ai/validate.php', ['id' => $stat->cmid]);
    $plagiarismurl = new moodle_url('/local/dreamu_ai/plagiarism.php', ['id' => $stat->cmid]);
    $actions .= '<a href="' . $statsurl . '" class="btn btn-sm btn-outline-info mr-1">Stats</a>';
    $actions .= '<a href="' . $validateurl . '" class="btn btn-sm btn-outline-success mr-1">Valider</a>';
    $actions .= '<a href="' . $plagiarismurl . '" class="btn btn-sm btn-outline-danger">Plagiat</a>';

    $table->data[] = [
        format_string($stat->name),
        $stat->count,
        $stat->count > 0 ? '<strong class="' . $avgcolor . '">' . number_format($stat->average, 2) . ' / ' . $stat->maxgrade . '</strong>' : '-',
        $stat->count > 0 ? number_format($stat->min, 2) : '-',
        $stat->count > 0 ? number_format($stat->max, 2) : '-',
        $statusbadge,
        $actions,
    ];
}

// Summary row.
$table->data[] = [
    '<strong>Total / Moyenne globale</strong>',
    '<strong>' . $globalcount . '</strong>',
    '<strong>' . number_format($globalaverage, 2) . '</strong>',
    '<strong>' . ($globalmin !== null ? number_format($globalmin, 2) : '-') . '</strong>',
    '<strong>' . ($globalmax !== null ? number_format($globalmax, 2) : '-') . '</strong>',
    '',
    '',
];

echo html_writer::table($table);
echo '</div></div>';

// Back button.
$backurl = new moodle_url('/course/view.php', ['id' => $courseid]);
echo '<a href="' . $backurl . '" class="btn btn-secondary">Retour au cours</a>';

echo $OUTPUT->footer();
