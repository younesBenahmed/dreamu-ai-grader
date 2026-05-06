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

    private string $deepseek_endpoint;

    /** @var string Vision model endpoint for PDF reading */
    private string $vision_endpoint;

    /** @var string Vision model name */
    private string $vision_model;

    public function __construct() {
        $this->endpoint = get_config('local_dreamu_ai', 'api_endpoint')
            ?: 'http://100.76.166.71:8102/v1/chat/completions';
        $this->apikey = get_config('local_dreamu_ai', 'api_key') ?: 'sk-dummy';
        $this->model = get_config('local_dreamu_ai', 'model_name') ?: 'general';
        // DeepSeek endpoint for dual grading
        $base = str_replace('/v1/chat/completions', '', $this->endpoint);
        $this->deepseek_endpoint = $base . '/v1/chat/completions';
        // Vision model endpoint for PDF reading
        $this->vision_endpoint = 'http://100.76.166.71:8105/v1/chat/completions';
        $this->vision_model = 'vision-lite';
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

        $maxchars = 30000;
        if (strlen($submissiontext) > $maxchars) {
            $submissiontext = substr($submissiontext, 0, $maxchars);
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

        // === PASS 1 (Qwen): Read and understand ===
        $system1 = "You are a {$contenttype} reviewer. Read the student submission carefully. "
            . "List ALL files/sections found, describe what each file/function/section does in 2-3 sentences. "
            . "Identify the main concepts, algorithms, or arguments presented. Respond in {$langname}.";

        try {
            $analysis = $this->call_api($system1, "Student submission:\n\n{$submissiontext}");
        } catch (\Exception $e) {
            $analysis = "Analysis failed: " . $e->getMessage();
        }

        // === PASS 2 (Qwen): Detailed review ===
        $system2 = "You are an expert {$contenttype} reviewer. Perform a DETAILED review of this {$type_label}. You MUST:\n"
            . "{$review_focus}\n"
            . "Be VERY specific. Respond in {$langname}.";

        $user2 = "Analysis:\n{$analysis}\n\nCriteria:\n{$prompt}\n\nSubmission:\n{$submissiontext}";
        if (strlen($user2) > 28000) {
            $user2 = substr($user2, 0, 28000) . "\n[... truncated ...]";
        }

        try {
            $qwen_review = $this->call_api($system2, $user2);
        } catch (\Exception $e) {
            $qwen_review = "Review failed: " . $e->getMessage();
        }

        // === PASS 3 (Qwen): Grade with structured feedback ===
        $system3 = "Based on your review, give a grade in JSON with this EXACT format:\n"
            . "{\"grade\": NUMBER, \"points_forts\": [\"point 1\", \"point 2\"], \"erreurs\": [\"error 1\", \"error 2\"], \"suggestions\": [\"suggestion 1\", \"suggestion 2\"], \"commentaire\": \"overall comment\"}\n"
            . "Grade 0-{$maxgrade}. Use FULL range: 0-5 very bad, 6-8 poor, 9-11 average, 12-14 good, 15-17 very good, 18-{$maxgrade} excellent.\n"
            . "Each array must have at least 2 items. Be specific, cite concrete elements from the submission. In {$langname}.\n"
            . "RESPOND ONLY WITH THE JSON, no extra text.";

        try {
            $qwen_response = $this->call_api($system3, "Criteria:\n{$prompt}\n\nMax: {$maxgrade}\n\nReview:\n{$qwen_review}\n\nJSON:");
            $qwen_result = $this->parse_structured_response($qwen_response, $maxgrade);
        } catch (\Exception $e) {
            $qwen_result = (object)[
                'grade' => $maxgrade * 0.5,
                'feedback' => 'Qwen grading failed: ' . $e->getMessage(),
                'points_forts' => [],
                'erreurs' => [],
                'suggestions' => [],
                'commentaire' => 'Qwen grading failed: ' . $e->getMessage(),
            ];
        }

        // === PASS 4 (DeepSeek): Independent counter-review and grade ===
        $ds_system = "You are a strict {$contenttype} professor. A student submitted work and another AI gave a review. "
            . "Read the submission yourself and give YOUR OWN independent grade. "
            . "You may DISAGREE with the first review. Be stricter on real errors and missing requirements. "
            . "Respond ONLY in JSON: {\"grade\": NUMBER, \"feedback\": \"TEXT\"}\n"
            . "Grade 0-{$maxgrade}. Use FULL range. Feedback in {$langname}. Cite specific elements.";

        $ds_user = "Criteria:\n{$prompt}\n\nMax: {$maxgrade}\n\nFirst AI review:\n" . substr($qwen_review, 0, 3000)
            . "\n\nFirst AI grade: " . $qwen_result->grade . "/{$maxgrade}"
            . "\n\nStudent submission:\n" . substr($submissiontext, 0, 8000) . "\n\nYour independent JSON grade:";

        try {
            $ds_response = $this->call_deepseek($ds_system, $ds_user);
            $ds_result = $this->parse_response($ds_response, $maxgrade);
        } catch (\Exception $e) {
            // If DeepSeek fails, use only Qwen grade
            $ds_result = (object)['grade' => $qwen_result->grade, 'feedback' => 'DeepSeek review unavailable'];
        }

        // === FINAL: Average both grades and build structured HTML feedback ===
        $final_grade = round(($qwen_result->grade + $ds_result->grade) / 2, 2);
        $final_grade = max(0, min($maxgrade, $final_grade));

        $final_feedback = $this->build_html_feedback(
            $qwen_result,
            $ds_result,
            $qwen_review,
            $final_grade,
            $maxgrade,
            $contenttype
        );

        $result = new \stdClass();
        $result->grade = $final_grade;
        $result->feedback = $final_feedback;

        return $result;
    }

    /**
     * Build structured HTML feedback with colored sections.
     */
    private function build_html_feedback(object $qwen, object $ds, string $detailed_review, float $final_grade, float $maxgrade, string $contenttype): string {
        $html = '<div style="font-family: sans-serif; max-width: 800px;">';

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
        $html .= '<strong style="font-size: 1.2em;">Note finale (moyenne) : ' . $final_grade . '/' . $maxgrade . '</strong>';
        $html .= '<br><small>Qwen : ' . $qwen->grade . '/' . $maxgrade . ' | DeepSeek : ' . $ds->grade . '/' . $maxgrade . '</small>';
        $html .= '<br><small>Type detecte : ' . htmlspecialchars($contenttype) . '</small>';
        $html .= '</div>';

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
        if (!empty($ds->feedback) && $ds->feedback !== 'DeepSeek review unavailable') {
            $html .= '<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px 14px; margin-bottom: 12px; border-radius: 4px;">';
            $html .= '<strong style="color: #856404;">Contre-evaluation (DeepSeek)</strong><br>';
            $html .= htmlspecialchars(substr($ds->feedback, 0, 500));
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
        $result->grade = max(0, min($maxgrade, floatval($data->grade)));
        $result->points_forts = isset($data->points_forts) && is_array($data->points_forts) ? $data->points_forts : [];
        $result->erreurs = isset($data->erreurs) && is_array($data->erreurs) ? $data->erreurs : [];
        $result->suggestions = isset($data->suggestions) && is_array($data->suggestions) ? $data->suggestions : [];
        $result->commentaire = isset($data->commentaire) ? (string)$data->commentaire : '';
        // Also set feedback for compatibility
        $result->feedback = $result->commentaire;

        return $result;
    }

    /**
     * Call DeepSeek API directly (bypass router to avoid image detection).
     */
    private function call_deepseek(string $systemprompt, string $userprompt): string {
        $systemprompt = $this->sanitize_utf8($systemprompt);
        $userprompt = $this->sanitize_utf8($userprompt);

        // Use DeepSeek endpoint (port 8103)
        $endpoint = str_replace(':8200/', ':8103/', $this->endpoint);

        $payload = json_encode([
            'model' => 'reasoning-lite',
            'messages' => [
                ['role' => 'system', 'content' => $systemprompt],
                ['role' => 'user', 'content' => $userprompt],
            ],
            'temperature' => 0.3,
            'max_tokens' => 1500,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apikey,
            ],
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpcode !== 200) {
            throw new \moodle_exception('api_error', 'local_dreamu_ai', '', null, "DeepSeek HTTP {$httpcode}");
        }

        $decoded = json_decode($response, true);
        $content = $decoded['choices'][0]['message']['content'] ?? '';

        // Remove <think> tags from DeepSeek
        $content = preg_replace('/<think>.*?<\/think>/s', '', $content);
        // Remove English thinking before French
        if (preg_match('/(Pour |L\'|La |Le |Les |Voici |\{)/u', $content, $m, PREG_OFFSET_CAPTURE)) {
            if ($m[0][1] > 100) {
                $content = substr($content, $m[0][1]);
            }
        }

        return trim($content);
    }

    private function call_api(string $systemprompt, string $userprompt): string {
        // Sanitize UTF-8: remove invalid sequences that would break JSON encoding.
        $systemprompt = $this->sanitize_utf8($systemprompt);
        $userprompt = $this->sanitize_utf8($userprompt);

        $payload = json_encode([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemprompt],
                ['role' => 'user', 'content' => $userprompt],
            ],
            'temperature' => 0.3,
            'max_tokens' => 2000,
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

        return $decoded['choices'][0]['message']['content'];
    }

    /**
     * Call the Vision model API to extract text from a PDF image/page.
     *
     * @param string $base64content Base64-encoded PDF content
     * @param string $filename Original filename for context
     * @return string Extracted text
     */
    private function call_vision_for_pdf(string $base64content, string $filename): string {
        $payload = json_encode([
            'model' => $this->vision_model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a document text extractor. Extract ALL text content from this PDF document. '
                        . 'Preserve the structure, headings, and formatting as much as possible. '
                        . 'Output only the extracted text, nothing else.',
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:application/pdf;base64,' . $base64content,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => "Extract all text from this PDF file: {$filename}",
                        ],
                    ],
                ],
            ],
            'temperature' => 0.1,
            'max_tokens' => 4000,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($payload === false) {
            throw new \Exception('Failed to encode vision API payload');
        }

        $ch = curl_init($this->vision_endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apikey,
            ],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpcode !== 200) {
            throw new \Exception("Vision API HTTP {$httpcode}");
        }

        $decoded = json_decode($response, true);
        $content = $decoded['choices'][0]['message']['content'] ?? '';

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
                    $text .= "--- File: {$filename} ---\n{$content}\n\n";
                } elseif (strtolower($extension) === 'pdf') {
                    // Try to extract text from PDF via Vision model API
                    try {
                        $pdfcontent = $file->get_content();
                        $base64 = base64_encode($pdfcontent);
                        // Limit to ~10MB base64 to avoid API overload
                        if (strlen($base64) > 10 * 1024 * 1024) {
                            $text .= "--- File: {$filename} (PDF too large for vision extraction, {$file->get_filesize()} bytes) ---\n\n";
                        } else {
                            $grader = new self();
                            $extracted = $grader->call_vision_for_pdf($base64, $filename);
                            if (!empty(trim($extracted))) {
                                $text .= "--- File: {$filename} (PDF, extracted via Vision) ---\n{$extracted}\n\n";
                            } else {
                                $text .= "--- File: {$filename} (PDF file, vision returned empty) ---\n\n";
                            }
                        }
                    } catch (\Exception $e) {
                        $text .= "--- File: {$filename} (PDF file, cannot read: " . $e->getMessage() . ") ---\n\n";
                    }
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
