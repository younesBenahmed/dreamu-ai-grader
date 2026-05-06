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
$submissions = $assign->get_all_submissions();
if (empty($submissions)) {
    $submissions = $DB->get_records('assign_submission', [
        'assignment' => $cm->instance,
        'status' => 'submitted',
        'latest' => 1,
    ]);
}

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

// Get embeddings for all submissions.
$embeddings = [];
$errors = [];

foreach ($submissiontexts as $userid => $text) {
    // Truncate to 2000 chars.
    $truncated = mb_substr($text, 0, 2000);

    $payload = json_encode([
        'model' => 'embedding',
        'input' => $truncated,
    ]);

    $ch = curl_init('http://100.76.166.71:8106/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlerror = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpcode !== 200) {
        $errors[] = "Erreur pour " . s($usernames[$userid]) . ": " . ($curlerror ?: "HTTP {$httpcode}");
        continue;
    }

    $data = json_decode($response, true);
    if (isset($data['data'][0]['embedding'])) {
        $embeddings[$userid] = $data['data'][0]['embedding'];
    } else {
        $errors[] = "Pas d'embedding retourné pour " . s($usernames[$userid]);
    }
}

if (!empty($errors)) {
    echo '<div class="alert alert-warning">';
    echo '<strong>Avertissements :</strong><ul>';
    foreach ($errors as $err) {
        echo '<li>' . $err . '</li>';
    }
    echo '</ul></div>';
}

if (count($embeddings) < 2) {
    echo $OUTPUT->notification('Pas assez d\'embeddings obtenus pour comparer.', 'error');
    echo $OUTPUT->footer();
    exit;
}

// Compute cosine similarity for all pairs.
function cosine_similarity(array $a, array $b): float {
    $dot = 0.0;
    $norma = 0.0;
    $normb = 0.0;
    $len = min(count($a), count($b));

    for ($i = 0; $i < $len; $i++) {
        $dot += $a[$i] * $b[$i];
        $norma += $a[$i] * $a[$i];
        $normb += $b[$i] * $b[$i];
    }

    $norma = sqrt($norma);
    $normb = sqrt($normb);

    if ($norma == 0 || $normb == 0) {
        return 0.0;
    }

    return $dot / ($norma * $normb);
}

$pairs = [];
$userids = array_keys($embeddings);

for ($i = 0; $i < count($userids); $i++) {
    for ($j = $i + 1; $j < count($userids); $j++) {
        $uid1 = $userids[$i];
        $uid2 = $userids[$j];
        $similarity = cosine_similarity($embeddings[$uid1], $embeddings[$uid2]);
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

    // Determine row color.
    $rowclass = '';
    if ($pair['similarity'] >= 0.85) {
        $rowclass = 'table-danger';
    } elseif ($pair['similarity'] >= 0.70) {
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
echo '<span class="badge bg-danger text-white p-2 mr-2">Rouge : >= 85% similarité</span> ';
echo '<span class="badge bg-warning text-dark p-2 mr-2">Orange : 70-85% similarité</span> ';
echo '<span class="badge bg-light text-dark border p-2">Normal : < 70%</span>';
echo '</div>';

// Back button.
$backurl = new moodle_url('/mod/assign/view.php', ['id' => $cmid]);
echo '<a href="' . $backurl . '" class="btn btn-secondary">Retour au devoir</a>';

echo $OUTPUT->footer();
