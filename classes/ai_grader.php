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
 * AI Grader — calls vLLM to grade a single submission.
 * Supports any language/content type with auto-detection.
 *
 * @package    local_dreamu_ai
 * @copyright  2026 Dream-U / AMU / IUT Aix-en-Provence
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_dreamu_ai;

defined('MOODLE_INTERNAL') || die();

class ai_grader {

    /** @var string API endpoint URL */
    private string $endpoint;

    /** @var string API key */
    private string $apikey;

    /** @var string Model name */
    private string $model;

    public function __construct() {
        $this->endpoint = get_config('local_dreamu_ai', 'api_endpoint')
            ?: 'http://100.76.166.71:8200/v1/chat/completions';
        $this->apikey = get_config('local_dreamu_ai', 'api_key') ?: 'dummy';
        $this->model = get_config('local_dreamu_ai', 'model_name') ?: 'hal-9001-chat';
    }

    /**
     * Detect the dominant language/content type from submission text.
     *
     * Scans for "--- File: xxx.ext ---" patterns and determines the dominant language.
     * Returns a human-readable label like "Python", "Java", "C++", etc.
     *
     * @param string $submissiontext The submission content
     * @return string The detected language/type label
     */
    private function detect_content_type(string $submissiontext): string {
        $ext_to_lang = [
            'py' => 'Python',
            'java' => 'Java',
            'c' => 'C',
            'cpp' => 'C++',
            'h' => 'C/C++',
            'hpp' => 'C++',
            'cs' => 'C#',
            'js' => 'JavaScript',
            'ts' => 'TypeScript',
            'html' => 'HTML',
            'css' => 'CSS',
            'php' => 'PHP',
            'rb' => 'Ruby',
            'go' => 'Go',
            'rs' => 'Rust',
            'sql' => 'SQL',
            'sh' => 'Shell/Bash',
            'bash' => 'Shell/Bash',
            'r' => 'R',
            'R' => 'R',
            'tex' => 'LaTeX',
            'ipynb' => 'Jupyter/Python',
            'json' => 'JSON',
            'xml' => 'XML',
            'yaml' => 'YAML',
            'yml' => 'YAML',
            'toml' => 'TOML',
            'md' => 'Markdown',
            'txt' => 'Text',
            'csv' => 'CSV',
            'ini' => 'INI',
        ];

        // Find all file extensions in "--- File: xxx.ext ---" patterns
        $counts = [];
        if (preg_match_all('/--- File(?:\s*\(in zip\))?: .+\.(\w+) ---/', $submissiontext, $matches)) {
            foreach ($matches[1] as $ext) {
                $ext_lower = strtolower($ext);
                $lang = $ext_to_lang[$ext_lower] ?? strtoupper($ext_lower);
                if (!isset($counts[$lang])) {
                    $counts[$lang] = 0;
                }
                $counts[$lang]++;
            }
        }

        if (empty($counts)) {
            return 'text/essay';
        }

        // Return the dominant language
        arsort($counts);
        return array_key_first($counts);
    }

    /**
     * Grade a single submission using the AI.
     *
     * @param string $submissiontext The student's submission content
     * @param string $prompt The grading instructions from the teacher
     * @param float $maxgrade The maximum grade for this assignment
     * @param string $language The language for feedback (fr/en)
     * @return object Object with ->grade (float) and ->feedback (string)
     * @throws \moodle_exception If the API call fails or response is unparseable
     */
    public function grade_submission(string $submissiontext, string $prompt, float $maxgrade, string $language = 'fr'): object {
        $langname = ($language === 'fr') ? 'French' : 'English';

        // Limit input size to fit in model context window.
        // 6000 chars ~ 1500 tokens, leaves room for system prompt + response.
        $maxchars = 6000;
        if (strlen($submissiontext) > $maxchars) {
            $submissiontext = substr($submissiontext, 0, $maxchars) . "\n[... TRUNCATED ...]";
        }

        // Auto-detect the content type / programming language
        $contenttype = $this->detect_content_type($submissiontext);
        $is_code = !in_array($contenttype, ['text/essay', 'Markdown', 'Text', 'CSV', 'LaTeX']);

        if ($is_code) {
            $type_label = $contenttype . " code";
            $review_focus = "1. Cite SPECIFIC function/class names and explain issues\n"
                . "2. Point out SPECIFIC bugs with variable names and logic errors\n"
                . "3. Comment on code style, missing comments, poor structure\n"
                . "4. Check error handling for edge cases\n"
                . "5. Check for compilation/syntax errors";
        } else {
            $type_label = "written submission";
            $review_focus = "1. Evaluate the clarity and coherence of the arguments\n"
                . "2. Check for factual accuracy and depth of analysis\n"
                . "3. Assess the structure and organization\n"
                . "4. Note grammar, spelling, and formatting issues\n"
                . "5. Evaluate whether the prompt/requirements are fully addressed";
        }

        // === PASS 1: Read and understand ===
        $system1 = "You are a {$contenttype} reviewer. Read the student submission carefully.\n"
            . "IMPORTANT: Only describe what is ACTUALLY in the submission. Do NOT invent or assume content.\n"
            . "List ALL files/sections/exercises found. Describe what each one does in 2-3 sentences.\n"
            . "If the submission mentions diagrams, tables, or visual elements, note them.\n"
            . "Respond in {$langname}.";

        try {
            $analysis = $this->call_api($system1, "Student submission:\n\n{$submissiontext}");
        } catch (\Exception $e) {
            $analysis = "Analysis failed: " . $e->getMessage();
        }

        // === PASS 2: Exhaustive review with strict verification ===
        $system2 = "You are a university professor performing a RIGOROUS academic review of a student's {$type_label}.\n\n"
            . "CRITICAL: You MUST evaluate ONLY what is in the grading criteria. If the criteria mention 3 exercises, review exactly 3 exercises. Do NOT invent additional exercises.\n"
            . "CRITICAL: Evaluate ONLY the content that is ACTUALLY in the student's submission. Do NOT hallucinate or assume content that is not there.\n"
            . "CRITICAL: Use ONLY the terminology and concepts from the grading criteria. If the criteria mention diagrams, evaluate diagrams. If they mention calculations, evaluate calculations. Do NOT reference topics not in the criteria.\n\n"
            . "YOUR TASK: Go through EACH exercise/question in the grading criteria, one by one, and evaluate the student's answer.\n\n"
            . "FOR EACH EXERCISE, you MUST write:\n"
            . "### Exercise N: [title from the grading criteria]\n"
            . "- STATUS: DONE / PARTIALLY DONE / NOT DONE / MISSING\n"
            . "- METHOD: Describe what method the student used (or 'no method shown')\n"
            . "- VERIFICATION: Redo the calculation yourself. State the correct answer. Compare with student's answer.\n"
            . "- ERRORS: List every specific error (wrong value, missing step, logical flaw, no justification)\n"
            . "- QUALITY: Comment on clarity, rigor, presentation\n\n"
            . "THEN provide a general assessment:\n"
            . "{$review_focus}\n\n"
            . "ABSOLUTE RULES:\n"
            . "- You MUST independently verify every numerical result. Do the math yourself.\n"
            . "- If the student wrote 'I don't know' or left an exercise blank, write 'NOT DONE'.\n"
            . "- If the student gives a result without showing work, write 'result without justification'.\n"
            . "- If you cannot find an exercise in the submission, write 'MISSING from submission'.\n"
            . "- Do NOT hallucinate. Do NOT credit work that is not present. Only evaluate what is ACTUALLY written.\n"
            . "- Be EXHAUSTIVE. A review that misses errors is worse than one that is too strict.\n"
            . "Respond in {$langname}.";

        $user2 = "Analysis:\n{$analysis}\n\nGrading criteria:\n{$prompt}\n\nStudent submission:\n{$submissiontext}";
        if (strlen($user2) > 6000) {
            $user2 = substr($user2, 0, 6000) . "\n[... truncated ...]";
        }

        try {
            $qwen_review = $this->call_api($system2, $user2, 0.2, 3000);
        } catch (\Exception $e) {
            $qwen_review = "Review failed: " . $e->getMessage();
        }

        // === PASS 3: Per-criterion grading with structured JSON feedback ===
        $scale = $this->build_grade_scale($maxgrade);
        $system3 = "You are a STRICT university grader. You MUST grade each criterion SEPARATELY, then sum to get the total.\n\n"
            . "RESPOND ONLY with valid JSON in this EXACT format:\n"
            . "{\"criteria_grades\": [{\"name\": \"criterion name\", \"max\": MAX_POINTS, \"score\": POINTS_GIVEN, \"justification\": \"why this score\"}], \"grade\": TOTAL, \"points_forts\": [\"...\"], \"erreurs\": [\"...\"], \"suggestions\": [\"...\"], \"commentaire\": \"...\"}\n\n"
            . "MANDATORY RULES:\n"
            . "- The 'grade' field MUST equal the SUM of all 'score' values in criteria_grades.\n"
            . "- Each criterion score MUST be between 0 and its max value.\n"
            . "- For each criterion, explain in 'justification' what the student did or did not do.\n"
            . "- NOT ATTEMPTED = 0 points. No exceptions.\n"
            . "- CORRECT METHOD + WRONG ANSWER = 50-70% of max for that criterion.\n"
            . "- CORRECT ANSWER + NO JUSTIFICATION = 30-50% of max.\n"
            . "- PERFECT = full points ONLY if method, result AND justification are all correct.\n"
            . "- Calculation error = deduct 0.5-1 point per error.\n"
            . "- Do NOT credit work that is NOT in the submission. Check your review carefully.\n"
            . "- points_forts, erreurs, suggestions: at least 2 items each. Be specific. In {$langname}.\n"
            . "RESPOND ONLY WITH THE JSON.";

        // Pass 3 is critical -- if it fails, the whole grading fails.
        $qwen_response = $this->call_api($system3,
            "Grading criteria:\n{$prompt}\n\nMax grade: {$maxgrade}\n\nDetailed review:\n{$qwen_review}\n\nJSON:",
            0.1, 4000);
        $qwen_result = $this->parse_structured_response($qwen_response, $maxgrade);

        // === PASS 4: BLIND independent counter-review (does NOT see pass 3 grade) ===
        $counter_system = "You are an independent STRICT examiner grading a student submission from scratch.\n"
            . "You have NOT seen any previous grade or review. Form your OWN opinion.\n\n"
            . "RULES:\n"
            . "- Grade ONLY based on the criteria provided and the actual submission content.\n"
            . "- An exercise not done = 0 points. An exercise partially done = partial credit.\n"
            . "- Verify all calculations independently. If a result is wrong, deduct points.\n"
            . "- A correct answer without proof/justification = max 50% for that exercise.\n"
            . "- Do NOT be generous. University standards: average work = 10-12/{$maxgrade}.\n"
            . "- Respond ONLY in JSON: {\"grade\": NUMBER, \"feedback\": \"TEXT\"}\n"
            . "- Grade 0-{$maxgrade}. Feedback in {$langname}. Cite specific elements.";

        $counter_user = "Grading criteria:\n{$prompt}\n\nMax grade: {$maxgrade}\n\n"
            . "Student submission:\n" . substr($submissiontext, 0, 5000)
            . "\n\nYour independent JSON grade:";

        try {
            $counter_response = $this->call_api($counter_system, $counter_user, 0.1, 3000);
            $ds_result = $this->parse_response($counter_response, $maxgrade);
        } catch (\Exception $e) {
            // If counter-review fails, use only first grade
            $ds_result = (object)['grade' => $qwen_result->grade, 'feedback' => 'Contre-correction indisponible'];
        }

        // === PASS 5: Decisional arbitration when grades diverge ===
        $grade_gap = abs($qwen_result->grade - $ds_result->grade);
        $gap_threshold = $maxgrade * 0.15; // 15% of maxgrade (e.g. 3 pts on /20)

        if ($grade_gap > $gap_threshold) {
            // Significant disagreement — ask a 5th pass to arbitrate.
            $arb_system = "You are the HEAD EXAMINER making the FINAL decision. Two independent reviewers graded the same work and DISAGREE.\n"
                . "Reviewer A gave {$qwen_result->grade}/{$maxgrade}. Reviewer B gave {$ds_result->grade}/{$maxgrade}. Gap: {$grade_gap} points.\n\n"
                . "You MUST:\n"
                . "1. Read BOTH justifications carefully\n"
                . "2. Identify which reviewer is MORE accurate based on the ACTUAL submission content\n"
                . "3. Decide a FINAL grade — you may side with either reviewer or choose a different grade entirely\n"
                . "4. Explain WHY you chose this grade in 2-3 sentences\n\n"
                . "Respond ONLY in JSON: {\"grade\": NUMBER, \"reasoning\": \"TEXT\"}\n"
                . "Grade 0-{$maxgrade}. In {$langname}.";

            $arb_user = "Grading criteria:\n{$prompt}\n\nMax: {$maxgrade}\n\n"
                . "=== REVIEWER A (grade: {$qwen_result->grade}) ===\n"
                . "Points forts: " . implode(', ', $qwen_result->points_forts ?? []) . "\n"
                . "Erreurs: " . implode(', ', $qwen_result->erreurs ?? []) . "\n"
                . "Commentaire: " . ($qwen_result->commentaire ?? '') . "\n\n"
                . "=== REVIEWER B (grade: {$ds_result->grade}) ===\n"
                . substr($ds_result->feedback ?? '', 0, 2000) . "\n\n"
                . "=== STUDENT SUBMISSION (excerpt) ===\n"
                . substr($submissiontext, 0, 4000) . "\n\n"
                . "Your FINAL arbitrated JSON grade:";

            try {
                $arb_response = $this->call_api($arb_system, $arb_user, 0.1, 2000);
                $arb_json = $arb_response;
                if (preg_match('/\{[\s\S]*\}/s', $arb_response, $m)) {
                    $arb_json = $m[0];
                }
                $arb_data = json_decode($arb_json);
                if ($arb_data && isset($arb_data->grade)) {
                    $final_grade = max(0, min($maxgrade, floatval($arb_data->grade)));
                    $arb_reasoning = $arb_data->reasoning ?? '';
                } else {
                    // Arbitration parsing failed — fall back to weighted average favoring pass 3.
                    $final_grade = round(($qwen_result->grade * 0.6 + $ds_result->grade * 0.4), 2);
                    $arb_reasoning = '';
                }
            } catch (\Exception $e) {
                // Arbitration call failed — weighted average.
                $final_grade = round(($qwen_result->grade * 0.6 + $ds_result->grade * 0.4), 2);
                $arb_reasoning = '';
            }
        } else {
            // Grades are close — simple average is fine.
            $final_grade = round(($qwen_result->grade + $ds_result->grade) / 2, 2);
            $arb_reasoning = '';
        }

        $final_grade = max(0, min($maxgrade, $final_grade));

        // === CALIBRATION: detect inconsistencies between feedback content and grade ===
        $calibration = $this->calibrate_grade($final_grade, $maxgrade, $qwen_result, $ds_result, $qwen_review);
        $final_grade = $calibration['grade'];
        $calibration_note = $calibration['note'];

        $final_feedback = $this->build_html_feedback(
            $qwen_result,
            $ds_result,
            $qwen_review,
            $final_grade,
            $maxgrade,
            $contenttype,
            $arb_reasoning,
            $calibration_note
        );

        $result = new \stdClass();
        $result->grade = $final_grade;
        $result->feedback = $final_feedback;

        return $result;
    }

    /**
     * Build structured HTML feedback with colored sections.
     */
    private function build_html_feedback(object $qwen, object $ds, string $detailed_review, float $final_grade, float $maxgrade, string $contenttype, string $arb_reasoning = '', string $calibration_note = ''): string {
        $html = '<div style="font-family: sans-serif; max-width: 800px;">';

        // AI disclosure banner.
        $html .= '<div style="background: #e3f2fd; border: 1px solid #90caf9; padding: 10px 14px; border-radius: 4px; margin-bottom: 16px; font-size: 0.85em; color: #1565c0;">';
        $html .= '<strong>Information :</strong> Cette correction a &eacute;t&eacute; assist&eacute;e par intelligence artificielle '
            . 'et valid&eacute;e par votre enseignant(e). Si vous souhaitez des pr&eacute;cisions ou contester un point, '
            . 'contactez directement votre enseignant(e).';
        $html .= '</div>';

        // Header with grade summary
        $grade_pct = ($maxgrade > 0) ? ($final_grade / $maxgrade) * 100 : 0;
        if ($grade_pct >= 70) {
            $grade_color = '#28a745';
        } elseif ($grade_pct >= 50) {
            $grade_color = '#ffc107';
        } else {
            $grade_color = '#dc3545';
        }

        $html .= '<div style="background: ' . $grade_color . '22; border-left: 4px solid ' . $grade_color . '; padding: 12px 16px; margin-bottom: 16px; border-radius: 4px;">';
        $html .= '<strong style="font-size: 1.2em;">Note : ' . $final_grade . '/' . $maxgrade . '</strong>';
        $html .= '</div>';

        // Per-criterion breakdown table.
        if (!empty($qwen->criteria_grades)) {
            $html .= '<div style="margin-bottom: 16px;">';
            $html .= '<strong>D&eacute;tail par crit&egrave;re :</strong>';
            $html .= '<table style="width:100%; border-collapse:collapse; margin-top:8px; font-size:0.9em;">';
            $html .= '<tr style="background:#f0f0f0;"><th style="text-align:left; padding:6px 10px; border:1px solid #ddd;">Crit&egrave;re</th>'
                . '<th style="width:80px; text-align:center; padding:6px; border:1px solid #ddd;">Score</th>'
                . '<th style="text-align:left; padding:6px 10px; border:1px solid #ddd;">Justification</th></tr>';
            foreach ($qwen->criteria_grades as $cg) {
                $cg = (object)$cg;
                $cscore = floatval($cg->score ?? 0);
                $cmax = floatval($cg->max ?? 0);
                $cpct = $cmax > 0 ? $cscore / $cmax : 0;
                $ccolor = $cpct >= 0.7 ? '#28a745' : ($cpct >= 0.4 ? '#ffc107' : '#dc3545');
                $html .= '<tr>';
                $html .= '<td style="padding:6px 10px; border:1px solid #ddd;">' . htmlspecialchars($cg->name ?? '') . '</td>';
                $html .= '<td style="text-align:center; padding:6px; border:1px solid #ddd; font-weight:bold; color:' . $ccolor . ';">' . $cscore . '/' . $cmax . '</td>';
                $html .= '<td style="padding:6px 10px; border:1px solid #ddd; font-size:0.9em;">' . htmlspecialchars($cg->justification ?? '') . '</td>';
                $html .= '</tr>';
            }
            $html .= '</table></div>';
        }

        // Points forts (green)
        if (!empty($qwen->points_forts)) {
            $html .= '<div style="background: #d4edda; border-left: 4px solid #28a745; padding: 10px 14px; margin-bottom: 12px; border-radius: 4px;">';
            $html .= '<strong style="color: #155724;">Points forts</strong><ul style="margin: 6px 0 0 0; padding-left: 20px;">';
            foreach ($qwen->points_forts as $point) {
                $html .= '<li>' . htmlspecialchars($point) . '</li>';
            }
            $html .= '</ul></div>';
        }

        // Erreurs (red)
        if (!empty($qwen->erreurs)) {
            $html .= '<div style="background: #f8d7da; border-left: 4px solid #dc3545; padding: 10px 14px; margin-bottom: 12px; border-radius: 4px;">';
            $html .= '<strong style="color: #721c24;">Erreurs</strong><ul style="margin: 6px 0 0 0; padding-left: 20px;">';
            foreach ($qwen->erreurs as $err) {
                $html .= '<li>' . htmlspecialchars($err) . '</li>';
            }
            $html .= '</ul></div>';
        }

        // Suggestions (blue)
        if (!empty($qwen->suggestions)) {
            $html .= '<div style="background: #d1ecf1; border-left: 4px solid #17a2b8; padding: 10px 14px; margin-bottom: 12px; border-radius: 4px;">';
            $html .= '<strong style="color: #0c5460;">Suggestions</strong><ul style="margin: 6px 0 0 0; padding-left: 20px;">';
            foreach ($qwen->suggestions as $sug) {
                $html .= '<li>' . htmlspecialchars($sug) . '</li>';
            }
            $html .= '</ul></div>';
        }

        // Overall comment
        if (!empty($qwen->commentaire)) {
            $html .= '<div style="background: #f5f5f5; border-left: 4px solid #6c757d; padding: 10px 14px; margin-bottom: 12px; border-radius: 4px;">';
            $html .= '<strong>Commentaire general</strong><br>' . htmlspecialchars($qwen->commentaire);
            $html .= '</div>';
        }

        // DeepSeek feedback
        if (!empty($ds->feedback) && $ds->feedback !== 'Contre-correction indisponible') {
            $html .= '<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px 14px; margin-bottom: 12px; border-radius: 4px;">';
            $html .= '<strong style="color: #856404;">Contre-correction</strong><br>';
            $html .= htmlspecialchars(substr($ds->feedback, 0, 500));
            $html .= '</div>';
        }

        // Calibration note (when post-processing adjusted the grade).
        if (!empty($calibration_note)) {
            $html .= '<div style="background: #fff3e0; border-left: 4px solid #ff9800; padding: 10px 14px; margin-bottom: 12px; border-radius: 4px; font-size: 0.85em;">';
            $html .= '<strong style="color: #e65100;">Calibrage appliqu&eacute;</strong><br>';
            $html .= htmlspecialchars($calibration_note);
            $html .= '</div>';
        }

        // Arbitration reasoning (when reviewers disagreed).
        if (!empty($arb_reasoning)) {
            $html .= '<div style="background: #e8eaf6; border-left: 4px solid #5c6bc0; padding: 10px 14px; margin-bottom: 12px; border-radius: 4px;">';
            $html .= '<strong style="color: #283593;">Arbitrage</strong><br>';
            $html .= htmlspecialchars($arb_reasoning);
            $html .= '</div>';
        }

        // Detailed analysis (collapsible)
        $html .= '<details style="margin-top: 12px;"><summary style="cursor: pointer; font-weight: bold; padding: 8px; background: #e9ecef; border-radius: 4px;">Analyse detaillee (cliquer pour ouvrir)</summary>';
        $html .= '<div style="padding: 12px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 0 0 4px 4px; white-space: pre-wrap; font-size: 0.9em;">';
        $html .= htmlspecialchars($detailed_review);
        $html .= '</div></details>';

        $html .= '</div>';
        return $html;
    }

    /**
     * Parse structured JSON response (Pass 3 format with points_forts, erreurs, suggestions).
     */
    private function parse_structured_response(string $response, float $maxgrade): object {
        // Try to extract JSON from the response
        $json = $response;
        if (preg_match('/```(?:json)?\s*(\{.+\})\s*```/s', $response, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/(\{.*"grade".*\})/s', $response, $matches)) {
            $json = $matches[1];
        }

        $data = json_decode($json);
        if (!$data || !isset($data->grade)) {
            throw new \moodle_exception('parse_error', 'local_dreamu_ai', '', null,
                "Could not parse AI response as JSON: {$response}");
        }

        $result = new \stdClass();
        $result->points_forts = isset($data->points_forts) && is_array($data->points_forts) ? $data->points_forts : [];
        $result->erreurs = isset($data->erreurs) && is_array($data->erreurs) ? $data->erreurs : [];
        $result->suggestions = isset($data->suggestions) && is_array($data->suggestions) ? $data->suggestions : [];
        $result->commentaire = isset($data->commentaire) ? (string)$data->commentaire : '';
        $result->criteria_grades = isset($data->criteria_grades) && is_array($data->criteria_grades) ? $data->criteria_grades : [];

        // If per-criterion grades exist, compute grade as sum of scores (override model's total).
        if (!empty($result->criteria_grades)) {
            $computed = 0;
            foreach ($result->criteria_grades as $cg) {
                $score = floatval($cg->score ?? $cg['score'] ?? 0);
                $max = floatval($cg->max ?? $cg['max'] ?? 0);
                $computed += min($score, $max); // Never exceed criterion max.
            }
            $result->grade = max(0, min($maxgrade, round($computed, 2)));
        } else {
            $result->grade = max(0, min($maxgrade, floatval($data->grade)));
        }

        $result->feedback = $result->commentaire;

        return $result;
    }

    private function call_api(string $systemprompt, string $userprompt, float $temperature = 0.3, int $maxtokens = 2000): string {
        // Sanitize UTF-8: remove invalid sequences that would break JSON encoding.
        $systemprompt = $this->sanitize_utf8($systemprompt);
        $userprompt = $this->sanitize_utf8($userprompt);

        $payload = json_encode([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemprompt],
                ['role' => 'user', 'content' => $userprompt],
            ],
            'temperature' => $temperature,
            'max_tokens' => $maxtokens,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($payload === false) {
            throw new \moodle_exception('api_error', 'local_dreamu_ai', '', null,
                'Failed to encode JSON payload: ' . json_last_error_msg());
        }

        // Use native PHP curl — Moodle's \curl class causes indefinite hangs with vLLM.
        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apikey,
            ],
            CURLOPT_TIMEOUT => 600,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlerror = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \moodle_exception('api_error', 'local_dreamu_ai', '', null,
                "cURL error: {$curlerror}");
        }

        if ($httpcode !== 200) {
            throw new \moodle_exception('api_error', 'local_dreamu_ai', '', null,
                "HTTP {$httpcode}: {$response}");
        }

        $decoded = json_decode($response, true);
        if (!$decoded || !isset($decoded['choices'][0]['message']['content'])) {
            throw new \moodle_exception('api_error', 'local_dreamu_ai', '', null,
                "Invalid API response: {$response}");
        }

        $content = $decoded['choices'][0]['message']['content'];

        // Filter out <think> tags from reasoning models (DeepSeek R1, etc.).
        $content = preg_replace('/<think>[\s\S]*?<\/think>/', '', $content);

        return trim($content);
    }


    /**
     * Parse the AI response into a grade and feedback.
     *
     * @param string $response Raw text from the AI
     * @param float $maxgrade Maximum allowed grade
     * @return object with ->grade and ->feedback
     * @throws \moodle_exception
     */
    private function parse_response(string $response, float $maxgrade): object {
        // Try to extract JSON from the response (the AI might wrap it in markdown code blocks).
        $json = $response;
        if (preg_match('/```(?:json)?\s*(\{.+\})\s*```/s', $response, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/(\{.*"grade".*"feedback".*\})/s', $response, $matches)) {
            $json = $matches[1];
        }

        $data = json_decode($json);
        if (!$data || !isset($data->grade) || !isset($data->feedback)) {
            throw new \moodle_exception('parse_error', 'local_dreamu_ai', '', null,
                "Could not parse AI response as JSON: {$response}");
        }

        // Clamp grade to valid range.
        $grade = max(0, min($maxgrade, floatval($data->grade)));

        // Handle feedback that may be a string or an object/array.
        $feedback = $data->feedback;
        if (!is_string($feedback)) {
            // Convert structured feedback to readable HTML.
            $parts = [];
            foreach ((array)$feedback as $key => $value) {
                $label = ucfirst(str_replace('_', ' ', $key));
                if (is_array($value)) {
                    $items = implode('</li><li>', array_map('htmlspecialchars', $value));
                    $parts[] = "<strong>{$label}:</strong><ul><li>{$items}</li></ul>";
                } else {
                    $parts[] = "<strong>{$label}:</strong> " . htmlspecialchars($value);
                }
            }
            $feedback = implode("\n", $parts);
        }

        $result = new \stdClass();
        $result->grade = $grade;
        $result->feedback = clean_text($feedback);

        return $result;
    }

    /**
     * Get the text content of a submission (online text + file contents).
     * Supports text files, ZIP archives, and PDF files (via Vision API).
     *
     * @param \assign $assign The assignment instance
     * @param \stdClass $submission The submission record
     * @param int $userid The user ID
     * @return string The combined submission text
     */
    public static function get_submission_text(\assign $assign, \stdClass $submission, int $userid): string {
        global $DB;
        $text = '';

        // Get online text submission — try plugin first, then direct DB fallback.
        $onlinetext = $assign->get_submission_plugin_by_type('onlinetext');
        if ($onlinetext) {
            $editortext = $onlinetext->get_editor_text('onlinetext', $submission->id);
            if ($editortext) {
                $text .= html_to_text($editortext, 0, false) . "\n\n";
            }
        }
        // Direct DB fallback if plugin method returned nothing.
        if (empty(trim($text))) {
            $onlinetextrecord = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]);
            if ($onlinetextrecord && !empty($onlinetextrecord->onlinetext)) {
                $text .= html_to_text($onlinetextrecord->onlinetext, 0, false) . "\n\n";
            }
        }

        // Get file submissions — read text content of code/text files.
        $fileplugin = $assign->get_submission_plugin_by_type('file');
        if ($fileplugin) {
            $fs = get_file_storage();
            $context = $assign->get_context();
            $files = $fs->get_area_files(
                $context->id,
                'assignsubmission_file',
                'submission_files',
                $submission->id,
                'sortorder, filename',
                false
            );

            foreach ($files as $file) {
                $filename = $file->get_filename();
                $extension = pathinfo($filename, PATHINFO_EXTENSION);

                // Read text-based files (code, text, markdown, etc.).
                $textextensions = [
                    'txt', 'md', 'py', 'java', 'c', 'cpp', 'h', 'hpp', 'cs',
                    'js', 'ts', 'html', 'css', 'php', 'rb', 'go', 'rs', 'sql',
                    'sh', 'bash', 'json', 'xml', 'yaml', 'yml', 'toml', 'ini',
                    'r', 'R', 'ipynb', 'tex', 'csv',
                ];

                if (in_array(strtolower($extension), $textextensions)) {
                    $content = $file->get_content();
                    // Convert HTML files to plain text to strip CSS/JS/tags.
                    if (in_array(strtolower($extension), ['html', 'htm'])) {
                        $content = html_to_text($content, 0, false);
                    }
                    $text .= "--- File: {$filename} ---\n{$content}\n\n";
                } elseif (strtolower($extension) === 'pdf') {
                    $text .= "--- File: {$filename} (PDF, {$file->get_filesize()} bytes) ---\n\n";
                } elseif (strtolower($extension) === 'zip') {
                    // Extract ZIP and read text files inside
                    $tmpdir = make_temp_directory('dreamu_ai_zip_' . $submission->id);
                    $tmpzip = $tmpdir . '/' . $filename;
                    $file->copy_content_to($tmpzip);

                    $zip = new \ZipArchive();
                    if ($zip->open($tmpzip) === true) {
                        $text .= "--- Archive: {$filename} ({$zip->numFiles} files) ---\n\n";
                        $filesread = 0;
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $entryname = $zip->getNameIndex($i);
                            $entryext = strtolower(pathinfo($entryname, PATHINFO_EXTENSION));

                            // Skip directories and non-text files
                            if (substr($entryname, -1) === '/') continue;

                            if (in_array($entryext, $textextensions)) {
                                $entrycontent = $zip->getFromIndex($i);
                                if ($entrycontent !== false && strlen($entrycontent) > 0) {
                                    // Limit each file to 5000 chars
                                    if (strlen($entrycontent) > 5000) {
                                        $entrycontent = substr($entrycontent, 0, 5000) . "\n[... TRUNCATED ...]";
                                    }
                                    $text .= "--- File (in zip): {$entryname} ---\n{$entrycontent}\n\n";
                                    $filesread++;
                                }
                            }
                            // Stop after 10 files to avoid context overflow
                            if ($filesread >= 10) {
                                $text .= "[... remaining files skipped ...]\n\n";
                                break;
                            }
                        }
                        $zip->close();
                    } else {
                        $text .= "--- File: {$filename} (ZIP could not be opened) ---\n\n";
                    }
                    // Cleanup
                    @unlink($tmpzip);
                    @rmdir($tmpdir);
                } else {
                    $text .= "--- File: {$filename} (binary, {$file->get_filesize()} bytes) ---\n\n";
                }
            }
        }

        return trim($text);
    }

    /**
     * Post-processing calibration: detect inconsistencies between feedback and grade.
     *
     * Checks:
     * 1. Per-criterion scores: if a criterion's justification mentions errors/incomplete but score is > 80%, deduct.
     * 2. Error count vs grade: if many errors listed but grade > 80%, cap or deduct.
     * 3. Incomplete exercises: if review mentions "not done/incomplete/missing", enforce 0 for that criterion.
     * 4. Both passes agree on high grade but review mentions significant issues.
     *
     * @return array ['grade' => float, 'note' => string]
     */
    private function calibrate_grade(float $grade, float $maxgrade, object $pass3, object $pass4, string $review): array {
        $original = $grade;
        $notes = [];

        // --- Check 1: Per-criterion consistency ---
        if (!empty($pass3->criteria_grades)) {
            $recomputed = 0;
            $adjusted_criteria = [];

            foreach ($pass3->criteria_grades as $cg) {
                $cg = (object)$cg;
                $score = floatval($cg->score ?? 0);
                $cmax = floatval($cg->max ?? 0);
                $justification = strtolower($cg->justification ?? '');
                $name = strtolower($cg->name ?? '');

                if ($cmax <= 0) {
                    $recomputed += $score;
                    continue;
                }

                $ratio = $score / $cmax;

                // Detect negative signals in the justification.
                $negative_signals = [
                    'not done', 'not attempted', 'missing', 'absent', 'pas fait', 'non fait',
                    'incomplet', 'incomplete', 'pas termine', 'non termine',
                    'ne sait pas', 'doesn\'t know', 'no answer', 'pas de reponse',
                    'aucune', 'nothing', 'vide', 'empty',
                ];
                $partial_signals = [
                    'error', 'erreur', 'incorrect', 'faux', 'wrong', 'false',
                    'bug', 'mistake', 'failed', 'echoue', 'manque',
                    'without justification', 'sans justification', 'sans preuve',
                    'no proof', 'no justification', 'partial', 'partiel',
                    'compilation error', 'syntax error', 'ne compile pas',
                ];

                $is_missing = false;
                $has_errors = false;

                foreach ($negative_signals as $sig) {
                    if (strpos($justification, $sig) !== false) {
                        $is_missing = true;
                        break;
                    }
                }

                if (!$is_missing) {
                    foreach ($partial_signals as $sig) {
                        if (strpos($justification, $sig) !== false) {
                            $has_errors = true;
                            break;
                        }
                    }
                }

                // Apply deductions.
                if ($is_missing && $ratio > 0.3) {
                    // Justification says missing/not done but score > 30% -- cap at 0.
                    $score = 0;
                    $notes[] = "Calibrage: '{$cg->name}' marque non fait -> 0/{$cmax}";
                } else if ($has_errors && $ratio > 0.8) {
                    // Justification mentions errors but score > 80% -- cap at 60%.
                    $score = round($cmax * 0.6, 1);
                    $notes[] = "Calibrage: '{$cg->name}' contient des erreurs -> {$score}/{$cmax}";
                }

                $recomputed += min($score, $cmax);
            }

            $recomputed = round($recomputed, 2);

            if ($recomputed < $grade) {
                $grade = $recomputed;
                $notes[] = "Note recalculee apres calibrage par critere: {$grade}/{$maxgrade}";
            }
        }

        // --- Check 2: Error count vs grade ratio ---
        $error_count = count($pass3->erreurs ?? []);
        $grade_ratio = $maxgrade > 0 ? $grade / $maxgrade : 0;

        // If 3+ errors listed but grade still > 85%, apply penalty.
        if ($error_count >= 3 && $grade_ratio > 0.85) {
            $penalty = min($error_count * 0.5, $maxgrade * 0.15); // 0.5pt per error, max 15%
            $grade = round($grade - $penalty, 2);
            $notes[] = "Calibrage: {$error_count} erreurs listees, -" . $penalty . " pts";
        }

        // --- Check 3: Review text mentions incomplete/missing exercises ---
        $review_lower = strtolower($review);
        $incomplete_patterns = [
            '/exercice\s*\d+\s*:?\s*(not done|non fait|incomplet|incomplete|pas fait|manquant|missing)/i',
            '/n\'?a pas (fait|repondu|traite|termine)/i',
            '/je n\'?ai pas (reussi|pu|su)/i',
            '/je sais pas/i',
        ];

        $incomplete_mentions = 0;
        foreach ($incomplete_patterns as $pat) {
            $incomplete_mentions += preg_match_all($pat, $review);
        }

        if ($incomplete_mentions > 0 && $grade_ratio > 0.7) {
            $penalty = min($incomplete_mentions * ($maxgrade * 0.1), $maxgrade * 0.25);
            $grade = round($grade - $penalty, 2);
            $notes[] = "Calibrage: {$incomplete_mentions} exercice(s) incomplet(s) detecte(s), -" . $penalty . " pts";
        }

        // --- Check 4: Counter-review significantly lower should pull down ---
        $p4grade = floatval($pass4->grade ?? $grade);
        if ($p4grade < $grade && ($grade - $p4grade) > $maxgrade * 0.1) {
            // Counter gave meaningfully lower -- use weighted: 40% pass3, 60% counter.
            $pulled = round($grade * 0.4 + $p4grade * 0.6, 2);
            if ($pulled < $grade) {
                $notes[] = "Calibrage: contre-correction plus severe ({$p4grade}/{$maxgrade}), ajustement";
                $grade = $pulled;
            }
        }

        // Final clamp.
        $grade = max(0, min($maxgrade, $grade));

        $note = implode('. ', $notes);
        return ['grade' => $grade, 'note' => $note];
    }

    /**
     * Build a grade scale description adapted to the maxgrade value.
     */
    private function build_grade_scale(float $maxgrade): string {
        if ($maxgrade <= 5) {
            return "0-1 very bad, 2 poor, 3 average, 4 good, 5 excellent";
        }
        if ($maxgrade <= 10) {
            $m = $maxgrade;
            return "0-" . round($m * 0.2) . " very bad, "
                . round($m * 0.2 + 1) . "-" . round($m * 0.4) . " poor, "
                . round($m * 0.4 + 1) . "-" . round($m * 0.55) . " average, "
                . round($m * 0.55 + 1) . "-" . round($m * 0.7) . " good, "
                . round($m * 0.7 + 1) . "-" . round($m * 0.85) . " very good, "
                . round($m * 0.85 + 1) . "-" . round($m) . " excellent";
        }
        // General formula for any maxgrade.
        $m = $maxgrade;
        return "0-" . round($m * 0.25) . " very bad, "
            . round($m * 0.25 + 1) . "-" . round($m * 0.4) . " poor, "
            . round($m * 0.4 + 1) . "-" . round($m * 0.55) . " average, "
            . round($m * 0.55 + 1) . "-" . round($m * 0.7) . " good, "
            . round($m * 0.7 + 1) . "-" . round($m * 0.85) . " very good, "
            . round($m * 0.85 + 1) . "-" . round($m) . " excellent";
    }

    /**
     * Sanitize a string to valid UTF-8, removing or replacing invalid sequences.
     */
    private function sanitize_utf8(string $text): string {
        // Convert to UTF-8 if needed, replace invalid chars with '?'.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        // Remove null bytes and control chars except newline/tab.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        return $text;
    }
}
