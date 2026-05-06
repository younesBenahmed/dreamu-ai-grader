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

$PAGE->set_url(new moodle_url('/local/dreamu_ai/stats.php', ['id' => $cmid]));
$PAGE->set_title('AI Grading Statistics - ' . $assignname);
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('Statistics: ' . format_string($assignname));

// Get validated + graded grades.
$records = $DB->get_records_select('local_dreamu_ai_grades',
    'assignid = :assignid AND grade IS NOT NULL AND status IN (:s1, :s2)',
    ['assignid' => $cm->instance, 's1' => 'graded', 's2' => 'validated']
);

if (empty($records)) {
    echo $OUTPUT->notification('No grades found. Run AI grading first.', 'info');
    $backurl = new moodle_url('/mod/assign/view.php', ['id' => $cmid]);
    echo $OUTPUT->single_button($backurl, get_string('back'), 'get');
    echo $OUTPUT->footer();
    exit;
}

// Calculate stats.
$grades = array_map(fn($r) => floatval($r->grade), $records);
sort($grades);

$count = count($grades);
$sum = array_sum($grades);
$average = $sum / $count;
$min = min($grades);
$max = max($grades);
$median = ($count % 2 === 0)
    ? ($grades[$count / 2 - 1] + $grades[$count / 2]) / 2
    : $grades[intdiv($count, 2)];

// Standard deviation.
$variance = array_sum(array_map(fn($g) => pow($g - $average, 2), $grades)) / $count;
$stddev = sqrt($variance);

// Distribution buckets.
$buckets = [];
$step = $maxgrade / 5;
for ($i = 0; $i < 5; $i++) {
    $low = round($i * $step, 1);
    $high = round(($i + 1) * $step, 1);
    $label = "{$low} - {$high}";
    $buckets[$label] = 0;
}
foreach ($grades as $g) {
    $idx = min(4, intval($g / $step));
    $keys = array_keys($buckets);
    $buckets[$keys[$idx]]++;
}

// Display stats cards.
echo '<div class="row mb-4">';

$stats = [
    ['Students', $count, 'secondary'],
    ['Average', number_format($average, 2) . ' / ' . $maxgrade, 'primary'],
    ['Median', number_format($median, 2), 'info'],
    ['Min', number_format($min, 2), 'danger'],
    ['Max', number_format($max, 2), 'success'],
    ['Std Dev', number_format($stddev, 2), 'warning'],
];

foreach ($stats as [$label, $value, $color]) {
    echo '<div class="col-md-2">';
    echo '<div class="card text-white bg-' . $color . ' mb-3">';
    echo '<div class="card-body text-center">';
    echo '<h5 class="card-title">' . $value . '</h5>';
    echo '<p class="card-text">' . $label . '</p>';
    echo '</div></div></div>';
}

echo '</div>';

// Distribution chart (CSS bars).
echo '<div class="card mb-4"><div class="card-body">';
echo '<h4>Grade Distribution</h4>';

$maxbucket = max(1, max($buckets));
foreach ($buckets as $label => $count_b) {
    $pct = round($count_b / $maxbucket * 100);
    $color = 'bg-primary';
    echo '<div class="mb-2">';
    echo '<div class="d-flex align-items-center">';
    echo '<span style="min-width:100px;">' . $label . '</span>';
    echo '<div class="progress flex-grow-1 mx-2" style="height:25px;">';
    echo '<div class="progress-bar ' . $color . '" style="width:' . $pct . '%" role="progressbar">' . $count_b . '</div>';
    echo '</div>';
    echo '</div></div>';
}

echo '</div></div>';

// Grade table per student.
echo '<div class="card mb-4"><div class="card-body">';
echo '<h4>Grades per Student</h4>';

$table = new html_table();
$table->head = ['Student', 'Grade', 'Status', 'Feedback (excerpt)'];
$table->attributes['class'] = 'generaltable';

foreach ($records as $record) {
    $user = $DB->get_record('user', ['id' => $record->userid]);
    if (!$user) continue;

    $statusmap = [
        'validated' => '<span class="badge badge-success bg-success">Validated</span>',
        'graded' => '<span class="badge badge-warning bg-warning">Pending</span>',
    ];

    $feedback = strip_tags($record->feedback ?? '');
    $feedback = shorten_text($feedback, 120);

    // Color the grade.
    $ratio = $maxgrade > 0 ? $record->grade / $maxgrade : 0;
    $gradecolor = $ratio >= 0.6 ? 'text-success' : ($ratio >= 0.4 ? 'text-warning' : 'text-danger');

    $table->data[] = [
        fullname($user),
        '<strong class="' . $gradecolor . '">' . number_format($record->grade, 2) . ' / ' . $maxgrade . '</strong>',
        $statusmap[$record->status] ?? $record->status,
        $feedback,
    ];
}

echo html_writer::table($table);
echo '</div></div>';

// Export CSV button.
$csvurl = new moodle_url('/local/dreamu_ai/export_csv.php', ['id' => $cmid]);
echo '<a href="' . $csvurl . '" class="btn btn-outline-primary mr-2">Export CSV</a>';

// Back button.
$backurl = new moodle_url('/local/dreamu_ai/validate.php', ['id' => $cmid]);
echo '<a href="' . $backurl . '" class="btn btn-secondary">Back to Validation</a>';

echo $OUTPUT->footer();
