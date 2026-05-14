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
$string['grading_prompt_help'] = 'A strong prompt contains 4 elements:

1. **A weighted rubric**: state the weight of each criterion (e.g. "Functionality 40%, error handling 20%, readability 15%, structure 15%, documentation 10%").

2. **Score anchors**: describe what each grade range represents (e.g. "0-3 = off-topic; 4-7 = critical bugs; 12-15 = correct; 18-20 = excellent").

3. **Automatic penalties and bonuses**: list typical faults and their point cost (e.g. "critical bug -3 pts, no README -1 pt, unit tests +2 pts bonus").

4. **The expected response format**: explicitly ask for the score breakdown and citations of specific elements (function names, paragraphs, reasoning steps).

Without these elements, the AI tends to cluster grades around the mean (10-14) and produce generic feedback. Click "Show examples" below this field to load 5 elaborated templates that you can insert with one click and adapt.

**Available variables** in the prompt (auto-substituted at grading time):
- `{maxgrade}`: maximum grade for the assignment
- `{assignname}`: assignment name
- `{coursename}`: course name
- `{language}`: feedback language (français / english)
- `{duedate}`: assignment due date';
$string['grading_prompt_default'] = 'You are a university teaching assistant. Grade the following student submission based on the criteria below. Provide a numerical grade and detailed feedback.';

// Prompt examples panel.
$string['show_examples'] = 'Show elaborated prompt examples (5 templates)';
$string['show_examples_intro'] = 'Click "Use this template" to pre-fill the field above. Adapt the topic, rubric and penalties to your specific assignment.';
$string['use_template'] = 'Use this template';
$string['token_count_label'] = 'Estimated prompt length:';

$string['example_code_title'] = '1. Programming assignment (code submission)';
$string['example_code'] = 'You are grading a programming assignment out of {maxgrade} points. You MUST use the full 0-{maxgrade} scale.

WEIGHTED RUBRIC ({maxgrade} pts total):
- Functionality / algorithmic correctness: 40%
- Error handling and edge cases: 20%
- Readability (naming, indentation, clarity): 15%
- Structure (functions, classes, separation of concerns): 15%
- Documentation (docstrings, comments, README, tests): 10%

SCORE ANCHORS (follow STRICTLY):
- 0-3  : Empty code, off-topic, does not compile / does not run at all
- 4-7  : Critical bugs (wrong output, missing return, crash on standard input), no docs
- 8-11 : Works on normal cases but no error handling, messy code
- 12-15: Works + basic error handling, clean code, light documentation
- 16-18: Well structured (classes/modules), complete error handling (exceptions), partial docs
- 19-20: Clean design + unit tests + docstrings + full README + edge cases handled

AUTOMATIC PENALTIES / BONUSES:
- Logic bug in a main function: -3 pts per bug
- Crash on common edge case (division by zero, empty list, missing file): -2 pts
- No docstring on public functions: -1 pt
- No README.md: -1 pt
- Working unit tests present: +2 pts BONUS
- Off-topic (does not address the brief): grade CAPPED at 3/{maxgrade}

RESPONSE FORMAT:
1. Final grade (half-point allowed)
2. Per-criterion breakdown
3. Feedback quoting the exact names of defective functions and variables';

$string['example_essay_title'] = '2. Essay / written paper (literature, philosophy, humanities)';
$string['example_essay'] = 'You are grading the essay "{assignname}" out of {maxgrade} points. You MUST use the full 0-{maxgrade} scale.

WEIGHTED RUBRIC:
- Understanding of the topic and problem statement: 25%
- Argument structure (intro, plan, transitions, conclusion): 25%
- Quality of arguments and examples (relevance, depth): 25%
- Writing quality (grammar, spelling, style): 15%
- References and citations properly integrated: 10%

SCORE ANCHORS:
- 0-4  : Completely off-topic, nearly empty paper, or obvious plagiarism
- 5-8  : Topic misunderstood, no plan, many errors, no argumentation
- 9-11 : Topic partially understood, implicit plan, weak arguments, errors
- 12-14: Topic well understood, clear plan, correct but shallow arguments
- 15-17: Strong problem statement, structured plan, solid arguments with examples
- 18-20: Sharp problem statement, original argumentation, mastered references, polished style

PENALTIES:
- No visible plan (no structured paragraphs): -2 pts
- More than 15 spelling/grammar errors: -2 pts
- No conclusion: -1 pt
- No concrete example: -2 pts
- Suspected plagiarism (flag explicitly in feedback): grade CAPPED at 3/{maxgrade}

RESPONSE FORMAT:
1. Final grade
2. Implicit plan identified (e.g., "I. ... ; II. ... ; III. ...")
3. Three strengths cited precisely
4. Three targeted improvement areas';

$string['example_lab_title'] = '3. Lab report (physics, chemistry, biology)';
$string['example_lab'] = 'You are grading a lab report out of {maxgrade} points. You MUST use the full 0-{maxgrade} scale.

WEIGHTED RUBRIC:
- Protocol and setup description: 15%
- Experimental results (tables, values, units): 25%
- Analysis and exploitation (uncertainty, graphs, modeling): 25%
- Discussion / physical-chemical-biological interpretation: 20%
- Conclusion answering the lab problem: 10%
- General presentation (numbered figures, captions, cleanliness): 5%

SCORE ANCHORS:
- 0-4  : Report missing or does not describe the lab actually performed
- 5-8  : Raw results with no analysis, no units, no discussion
- 9-11 : Results present but superficial analysis, little reasoning
- 12-14: Correct analysis, accurate calculations, summary discussion
- 15-17: Good exploitation, uncertainty calculated, relevant interpretation
- 18-20: Rigorous analysis, modeling, critical discussion, opening

PENALTIES:
- No physical units on values: -2 pts
- No uncertainty calculation: -2 pts
- Figures not numbered or no caption: -1 pt
- No comparison to theory or tabulated value: -1 pt
- Conclusion missing or off-topic: -2 pts

RESPONSE FORMAT:
1. Final grade
2. Score per rubric section
3. Three concrete improvement areas, citing the relevant paragraphs/figures';

$string['example_math_title'] = '4. Mathematics exercise with proof';
$string['example_math'] = 'You are grading a mathematics exercise out of {maxgrade} points. You MUST use the full 0-{maxgrade} scale.

WEIGHTED RUBRIC:
- Justification of each step (theorems cited, hypotheses verified): 30%
- Rigor of reasoning (logic, sequencing, no jumps): 30%
- Correctness of the final result: 20%
- Correct intermediate calculations: 15%
- Writing quality (standard notation, quantifiers, conclusion): 5%

SCORE ANCHORS:
- 0-3  : No approach, or completely wrong reasoning
- 4-7  : Some correct openings but major undetected errors
- 8-11 : Partial approach, several missing steps, wrong result
- 12-14: Valid approach but hypotheses not verified or calculation errors
- 15-17: Rigorous reasoning, hypotheses cited, minor calculation error
- 18-20: Flawless proof, complete justifications, correct result

PENALTIES:
- Correct result with no justification: -5 pts (the result alone is not enough)
- Hypothesis not verified before applying a theorem: -2 pts per occurrence
- Propagated calculation error: -1 pt
- Non-standard or ambiguous notation: -1 pt

NOTE: a correct result obtained by chance without a valid approach should receive a LOW grade. A correct approach with a minor calculation error should keep an HONORABLE grade.

RESPONSE FORMAT:
1. Final grade
2. Status of each step (correct / faulty / missing)
3. Explicit correction of the FIRST error found';

$string['example_analysis_title'] = '5. Case study / document analysis (econ, management, law, geography)';
$string['example_analysis'] = 'You are grading a case study or document analysis out of {maxgrade} points. You MUST use the full 0-{maxgrade} scale.

WEIGHTED RUBRIC:
- Identification of the case / documents stakes: 20%
- Use of concepts/notions from the course: 25%
- Critical analysis (no paraphrase, perspective taken): 25%
- Articulation between documents (if multiple): 15%
- Conclusion / recommendation / answer to the problem: 10%
- Presentation and clarity: 5%

SCORE ANCHORS:
- 0-4  : Full paraphrase, no personal contribution
- 5-8  : Stakes misidentified, course concepts absent, flat analysis
- 9-11 : Stakes identified but superficial analysis, few concepts mobilized
- 12-14: Good identification, several concepts used, correct analysis
- 15-17: Fine analysis, concepts applied correctly, critical perspective
- 18-20: Original analysis, concepts mastered and cross-referenced, argued recommendations

PENALTIES:
- More than 50% paraphrase of the documents: grade CAPPED at 8/{maxgrade}
- No course concept explicitly cited: -3 pts
- No conclusion: -2 pts
- No source / reference (if expected by the brief): -1 pt

RESPONSE FORMAT:
1. Final grade
2. Two-line summary of the student\'s analysis
3. Three strengths + three weaknesses cited with text excerpts
4. Pedagogical recommendation for the next assignment';
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
