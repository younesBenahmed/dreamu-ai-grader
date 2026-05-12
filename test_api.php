<?php
require_once(__DIR__ . '/../../config.php');

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/dreamu_ai/test_api.php'));
$PAGE->set_context($context);
$PAGE->set_title('Test connexion IA');
$PAGE->set_heading('Dream-U - Test connexion IA');

echo $OUTPUT->header();
echo '<h3>Test de connexion a l\'IA</h3>';

$endpoint = get_config('local_dreamu_ai', 'api_endpoint');
$apikey = get_config('local_dreamu_ai', 'api_key');
$model = get_config('local_dreamu_ai', 'model_name');

echo '<div class="card mb-3"><div class="card-body">';
echo '<table class="table table-sm" style="max-width:600px;">';
echo '<tr><td><strong>Endpoint</strong></td><td><code>' . s($endpoint) . '</code></td></tr>';
echo '<tr><td><strong>Modele</strong></td><td><code>' . s($model) . '</code></td></tr>';
echo '<tr><td><strong>Cle API</strong></td><td><code>' . s(substr($apikey, 0, 10)) . '...</code></td></tr>';
echo '</table>';
echo '</div></div>';

// Test 1: Health check
echo '<h4>Test 1 : Connexion</h4>';
$healthurl = str_replace('/v1/chat/completions', '/health', $endpoint);
$ch = curl_init($healthurl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
$r = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($code === 200) {
    echo '<div class="alert alert-success">Connexion OK (HTTP 200)</div>';
} else {
    echo '<div class="alert alert-danger">Echec de connexion : HTTP ' . $code . ($err ? ' - ' . s($err) : '') . '</div>';
    echo '<p>Verifiez que le serveur IA est accessible depuis ce serveur Moodle.</p>';
    echo $OUTPUT->footer();
    exit;
}

// Test 2: List models
echo '<h4>Test 2 : Modeles disponibles</h4>';
$modelsurl = str_replace('/v1/chat/completions', '/v1/models', $endpoint);
$ch = curl_init($modelsurl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $apikey]);
$r = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code === 200) {
    $data = json_decode($r, true);
    $models = $data['data'] ?? [];
    if (!empty($models)) {
        echo '<div class="alert alert-success">Modeles trouves :</div>';
        echo '<ul>';
        foreach ($models as $m) {
            $match = ($m['id'] === $model) ? ' <span class="badge badge-success bg-success">CONFIGURE</span>' : '';
            echo '<li><code>' . s($m['id']) . '</code>' . $match . '</li>';
        }
        echo '</ul>';

        $modelids = array_column($models, 'id');
        if (!in_array($model, $modelids)) {
            echo '<div class="alert alert-warning">Le modele configure <code>' . s($model) . '</code> n\'est pas dans la liste ! Changez-le dans les parametres.</div>';
        }
    } else {
        echo '<div class="alert alert-warning">Aucun modele trouve.</div>';
    }
} else {
    echo '<div class="alert alert-warning">Impossible de lister les modeles (HTTP ' . $code . ')</div>';
}

// Test 3: Chat completion
echo '<h4>Test 3 : Generation de texte</h4>';
$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apikey,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'model' => $model,
    'messages' => [['role' => 'user', 'content' => 'Dis bonjour en une phrase.']],
    'max_tokens' => 50,
    'temperature' => 0.7,
]));
$r = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($code === 200) {
    $data = json_decode($r, true);
    $response = $data['choices'][0]['message']['content'] ?? 'Reponse vide';
    echo '<div class="alert alert-success">';
    echo '<strong>Reponse de l\'IA :</strong><br>';
    echo '<em>' . s($response) . '</em>';
    echo '</div>';
} else {
    echo '<div class="alert alert-danger">Echec : HTTP ' . $code . ($err ? ' - ' . s($err) : '') . '</div>';
    if ($r) {
        echo '<pre>' . s(substr($r, 0, 500)) . '</pre>';
    }
}

// Summary
echo '<hr>';
echo '<a href="' . new moodle_url('/admin/settings.php', ['section' => 'local_dreamu_ai']) . '" class="btn btn-primary">Modifier les parametres</a> ';
echo '<a href="' . $PAGE->url . '" class="btn btn-secondary">Relancer le test</a>';

echo $OUTPUT->footer();
