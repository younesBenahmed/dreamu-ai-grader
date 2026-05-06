<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Dream-U AI Grader';
$string['privacy:metadata'] = 'Le plugin Dream-U AI Grader envoie le texte des soumissions à un service IA pour la correction.';

// Settings.
$string['enable_ai_grading'] = 'Activer la correction IA';
$string['enable_ai_grading_help'] = 'Quand activé, un bouton apparaît pour corriger toutes les soumissions avec l\'IA.';
$string['grading_prompt'] = 'Consignes de correction';
$string['grading_prompt_help'] = 'Fournissez des critères de notation détaillés pour l\'IA. Soyez précis sur ce qu\'il faut évaluer, le barème et le type de feedback attendu.';
$string['grading_prompt_default'] = 'Vous êtes un assistant d\'enseignement universitaire. Corrigez la soumission de l\'étudiant en vous basant sur les critères ci-dessous. Fournissez une note chiffrée et un feedback détaillé.';
$string['max_grade'] = 'Note maximale';
$string['language'] = 'Langue du feedback';
$string['language_fr'] = 'Français';
$string['language_en'] = 'Anglais';

// Navigation and UI.
$string['ai_grade_all'] = 'Corriger tout avec l\'IA';
$string['ai_grade_history'] = 'Historique des corrections IA';
$string['validate_grades'] = 'Valider les notes IA';
$string['grade_submissions'] = 'Corriger les soumissions avec l\'IA';
$string['confirm_grade_all'] = 'Voulez-vous vraiment corriger toutes les soumissions avec l\'IA ? Les notes devront être validées avant d\'être appliquées.';
$string['grading_started'] = 'La correction IA a été lancée. Vous serez notifié quand elle sera terminée pour pouvoir valider les notes.';
$string['no_submissions'] = 'Aucune soumission trouvée à corriger.';

// Task.
$string['task_grade_submissions'] = 'Correction IA des soumissions';

// Results and notifications.
$string['grading_complete'] = 'Correction IA terminée : {$a->graded} soumissions corrigées, {$a->errors} erreurs.';
$string['grading_complete_subject'] = 'Correction IA terminée';
$string['grading_complete_html'] = '<p>La correction IA est terminée pour <strong>{$a->assignname}</strong>.</p><p>{$a->graded} soumissions corrigées, {$a->errors} erreurs.</p><p><a href="{$a->validateurl}">Cliquez ici pour valider les notes</a></p>';
$string['grading_error'] = 'Erreur lors de la correction pour l\'utilisateur {$a->userid} : {$a->error}';
$string['messageprovider:grading_complete'] = 'Notification de fin de correction IA';

// Validation workflow.
$string['pending_validation'] = 'En attente de validation';
$string['processed_grades'] = 'Notes traitées';
$string['ai_suggested_grade'] = 'Note suggérée par l\'IA';
$string['ai_feedback'] = 'Feedback IA';
$string['approve_grade'] = 'Approuver et appliquer';
$string['reject_grade'] = 'Rejeter';
$string['approve_all'] = 'Approuver toutes les notes';
$string['confirm_approve_all'] = 'Appliquer toutes les notes IA au carnet de notes ? Cette action est irréversible.';
$string['confirm_reject'] = 'Rejeter cette note IA ? L\'étudiant ne recevra pas cette note.';
$string['grade_approved'] = 'Note approuvée et appliquée au carnet de notes.';
$string['grade_rejected'] = 'Note rejetée.';
$string['all_grades_approved'] = '{$a} notes approuvées et appliquées au carnet de notes.';
$string['no_ai_grades'] = 'Aucun résultat de correction IA. Utilisez "Corriger tout avec l\'IA" pour commencer.';

// Status labels.
$string['status_validated'] = 'Validée';
$string['status_rejected'] = 'Rejetée';
$string['status_error'] = 'Erreur';
$string['status_pending'] = 'En attente';
$string['status_graded'] = 'Corrigée (en attente de validation)';

// Capabilities.
$string['dreamu_ai:grade'] = 'Corriger les devoirs avec l\'IA';
$string['dreamu_ai:configure'] = 'Configurer la correction IA';

// Settings page.
$string['settings_heading'] = 'Paramètres Dream-U AI Grader';
$string['api_endpoint'] = 'Endpoint API vLLM';
$string['api_endpoint_desc'] = 'L\'endpoint API compatible OpenAI pour vLLM (ex: http://100.76.166.71:8200/v1/chat/completions)';
$string['api_key'] = 'Clé API';
$string['api_key_desc'] = 'Clé API pour le service LLM (utilisez sk-dummy pour vLLM)';
$string['model_name'] = 'Nom du modèle';
$string['model_name_desc'] = 'Le modèle à utiliser pour la correction (ex: general)';
