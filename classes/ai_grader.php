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
            ?: 'http://100.76.166.71:8102/v1/chat/completions';
        $this->apikey = get_config('local_dreamu_ai', 'api_key') ?: 'sk-dummy';
        $this->model = get_config('local_dreamu_ai', 'model_name') ?: 'general';
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

        // No truncation for multi-pass - send full code
        $maxchars = 30000;
        $truncated = false;
        if (strlen($submissiontext) > $maxchars) {
            $submissiontext = substr($submissiontext, 0, $maxchars);
            $truncated = true;
        }

        // === PASS 1: Read and understand the code ===
        $system1 = "You are a code reviewer. Read the following student submission carefully. "
            . "List ALL files found, describe what each file/function does in 2-3 sentences. "
            . "Identify the main algorithms implemented. Respond in {$langname}.";
        $user1 = "Student submission:\n\n{$submissiontext}";

        try {
            $analysis = $this->call_api($system1, $user1);
        } catch (\Exception $e) {
            $analysis = "Analysis failed: " . $e->getMessage();
        }

        // === PASS 2: Detailed code review ===
        $system2 = "You are an expert C++ code reviewer. Based on the code and your previous analysis, "
            . "perform a DETAILED review. You MUST:\n"
            . "1. Cite SPECIFIC function names and explain issues (e.g. 'La fonction calculerBorda() ne gere pas le cas ou la liste est vide')\n"
            . "2. Point out SPECIFIC bugs with the variable names involved\n"
            . "3. Comment on code style: missing comments, bad variable names, poor structure\n"
            . "4. Check if error handling exists for edge cases\n"
            . "5. Check compilation: are there syntax errors, missing includes, type mismatches?\n"
            . "Be VERY specific. Do NOT use generic phrases like 'le code est correct'. "
            . "Cite actual code elements. Respond in {$langname}.";
        $user2 = "Previous analysis:\n{$analysis}\n\nGrading criteria:\n{$prompt}\n\nFull code:\n{$submissiontext}";

        // Truncate user2 if too long for second pass
        if (strlen($user2) > 28000) {
            $user2 = substr($user2, 0, 28000) . "\n[... code truncated for review pass ...]";
        }

        try {
            $review = $this->call_api($system2, $user2);
        } catch (\Exception $e) {
            $review = "Review failed: " . $e->getMessage();
        }

        // === PASS 3: Final grade ===
        $system3 = "Based on your detailed code review below, give a final grade and feedback. "
            . "Respond ONLY in JSON: {\"grade\": NUMBER, \"feedback\": \"TEXT\"}\n"
            . "Grade 0-{$maxgrade}. Use the FULL range: 0-6 = very bad, 7-9 = poor, 10-12 = average, 13-15 = good, 16-18 = very good, 19-20 = excellent.\n"
            . "The feedback MUST include:\n"
            . "- A summary of what was implemented\n"
            . "- Specific issues found (cite function names and variables)\n"
            . "- What was done well\n"
            . "- What needs improvement\n"
            . "Feedback in {$langname}. Be detailed (at least 5 sentences).";
        $user3 = "Grading criteria:\n{$prompt}\n\nMax grade: {$maxgrade}\n\nDetailed review:\n{$review}\n\nJSON:";

        $response = $this->call_api($system3, $user3);

        $result = $this->parse_response($response, $maxgrade);

        // Append the detailed review to the feedback
        $result->feedback = $result->feedback . "\n\n--- Analyse detaillee ---\n" . $review;

        return $result;
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
