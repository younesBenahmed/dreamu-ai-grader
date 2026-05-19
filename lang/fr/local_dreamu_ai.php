<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Dream-U AI Grader';
$string['privacy:metadata'] = 'Le plugin Dream-U AI Grader envoie le texte des soumissions à un service IA pour la correction.';

// Settings.
$string['enable_ai_grading'] = 'Activer la correction IA';
$string['enable_ai_grading_help'] = 'Quand activé, un bouton apparaît pour corriger toutes les soumissions avec l\'IA.';
$string['grading_prompt'] = 'Consignes de correction';
$string['grading_prompt_help'] = 'Un bon prompt contient 4 éléments :

1. **Un barème pondéré** : indiquez le poids de chaque critère (ex : « Fonctionnement 40%, gestion d\'erreurs 20%, lisibilité 15%, structure 15%, documentation 10% »).

2. **Des ancrages de notation** : décrivez ce que représente chaque tranche de note (ex : « 0-3 = hors-sujet ; 4-7 = bugs critiques ; 12-15 = correct ; 18-20 = excellent »).

3. **Des pénalités et bonus automatiques** : listez les fautes systématiques et leur coût en points (ex : « bug critique -3 pts, aucun README -1 pt, tests unitaires +2 pts bonus »).

4. **Le format de réponse attendu** : demandez explicitement le détail du calcul et la citation des éléments précis du travail (noms de fonctions, paragraphes, étapes du raisonnement).

Sans ces éléments, l\'IA tend à concentrer ses notes autour de la moyenne (10-14) et à donner un feedback générique. Cliquez sur « Voir des exemples » sous ce champ pour accéder à 5 modèles élaborés que vous pouvez insérer en un clic et adapter.

**Variables disponibles** dans le prompt (substituées automatiquement à chaque correction) :
- `{maxgrade}` : note maximale du devoir
- `{assignname}` : nom du devoir
- `{coursename}` : nom du cours
- `{language}` : langue du feedback (français / english)
- `{duedate}` : date limite du devoir';
$string['grading_prompt_default'] = 'Vous êtes un assistant d\'enseignement universitaire. Corrigez la soumission de l\'étudiant en vous basant sur les critères ci-dessous. Fournissez une note chiffrée et un feedback détaillé.';

// Prompt examples panel.
$string['show_examples'] = 'Voir des exemples de prompts élaborés (5 modèles)';
$string['show_examples_intro'] = 'Cliquez sur « Utiliser ce modèle » pour pré-remplir le champ ci-dessus. Pensez à adapter le sujet, le barème et les pénalités à votre devoir.';
$string['use_template'] = 'Utiliser ce modèle';
$string['token_count_label'] = 'Longueur estimée du prompt :';

$string['example_code_title'] = '1. Devoir de programmation (code à rendre)';
$string['example_code'] = 'Vous corrigez un devoir de programmation sur {maxgrade} points. Utilisez OBLIGATOIREMENT toute l\'échelle 0-{maxgrade}.

BARÈME PONDÉRÉ ({maxgrade} pts total) :
- Fonctionnement / exactitude algorithmique : 40%
- Gestion des erreurs et cas limites : 20%
- Lisibilité (nommage, indentation, clarté) : 15%
- Structuration (fonctions, classes, séparation des responsabilités) : 15%
- Documentation (docstrings, commentaires, README, tests) : 10%

ANCRAGES DE NOTATION (à suivre STRICTEMENT) :
- 0-3  : Code vide, hors-sujet, ne compile pas / ne s\'exécute pas du tout
- 4-7  : Bugs critiques (résultat faux, return manquant, crash sur cas standard), aucune doc
- 8-11 : Fonctionnel sur les cas normaux mais aucune gestion d\'erreur, code brouillon
- 12-15: Fonctionnel + gestion d\'erreur basique, code propre, documentation légère
- 16-18: Bien structuré (classes/modules), gestion d\'erreur complète (exceptions), docs partielles
- 19-20: Conception propre + tests unitaires + docstrings + README complet + edge cases gérés

PÉNALITÉS / BONUS AUTOMATIQUES :
- Bug logique dans une fonction principale : -3 pts par bug
- Crash sur cas limite courant (division par zéro, liste vide, fichier inexistant) : -2 pts
- Aucune docstring sur les fonctions publiques : -1 pt
- Aucun README.md : -1 pt
- Tests unitaires fonctionnels présents : +2 pts BONUS
- Hors-sujet (ne traite pas la consigne) : note PLAFONNÉE à 3/{maxgrade}

FORMAT DE RÉPONSE :
1. Note finale (peut être demi-point)
2. Détail du calcul par critère
3. Feedback citant les noms exacts de fonctions et variables défaillantes';

$string['example_essay_title'] = '2. Dissertation / rédaction (lettres, philo, sciences humaines)';
$string['example_essay'] = 'Vous corrigez la dissertation « {assignname} » sur {maxgrade} points. Utilisez OBLIGATOIREMENT toute l\'échelle 0-{maxgrade}.

BARÈME PONDÉRÉ :
- Compréhension du sujet et problématisation : 25%
- Structure de l\'argumentation (introduction, plan, transitions, conclusion) : 25%
- Qualité des arguments et exemples (pertinence, profondeur) : 25%
- Qualité de la rédaction (grammaire, orthographe, style) : 15%
- Références et citations correctement intégrées : 10%

ANCRAGES DE NOTATION :
- 0-4  : Hors-sujet complet, copie quasi-vide, ou plagiat évident
- 5-8  : Sujet mal compris, pas de plan, fautes nombreuses, aucune argumentation
- 9-11 : Sujet compris partiellement, plan implicite, arguments faibles, fautes
- 12-14: Sujet bien compris, plan clair, arguments corrects mais peu approfondis
- 15-17: Bonne problématisation, plan structuré, arguments solides avec exemples
- 18-20: Problématique fine, argumentation originale, références maîtrisées, style soigné

PÉNALITÉS :
- Aucun plan visible (pas de paragraphes structurés) : -2 pts
- Plus de 15 fautes d\'orthographe/grammaire : -2 pts
- Aucune conclusion : -1 pt
- Aucun exemple concret : -2 pts
- Plagiat suspecté (à signaler explicitement dans le feedback) : note PLAFONNÉE à 3/{maxgrade}

FORMAT DE RÉPONSE :
1. Note finale
2. Plan implicite identifié (ex : « I. ... ; II. ... ; III. ... »)
3. Trois points forts cités précisément
4. Trois axes d\'amélioration ciblés';

$string['example_lab_title'] = '3. Compte-rendu de TP scientifique (physique, chimie, biologie)';
$string['example_lab'] = 'Vous corrigez un compte-rendu de TP sur {maxgrade} points. Utilisez OBLIGATOIREMENT toute l\'échelle 0-{maxgrade}.

BARÈME PONDÉRÉ :
- Présentation du protocole et du dispositif : 15%
- Résultats expérimentaux (tableaux, valeurs, unités) : 25%
- Analyse et exploitation (calculs d\'incertitudes, graphes, modélisation) : 25%
- Discussion / interprétation physique-chimique-biologique : 20%
- Conclusion répondant à la problématique du TP : 10%
- Présentation générale (figures numérotées, légendes, propreté) : 5%

ANCRAGES DE NOTATION :
- 0-4  : Compte-rendu absent ou ne décrit pas le TP réalisé
- 5-8  : Résultats bruts sans aucune analyse, pas d\'unités, pas de discussion
- 9-11 : Résultats présents mais analyse superficielle, peu de raisonnement
- 12-14: Analyse correcte, calculs justes, discussion sommaire
- 15-17: Bonne exploitation, incertitudes calculées, interprétation pertinente
- 18-20: Analyse rigoureuse, modélisation, discussion critique, ouverture

PÉNALITÉS :
- Aucune unité physique sur les valeurs : -2 pts
- Aucun calcul d\'incertitude : -2 pts
- Figures non numérotées ou sans légende : -1 pt
- Pas de comparaison à la théorie ou valeur tabulée : -1 pt
- Conclusion absente ou hors-sujet : -2 pts

FORMAT DE RÉPONSE :
1. Note finale
2. Note par section du barème
3. Trois axes d\'amélioration concrets, citant les paragraphes/figures concernés';

$string['example_math_title'] = '4. Exercice mathématique avec démonstration';
$string['example_math'] = 'Vous corrigez un exercice mathématique sur {maxgrade} points. Utilisez OBLIGATOIREMENT toute l\'échelle 0-{maxgrade}.

BARÈME PONDÉRÉ :
- Justification de chaque étape (théorèmes cités, hypothèses vérifiées) : 30%
- Rigueur du raisonnement (logique, enchaînement, pas de saut) : 30%
- Exactitude du résultat final : 20%
- Calculs intermédiaires corrects : 15%
- Qualité de la rédaction (notations standards, quantificateurs, conclusion) : 5%

ANCRAGES DE NOTATION :
- 0-3  : Aucune démarche, ou raisonnement complètement faux
- 4-7  : Quelques amorces correctes mais erreurs majeures non détectées
- 8-11 : Démarche partielle, plusieurs étapes manquantes, résultat faux
- 12-14: Démarche valide mais hypothèses non vérifiées ou erreurs de calcul
- 15-17: Raisonnement rigoureux, hypothèses citées, petite erreur de calcul
- 18-20: Démonstration impeccable, justifications complètes, résultat correct

PÉNALITÉS :
- Résultat juste sans aucune justification : -5 pts (le résultat seul ne suffit pas)
- Hypothèse non vérifiée avant application d\'un théorème : -2 pts par occurrence
- Erreur de calcul propagée : -1 pt
- Notation non standard ou ambiguë : -1 pt

ATTENTION : un résultat correct obtenu par hasard sans démarche valide doit être noté FAIBLE. Une démarche correcte avec erreur de calcul mineure doit garder une note HONORABLE.

FORMAT DE RÉPONSE :
1. Note finale
2. Statut de chaque étape (correcte / fautive / manquante)
3. Correction explicite de la PREMIÈRE erreur trouvée';

$string['example_analysis_title'] = '5. Analyse de cas / étude documentaire (éco, gestion, droit, géo)';
$string['example_analysis'] = 'Vous corrigez une analyse de cas ou étude documentaire sur {maxgrade} points. Utilisez OBLIGATOIREMENT toute l\'échelle 0-{maxgrade}.

BARÈME PONDÉRÉ :
- Identification des enjeux du cas / des documents : 20%
- Mobilisation de concepts/notions du cours : 25%
- Analyse critique (pas de paraphrase, prise de recul) : 25%
- Articulation des documents entre eux (si plusieurs) : 15%
- Conclusion / recommandation / réponse à la problématique : 10%
- Présentation et clarté : 5%

ANCRAGES DE NOTATION :
- 0-4  : Paraphrase intégrale, aucun apport personnel
- 5-8  : Enjeux mal identifiés, concepts du cours absents, analyse plate
- 9-11 : Enjeux identifiés mais analyse superficielle, peu de concepts mobilisés
- 12-14: Bonne identification, plusieurs concepts mobilisés, analyse correcte
- 15-17: Analyse fine, concepts justement appliqués, recul critique
- 18-20: Analyse originale, concepts maîtrisés et croisés, recommandations argumentées

PÉNALITÉS :
- Plus de 50% de paraphrase des documents : note PLAFONNÉE à 8/{maxgrade}
- Aucun concept du cours cité explicitement : -3 pts
- Aucune conclusion : -2 pts
- Aucune source / référence (si attendues par la consigne) : -1 pt

FORMAT DE RÉPONSE :
1. Note finale
2. Résumé en 2 lignes de l\'analyse de l\'étudiant
3. Trois forces + trois faiblesses cités avec extraits du texte
4. Recommandation pédagogique pour la prochaine production';
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

// Publier dans le carnet de notes.
$string['publish_grades'] = 'Publier dans le carnet de notes';
$string['grades_published'] = 'Les notes ont été publiées dans le carnet de notes.';
$string['confirm_publish_grades'] = 'Publier toutes les notes validées dans le carnet de notes Moodle ?';
