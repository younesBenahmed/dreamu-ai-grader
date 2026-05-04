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
 * Language strings for local_dreamu_ai.
 *
 * @package    local_dreamu_ai
 * @copyright  2026 Dream-U / AMU / IUT Aix-en-Provence
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Dream-U AI Grader';
$string['privacy:metadata'] = 'The Dream-U AI Grader plugin sends student submission text to an AI service for grading.';

// Settings.
$string['enable_ai_grading'] = 'Enable AI grading';
$string['enable_ai_grading_help'] = 'When enabled, a button will appear to grade all submissions using AI.';
$string['grading_prompt'] = 'Grading instructions';
$string['grading_prompt_help'] = 'Provide detailed grading criteria and instructions for the AI. Be specific about what to evaluate, the grading scale, and how to provide feedback.';
$string['grading_prompt_default'] = 'You are a university teaching assistant. Grade the following student submission based on the criteria below. Provide a numerical grade and detailed feedback.';
$string['max_grade'] = 'Maximum grade';
$string['language'] = 'Feedback language';
$string['language_fr'] = 'French';
$string['language_en'] = 'English';

// Navigation and UI.
$string['ai_grade_all'] = 'AI Grade All';
$string['ai_grade_history'] = 'AI Grading History';
$string['validate_grades'] = 'Validate AI Grades';
$string['grade_submissions'] = 'Grade submissions with AI';
$string['confirm_grade_all'] = 'Are you sure you want to grade all submitted assignments using AI? Grades will need your validation before being applied.';
$string['grading_started'] = 'AI grading has been queued. You will be notified when it completes so you can review and validate the grades.';
$string['no_submissions'] = 'No submissions found to grade.';

// Task.
$string['task_grade_submissions'] = 'AI grade submissions';

// Results and notifications.
$string['grading_complete'] = 'AI grading complete: {$a->graded} submissions graded, {$a->errors} errors.';
$string['grading_complete_subject'] = 'AI grading complete';
$string['grading_complete_html'] = '<p>AI grading is complete for <strong>{$a->assignname}</strong>.</p><p>{$a->graded} submissions graded, {$a->errors} errors.</p><p><a href="{$a->validateurl}">Click here to review and validate the grades</a></p>';
$string['grading_error'] = 'Error grading submission for user {$a->userid}: {$a->error}';
$string['messageprovider:grading_complete'] = 'AI grading complete notification';

// Validation workflow.
$string['pending_validation'] = 'Pending teacher validation';
$string['processed_grades'] = 'Processed grades';
$string['ai_suggested_grade'] = 'AI suggested grade';
$string['ai_feedback'] = 'AI feedback';
$string['approve_grade'] = 'Approve & apply grade';
$string['reject_grade'] = 'Reject';
$string['approve_all'] = 'Approve all grades';
$string['confirm_approve_all'] = 'Apply all AI grades to the gradebook? This action cannot be undone.';
$string['confirm_reject'] = 'Reject this AI grade? The student will not receive this grade.';
$string['grade_approved'] = 'Grade approved and applied to gradebook.';
$string['grade_rejected'] = 'Grade rejected.';
$string['all_grades_approved'] = '{$a} grades approved and applied to gradebook.';
$string['no_ai_grades'] = 'No AI grading results found. Use "AI Grade All" to start.';

// Status labels.
$string['status_validated'] = 'Validated';
$string['status_rejected'] = 'Rejected';
$string['status_error'] = 'Error';
$string['status_pending'] = 'Pending';
$string['status_graded'] = 'Graded (awaiting validation)';

// Capabilities.
$string['dreamu_ai:grade'] = 'Grade assignments using AI';
$string['dreamu_ai:configure'] = 'Configure AI grading settings';

// Settings page.
$string['settings_heading'] = 'Dream-U AI Grader Settings';
$string['api_endpoint'] = 'vLLM API endpoint';
$string['api_endpoint_desc'] = 'The OpenAI-compatible API endpoint for vLLM (e.g., http://100.76.166.71:8102/v1/chat/completions)';
$string['api_key'] = 'API key';
$string['api_key_desc'] = 'API key for the LLM service (use sk-dummy for vLLM)';
$string['model_name'] = 'Model name';
$string['model_name_desc'] = 'The model to use for grading (e.g., general)';
