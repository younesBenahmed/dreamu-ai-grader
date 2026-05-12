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
 * Plugin settings.
 *
 * @package    local_dreamu_ai
 * @copyright  2026 Dream-U / AMU / IUT Aix-en-Provence
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_dreamu_ai', get_string('pluginname', 'local_dreamu_ai'));

    $settings->add(new admin_setting_heading(
        'local_dreamu_ai/heading_api',
        'Configuration IA',
        'Ces parametres sont partages avec le plugin QCM Generator. Modifiez-les ici pour les deux plugins.'
    ));

    $settings->add(new admin_setting_configtext(
        'local_dreamu_ai/api_endpoint',
        'Endpoint API',
        'URL de l\'API compatible OpenAI (ex: http://100.76.166.71:8200/v1/chat/completions)',
        'http://100.76.166.71:8200/v1/chat/completions',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_dreamu_ai/api_key',
        'Cle API',
        'Cle d\'authentification (sk-dummy pour vLLM local)',
        'sk-dummy',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_dreamu_ai/model_name',
        'Nom du modele',
        'Le served-model-name du modele vLLM (ex: hal-9001-code, general)',
        'hal-9001-code',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_heading(
        'local_dreamu_ai/heading_test',
        'Test de connexion',
        'Allez sur <a href="../local/dreamu_ai/test_api.php">cette page</a> pour tester la connexion a l\'IA.'
    ));

    $ADMIN->add('localplugins', $settings);
}
