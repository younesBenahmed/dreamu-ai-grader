<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Library functions and hooks for local_dreamu_ai.
 *
 * @package    local_dreamu_ai
 * @copyright  2026 Dream-U / AMU / IUT Aix-en-Provence
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add AI grading navigation items to the assignment settings.
 *
 * @param settings_navigation $settingsnav
 * @param context $context
 */
function local_dreamu_ai_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    global $PAGE, $DB;

    // Only act on assign module pages.
    if ($context->contextlevel !== CONTEXT_MODULE) {
        return;
    }

    $cm = get_coursemodule_from_id('assign', $context->instanceid, 0, false, IGNORE_MISSING);
    if (!$cm) {
        return;
    }

    if (!has_capability('local/dreamu_ai:grade', $context)) {
        return;
    }

    $assignnode = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
    if (!$assignnode) {
        return;
    }

    // Check if AI grading is enabled for this assignment.
    $config = $DB->get_record('local_dreamu_ai_config', ['assignid' => $cm->instance]);
    if (!$config || !$config->enabled) {
        return;
    }

    // Add "AI Grade All" button.
    $url = new moodle_url('/local/dreamu_ai/grade.php', ['id' => $cm->id]);
    $assignnode->add(
        get_string('ai_grade_all', 'local_dreamu_ai'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'dreamu_ai_grade',
        new pix_icon('i/grades', '')
    );

    // Add "Validate AI Grades" link (with pending count).
    $pendingcount = $DB->count_records('local_dreamu_ai_grades', [
        'assignid' => $cm->instance,
        'status' => 'graded',
        'validated' => 0,
    ]);
    $validateurl = new moodle_url('/local/dreamu_ai/validate.php', ['id' => $cm->id]);
    $validatelabel = get_string('validate_grades', 'local_dreamu_ai');
    if ($pendingcount > 0) {
        $validatelabel .= " ({$pendingcount})";
    }
    $assignnode->add(
        $validatelabel,
        $validateurl,
        navigation_node::TYPE_SETTING,
        null,
        'dreamu_ai_validate',
        new pix_icon('i/checked', '')
    );

    // Add "AI Grading History" link.
    $historyurl = new moodle_url('/local/dreamu_ai/history.php', ['id' => $cm->id]);
    $assignnode->add(
        get_string('ai_grade_history', 'local_dreamu_ai'),
        $historyurl,
        navigation_node::TYPE_SETTING,
        null,
        'dreamu_ai_history',
        new pix_icon('i/report', '')
    );

    // Add "AI Statistics" link.
    $statsurl = new moodle_url('/local/dreamu_ai/stats.php', ['id' => $cm->id]);
    $assignnode->add(
        'Statistiques IA',
        $statsurl,
        navigation_node::TYPE_SETTING,
        null,
        'dreamu_ai_stats',
        new pix_icon('i/stats', '')
    );

    // Add "Import Submissions" link.
    $importurl = new moodle_url('/local/dreamu_ai/import_submissions.php', ['id' => $cm->id]);
    $assignnode->add(
        'Importer des soumissions',
        $importurl,
        navigation_node::TYPE_SETTING,
        null,
        'dreamu_ai_import',
        new pix_icon('i/upload', '')
    );
}

/**
 * Inject AI grading fields into the assignment creation/editing form.
 *
 * @param moodleform_mod $formwrapper
 * @param MoodleQuickForm $mform
 */
function local_dreamu_ai_coursemodule_standard_elements($formwrapper, $mform) {
    global $DB;

    $modulename = $formwrapper->get_current()->modulename ?? '';
    if ($modulename !== 'assign') {
        return;
    }

    $context = $formwrapper->get_context();
    if ($context->contextlevel === CONTEXT_MODULE && !has_capability('local/dreamu_ai:configure', $context)) {
        return;
    }

    // Load existing config if editing.
    $config = null;
    $cmid = $formwrapper->get_current()->coursemodule ?? 0;
    if ($cmid) {
        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
        if ($cm) {
            $config = $DB->get_record('local_dreamu_ai_config', ['assignid' => $cm->instance]);
        }
    }

    // Add AI Grading section.
    $mform->addElement('header', 'dreamu_ai_header', get_string('pluginname', 'local_dreamu_ai'));

    $mform->addElement('advcheckbox', 'dreamu_ai_enabled',
        get_string('enable_ai_grading', 'local_dreamu_ai'));
    $mform->addHelpButton('dreamu_ai_enabled', 'enable_ai_grading', 'local_dreamu_ai');
    $mform->setDefault('dreamu_ai_enabled', $config->enabled ?? 0);

    $mform->addElement('textarea', 'dreamu_ai_prompt',
        get_string('grading_prompt', 'local_dreamu_ai'),
        ['rows' => 8, 'cols' => 60]);
    $mform->addHelpButton('dreamu_ai_prompt', 'grading_prompt', 'local_dreamu_ai');
    $mform->setDefault('dreamu_ai_prompt', $config->prompt ?? '');
    $mform->hideIf('dreamu_ai_prompt', 'dreamu_ai_enabled', 'notchecked');

    // Live token counter under the textarea — approximation: ~4 characters per token.
    $tokenid = 'dreamu_tokens_' . uniqid();
    $tokenjs = "(function(){function u(){var t=document.querySelector('textarea[name=dreamu_ai_prompt]');if(!t)return;var n=Math.ceil((t.value||'').length/4);var s=document.getElementById('{$tokenid}');if(!s)return;s.textContent=n;s.style.color=n<2000?'#1b6e1b':(n<4000?'#a07000':'#c00')}var t=document.querySelector('textarea[name=dreamu_ai_prompt]');if(t){t.addEventListener('input',u);t.addEventListener('change',u);setTimeout(u,200)}})();";
    $tokenhtml = '<div style="margin:-.5em 0 1em 0;text-align:right;font-size:.85em;color:#666">'
               . s(get_string('token_count_label', 'local_dreamu_ai')) . ' '
               . '<span id="' . $tokenid . '" style="font-weight:600">0</span>'
               . '</div>'
               . '<script>' . $tokenjs . '</script>';
    $mform->addElement('static', 'dreamu_ai_tokencount', '', $tokenhtml);
    $mform->hideIf('dreamu_ai_tokencount', 'dreamu_ai_enabled', 'notchecked');

    // Elaborate prompt examples panel — helps teachers write calibrated grading instructions.
    $examples = [
        'code'     => ['title' => get_string('example_code_title', 'local_dreamu_ai'),     'prompt' => get_string('example_code', 'local_dreamu_ai')],
        'essay'    => ['title' => get_string('example_essay_title', 'local_dreamu_ai'),    'prompt' => get_string('example_essay', 'local_dreamu_ai')],
        'lab'      => ['title' => get_string('example_lab_title', 'local_dreamu_ai'),      'prompt' => get_string('example_lab', 'local_dreamu_ai')],
        'math'     => ['title' => get_string('example_math_title', 'local_dreamu_ai'),     'prompt' => get_string('example_math', 'local_dreamu_ai')],
        'analysis' => ['title' => get_string('example_analysis_title', 'local_dreamu_ai'), 'prompt' => get_string('example_analysis', 'local_dreamu_ai')],
    ];
    $html  = '<details class="dreamu-prompt-examples" style="margin:.5em 0 1em;background:#f8f9fa;border:1px solid #dee2e6;border-radius:4px;padding:.75em">';
    $html .= '<summary style="cursor:pointer;font-weight:600;color:#0066cc">' . s(get_string('show_examples', 'local_dreamu_ai')) . '</summary>';
    $html .= '<p style="margin:.75em 0;font-size:.9em;color:#555">' . s(get_string('show_examples_intro', 'local_dreamu_ai')) . '</p>';
    foreach ($examples as $key => $ex) {
        $payload = json_encode($ex['prompt'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_APOS);
        $onclick = "var t=document.querySelector('textarea[name=dreamu_ai_prompt]');if(t){t.value={$payload};t.dispatchEvent(new Event('change'));t.focus();}return false;";
        $html .= '<div style="margin:1em 0;padding:.5em;border:1px solid #ccc;background:white;border-radius:3px">';
        $html .= '<div style="display:flex;justify-content:space-between;align-items:center;gap:1em;margin-bottom:.4em">';
        $html .= '<strong>' . s($ex['title']) . '</strong>';
        $html .= '<button type="button" class="btn btn-sm btn-outline-primary" onclick="' . s($onclick) . '">'
              .  s(get_string('use_template', 'local_dreamu_ai')) . '</button>';
        $html .= '</div>';
        $html .= '<pre style="white-space:pre-wrap;font-size:.78em;max-height:220px;overflow:auto;margin:0;background:#fafafa;border:none;padding:.4em">'
              .  s($ex['prompt']) . '</pre>';
        $html .= '</div>';
    }
    $html .= '</details>';
    $mform->addElement('static', 'dreamu_ai_examples', '', $html);
    $mform->hideIf('dreamu_ai_examples', 'dreamu_ai_enabled', 'notchecked');

    $mform->addElement('text', 'dreamu_ai_maxgrade',
        get_string('max_grade', 'local_dreamu_ai'));
    $mform->setType('dreamu_ai_maxgrade', PARAM_FLOAT);
    $mform->setDefault('dreamu_ai_maxgrade', $config->maxgrade ?? 20);
    $mform->hideIf('dreamu_ai_maxgrade', 'dreamu_ai_enabled', 'notchecked');

    $mform->addElement('select', 'dreamu_ai_language',
        get_string('language', 'local_dreamu_ai'),
        [
            'fr' => get_string('language_fr', 'local_dreamu_ai'),
            'en' => get_string('language_en', 'local_dreamu_ai'),
        ]);
    $mform->setDefault('dreamu_ai_language', $config->language ?? 'fr');
    $mform->hideIf('dreamu_ai_language', 'dreamu_ai_enabled', 'notchecked');
}

/**
 * Save AI grading config when the assignment form is submitted.
 *
 * @param stdClass $data
 * @param stdClass $course
 * @return stdClass
 */
function local_dreamu_ai_coursemodule_edit_post_actions($data, $course) {
    global $DB;

    if (($data->modulename ?? '') !== 'assign') {
        return $data;
    }

    $assignid = $data->instance;

    $config = $DB->get_record('local_dreamu_ai_config', ['assignid' => $assignid]);
    $isnew = !$config;

    if ($isnew) {
        $config = new \stdClass();
        $config->assignid = $assignid;
        $config->timecreated = time();
    }

    $config->enabled = !empty($data->dreamu_ai_enabled) ? 1 : 0;
    $config->prompt = $data->dreamu_ai_prompt ?? '';
    $config->maxgrade = !empty($data->dreamu_ai_maxgrade) ? floatval($data->dreamu_ai_maxgrade) : 20;
    $config->language = $data->dreamu_ai_language ?? 'fr';
    $config->timemodified = time();

    if ($isnew) {
        $DB->insert_record('local_dreamu_ai_config', $config);
    } else {
        $DB->update_record('local_dreamu_ai_config', $config);
    }

    return $data;
}

/**
 * Add AI Dashboard link to course navigation.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_dreamu_ai_extend_navigation_course($navigation, $course, $context) {
    if (has_capability('local/dreamu_ai:grade', $context)) {
        $url = new moodle_url('/local/dreamu_ai/dashboard.php', ['courseid' => $course->id]);
        $navigation->add(
            'Dashboard IA',
            $url,
            navigation_node::TYPE_CUSTOM,
            null,
            'dreamu_ai_dashboard',
            new pix_icon('i/report', '')
        );
    }
}
