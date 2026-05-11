<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$cmid = required_param('id', PARAM_INT);
$compare = optional_param('compare', '', PARAM_RAW); // Format: "userid1-userid2".

$cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('local/dreamu_ai:grade', $context);

$assign = new assign($context, $cm, $course);
$assignname = $assign->get_instance()->name;

$PAGE->set_url(new moodle_url('/local/dreamu_ai/plagiarism.php', ['id' => $cmid]));
$PAGE->set_title('Détection de plagiat - ' . $assignname);
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('Détection de plagiat : ' . format_string($assignname));

// Get all submissions with text.
$submissions = $DB->get_records('assign_submission', [
    'assignment' => $cm->instance,
    'status' => 'submitted',
    'latest' => 1,
]);

$submissiontexts = [];
$usernames = [];

foreach ($submissions as $submission) {
    if (empty($submission->userid) || $submission->userid <= 0) {
        continue;
    }
    if (isset($submission->status) && $submission->status !== 'submitted') {
        continue;
    }

    $text = \local_dreamu_ai\ai_grader::get_submission_text($assign, $submission, $submission->userid);
    $text = trim($text);
    if (empty($text)) {
        continue;
    }

    $user = $DB->get_record('user', ['id' => $submission->userid]);
    if (!$user) {
        continue;
    }

    $submissiontexts[$submission->userid] = $text;
    $usernames[$submission->userid] = fullname($user);
}

if (count($submissiontexts) < 2) {
    echo $OUTPUT->notification('Il faut au moins 2 soumissions avec du texte pour détecter le plagiat.', 'warning');
    $backurl = new moodle_url('/mod/assign/view.php', ['id' => $cmid]);
    echo $OUTPUT->single_button($backurl, get_string('back'), 'get');
    echo $OUTPUT->footer();
    exit;
}

// Handle compare view.
if (!empty($compare)) {
    $parts = explode('-', $compare);
    if (count($parts) === 2) {
        $uid1 = intval($parts[0]);
        $uid2 = intval($parts[1]);
        if (isset($submissiontexts[$uid1]) && isset($submissiontexts[$uid2])) {
            echo '<div class="alert alert-info">';
            echo '<a href="' . new moodle_url($PAGE->url, ['id' => $cmid]) . '" class="btn btn-sm btn-secondary float-right">Retour</a>';
            echo '<h4>Comparaison : ' . s($usernames[$uid1]) . ' vs ' . s($usernames[$uid2]) . '</h4>';
            echo '</div>';

            echo '<div class="row">';
            echo '<div class="col-md-6">';
            echo '<div class="card"><div class="card-header"><strong>' . s($usernames[$uid1]) . '</strong></div>';
            echo '<div class="card-body"><pre style="white-space:pre-wrap;word-wrap:break-word;">' . s($submissiontexts[$uid1]) . '</pre></div></div>';
            echo '</div>';
            echo '<div class="col-md-6">';
            echo '<div class="card"><div class="card-header"><strong>' . s($usernames[$uid2]) . '</strong></div>';
            echo '<div class="card-body"><pre style="white-space:pre-wrap;word-wrap:break-word;">' . s($submissiontexts[$uid2]) . '</pre></div></div>';
            echo '</div>';
            echo '</div>';

            echo $OUTPUT->footer();
            exit;
        }
    }
}

// Compute n-gram fingerprints for all submissions (no external API needed).
// Uses Jaccard similarity on 4-grams (sequences of 4 words).
function get_ngrams(string $text, int $n = 4): array {
    // Normalize: lowercase, remove comments/headers, split into words.
    $text = strtolower($text);
    $text = preg_replace('/---\s*File:.*?---/i', '', $text);
    $text = preg_replace('/\/\/.*$/m', '', $text);
    $text = preg_replace('/\/\*.*?\*\//s', '', $text);
    $text = preg_replace('/[^a-z0-9éèêëàâäùûüôöïîç_]+/u', ' ', $text);
    $words = preg_split('/\s+/', trim($text));
    $words = array_filter($words, fn($w) => strlen($w) > 1);
    $words = array_values($words);

    $ngrams = [];
    for ($i = 0; $i <= count($words) - $n; $i++) {
        $ngrams[] = implode(' ', array_slice($words, $i, $n));
    }
    return array_unique($ngrams);
}

function jaccard_similarity(array $a, array $b): float {
    if (empty($a) && empty($b)) return 0.0;
    $intersection = count(array_intersect($a, $b));
    $union = count(array_unique(array_merge($a, $b)));
    return $union > 0 ? $intersection / $union : 0.0;
}

// Build n-grams for each submission.
$ngrams_map = [];
foreach ($submissiontexts as $userid => $text) {
    $ngrams_map[$userid] = get_ngrams($text, 4);
}

// Compute similarity for all pairs.
$pairs = [];
$userids = array_keys($ngrams_map);

for ($i = 0; $i < count($userids); $i++) {
    for ($j = $i + 1; $j < count($userids); $j++) {
        $uid1 = $userids[$i];
        $uid2 = $userids[$j];
        $similarity = jaccard_similarity($ngrams_map[$uid1], $ngrams_map[$uid2]);
        $pairs[] = [
            'user1' => $uid1,
            'user2' => $uid2,
            'similarity' => $similarity,
        ];
    }
}

// Sort by similarity descending.
usort($pairs, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

// Display results.
echo '<div class="card mb-4"><div class="card-body">';
echo '<h4>Résultats de similarité (' . count($pairs) . ' paires analysées)</h4>';

echo '<table class="table table-bordered table-striped">';
echo '<thead><tr>';
echo '<th>Étudiant 1</th>';
echo '<th>Étudiant 2</th>';
echo '<th>Similarité</th>';
echo '<th>Action</th>';
echo '</tr></thead><tbody>';

foreach ($pairs as $pair) {
    $pct = round($pair['similarity'] * 100, 1);

    // Determine row color (Jaccard thresholds are lower than cosine).
    $rowclass = '';
    if ($pair['similarity'] >= 0.40) {
        $rowclass = 'table-danger';
    } elseif ($pair['similarity'] >= 0.25) {
        $rowclass = 'table-warning';
    }

    $compareurl = new moodle_url($PAGE->url, [
        'id' => $cmid,
        'compare' => $pair['user1'] . '-' . $pair['user2'],
    ]);

    echo '<tr class="' . $rowclass . '">';
    echo '<td>' . s($usernames[$pair['user1']]) . '</td>';
    echo '<td>' . s($usernames[$pair['user2']]) . '</td>';
    echo '<td><strong>' . $pct . '%</strong></td>';
    echo '<td><a href="' . $compareurl . '" class="btn btn-sm btn-outline-primary">Comparer</a></td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div></div>';

// Legend.
echo '<div class="mb-3">';
echo '<span class="badge bg-danger text-white p-2 mr-2">Rouge : >= 40% (plagiat probable)</span> ';
echo '<span class="badge bg-warning text-dark p-2 mr-2">Orange : 25-40% (suspect)</span> ';
echo '<span class="badge bg-light text-dark border p-2">Normal : < 25%</span>';
echo '</div>';

// Back button.
$backurl = new moodle_url('/mod/assign/view.php', ['id' => $cmid]);
echo '<a href="' . $backurl . '" class="btn btn-secondary">Retour au devoir</a>';

echo $OUTPUT->footer();
