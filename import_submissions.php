<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->libdir . '/filelib.php');

$cmid = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/assign:grade', $context);

$assign = new assign($context, $cm, $course);
$instance = $assign->get_instance();

$PAGE->set_url(new moodle_url('/local/dreamu_ai/import_submissions.php', ['id' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_title('Importer des soumissions - ' . $instance->name);
$PAGE->set_heading($course->fullname);

// Handle file upload.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $includehidden = optional_param('createusers', 1, PARAM_BOOL);
    $defaultpassword = optional_param('password', 'Etudiant2026!', PARAM_RAW);

    if (isset($_FILES['zipfile']) && $_FILES['zipfile']['error'] === UPLOAD_ERR_OK) {
        $tmpfile = $_FILES['zipfile']['tmp_name'];
        $filename = $_FILES['zipfile']['name'];

        $zip = new ZipArchive();
        if ($zip->open($tmpfile) !== true) {
            redirect($PAGE->url, 'Erreur : impossible d\'ouvrir le fichier ZIP', null, \core\output\notification::NOTIFY_ERROR);
        }

        // Extract to temp dir.
        $tmpdir = make_temp_directory('dreamu_import_' . time());
        $zip->extractTo($tmpdir);
        $zip->close();

        // Scan for submission folders/files.
        $submissions = [];
        $items = scandir($tmpdir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $tmpdir . '/' . $item;

            if (is_dir($path)) {
                // Folder = one submission. Folder name = student/group name.
                $submissions[] = (object)[
                    'name' => $item,
                    'path' => $path,
                    'type' => 'folder',
                ];
            } elseif (is_file($path)) {
                // Single file = one submission. Filename = student name.
                $basename = pathinfo($item, PATHINFO_FILENAME);
                $submissions[] = (object)[
                    'name' => $basename,
                    'path' => $path,
                    'type' => 'file',
                ];
            }
        }

        if (empty($submissions)) {
            redirect($PAGE->url, 'Aucune soumission trouvée dans le ZIP', null, \core\output\notification::NOTIFY_ERROR);
        }

        // Process each submission.
        $imported = 0;
        $errors = [];
        $created_users = [];

        foreach ($submissions as $sub) {
            // Clean name for username.
            $cleanname = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '_', $sub->name));
            $cleanname = substr($cleanname, 0, 50);

            // Find or create user.
            $user = $DB->get_record('user', ['username' => $cleanname]);
            if (!$user) {
                // Extract first/last name from folder name.
                $parts = preg_split('/[_\-,\s]+/', $sub->name);
                $parts = array_filter($parts, fn($p) => strlen($p) > 1);
                $parts = array_values($parts);

                $firstname = ucfirst(strtolower($parts[0] ?? $cleanname));
                $lastname = ucfirst(strtolower($parts[1] ?? 'Étudiant'));

                $user = new stdClass();
                $user->username = $cleanname;
                $user->password = hash_internal_user_password($defaultpassword);
                $user->firstname = $firstname;
                $user->lastname = $lastname;
                $user->email = $cleanname . '@import.local';
                $user->confirmed = 1;
                $user->mnethostid = $CFG->mnet_localhost_id;
                $user->timecreated = time();
                $user->timemodified = time();

                try {
                    $user->id = $DB->insert_record('user', $user);
                    $created_users[] = $user->username;
                } catch (\Exception $e) {
                    $errors[] = "Impossible de créer l'utilisateur pour '{$sub->name}' : " . $e->getMessage();
                    continue;
                }
            }

            // Enrol in course if not already.
            $enrolled = is_enrolled($context, $user->id);
            if (!$enrolled) {
                $plugin = enrol_get_plugin('manual');
                $instances = enrol_get_instances($course->id, true);
                $manualinstance = null;
                foreach ($instances as $inst) {
                    if ($inst->enrol === 'manual') { $manualinstance = $inst; break; }
                }
                if (!$manualinstance) {
                    $plugin->add_instance($course);
                    $instances = enrol_get_instances($course->id, true);
                    foreach ($instances as $inst) {
                        if ($inst->enrol === 'manual') { $manualinstance = $inst; break; }
                    }
                }
                if ($manualinstance) {
                    try {
                        $plugin->enrol_user($manualinstance, $user->id, 5); // 5 = student role
                    } catch (\Exception $e) {
                        // Ignore email errors.
                    }
                }
            }

            // Create submission.
            $submission = $assign->get_user_submission($user->id, true);
            $fs = get_file_storage();

            // Clear old files.
            $fs->delete_area_files($context->id, 'assignsubmission_file', 'submission_files', $submission->id);

            if ($sub->type === 'folder') {
                // ZIP the folder and upload.
                $zipname = $cleanname . '.zip';
                $zippath = $tmpdir . '/' . $zipname;
                $subzip = new ZipArchive();
                $subzip->open($zippath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($sub->path, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($files as $file) {
                    if ($file->isFile()) {
                        $relpath = substr($file->getPathname(), strlen($sub->path) + 1);
                        $subzip->addFile($file->getPathname(), $relpath);
                    }
                }
                $subzip->close();

                $filerecord = [
                    'contextid' => $context->id,
                    'component' => 'assignsubmission_file',
                    'filearea' => 'submission_files',
                    'itemid' => $submission->id,
                    'filepath' => '/',
                    'filename' => $zipname,
                ];
                $fs->create_file_from_pathname($filerecord, $zippath);
            } else {
                // Single file upload.
                $filerecord = [
                    'contextid' => $context->id,
                    'component' => 'assignsubmission_file',
                    'filearea' => 'submission_files',
                    'itemid' => $submission->id,
                    'filepath' => '/',
                    'filename' => basename($sub->path),
                ];
                $fs->create_file_from_pathname($filerecord, $sub->path);
            }

            // Mark as submitted.
            $submission->status = 'submitted';
            $submission->timemodified = time();
            $DB->update_record('assign_submission', $submission);
            $imported++;
        }

        // Cleanup temp dir.
        remove_dir($tmpdir);

        $msg = "{$imported} soumissions importées avec succès !";
        if (!empty($created_users)) {
            $msg .= " " . count($created_users) . " nouveaux comptes étudiants créés.";
        }
        if (!empty($errors)) {
            $msg .= " Erreurs : " . implode('; ', $errors);
        }

        redirect(
            new moodle_url('/mod/assign/view.php', ['id' => $cmid, 'action' => 'grading']),
            $msg, null, \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        redirect($PAGE->url, 'Veuillez sélectionner un fichier ZIP', null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Display form.
echo $OUTPUT->header();

echo '<h2>Importer des soumissions</h2>';
echo '<p>Téléchargez un fichier ZIP contenant les soumissions des étudiants. Chaque dossier ou fichier dans le ZIP sera traité comme une soumission.</p>';

echo '<div class="card mb-3"><div class="card-body">';
echo '<h5>Structure ZIP attendue :</h5>';
echo '<pre style="background:#f4f4f4;padding:15px;border-radius:5px;">';
echo "submissions.zip\n";
echo "├── Etudiant1_Nom/          → Crée l'utilisateur 'etudiant1_nom', soumet le dossier en ZIP\n";
echo "│   ├── main.cpp\n";
echo "│   ├── header.h\n";
echo "│   └── ...\n";
echo "├── Etudiant2_Nom/\n";
echo "│   └── code.cpp\n";
echo "├── Etudiant3_Nom.zip       → Crée l'utilisateur 'etudiant3_nom', soumet le ZIP\n";
echo "└── ...\n";
echo '</pre>';
echo '</div></div>';

echo '<div class="card mb-3"><div class="card-body">';
echo '<h5>Devoir : <strong>' . format_string($instance->name) . '</strong></h5>';

$subcount = $DB->count_records('assign_submission', ['assignment' => $instance->id, 'status' => 'submitted', 'latest' => 1]);
echo '<p>Soumissions actuelles : <strong>' . $subcount . '</strong></p>';
echo '</div></div>';

echo '<form method="post" enctype="multipart/form-data">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

echo '<div class="form-group">';
echo '<label for="zipfile"><strong>Fichier ZIP avec les soumissions :</strong></label>';
echo '<input type="file" name="zipfile" id="zipfile" accept=".zip" class="form-control-file" required>';
echo '</div>';

echo '<div class="form-group">';
echo '<label for="password"><strong>Mot de passe par défaut pour les nouveaux étudiants :</strong></label>';
echo '<input type="text" name="password" id="password" value="Etudiant2026!" class="form-control" style="max-width:300px;">';
echo '<small class="form-text text-muted">Tous les nouveaux comptes étudiants utiliseront ce mot de passe.</small>';
echo '</div>';

echo '<div class="form-check mb-3">';
echo '<input class="form-check-input" type="checkbox" name="createusers" value="1" id="createusers" checked>';
echo '<label class="form-check-label" for="createusers">Créer automatiquement les comptes étudiants s\'ils n\'existent pas</label>';
echo '</div>';

echo '<button type="submit" class="btn btn-primary btn-lg">Importer les soumissions</button>';
echo ' <a href="' . new moodle_url('/mod/assign/view.php', ['id' => $cmid]) . '" class="btn btn-secondary">Annuler</a>';
echo '</form>';

echo $OUTPUT->footer();
