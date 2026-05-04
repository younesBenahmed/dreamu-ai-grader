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
 * Adhoc task to grade all submissions for an assignment using AI.
 * Grades are stored as "graded" (pending teacher validation) — NOT applied to gradebook.
 *
 * @package    local_dreamu_ai
 * @copyright  2026 Dream-U / AMU / IUT Aix-en-Provence
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_dreamu_ai\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assign/locallib.php');

class grade_submissions extends \core\task\adhoc_task {

    public function get_name() {
        return get_string('task_grade_submissions', 'local_dreamu_ai');
    }

    public function execute() {
        global $DB, $CFG;

        $data = $this->get_custom_data();
        $assignid = $data->assignid;
        $teacherid = $data->teacherid;

        mtrace("Dream-U AI Grader: Starting grading for assignment {$assignid}");

        // Load the assignment.
        $cm = get_coursemodule_from_instance('assign', $assignid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $assign = new \assign($context, $cm, $course);

        // Load AI config for this assignment.
        $config = $DB->get_record('local_dreamu_ai_config', ['assignid' => $assignid]);
        if (!$config || !$config->enabled) {
            mtrace("  AI grading not enabled for this assignment.");
            return;
        }

        $prompt = $config->prompt ?: get_string('grading_prompt_default', 'local_dreamu_ai');
        $maxgrade = floatval($assign->get_instance()->grade);
        if ($maxgrade <= 0) {
            $maxgrade = floatval($config->maxgrade);
        }
        $language = $config->language ?: 'fr';

        // Get all submitted submissions.
        $submissions = $DB->get_records('assign_submission', [
            'assignment' => $assignid,
            'status' => 'submitted',
            'latest' => 1,
        ]);

        if (empty($submissions)) {
            mtrace("  No submissions found.");
            return;
        }

        mtrace("  Found " . count($submissions) . " submissions to grade.");
// Clean up old pending (non-validated) grades before re-grading        $old_pending = $DB->delete_records_select(            "local_dreamu_ai_grades",            "assignid = :assignid AND validated = 0",            ["assignid" => $assignid]        );        if ($old_pending) {            mtrace("  Cleaned up " . $old_pending . " old pending grades.");        }

        $grader = new \local_dreamu_ai\ai_grader();
        $graded = 0;
        $errors = 0;

        $teacher = $DB->get_record('user', ['id' => $teacherid], '*', MUST_EXIST);
        \core\session\manager::set_user($teacher);

        foreach ($submissions as $submission) {
            $userid = $submission->userid;

            if ($userid == 0) {
                continue;
            }

            mtrace("  Grading submission for user {$userid}...");

            // Log the attempt.
            $logrecord = new \stdClass();
            $logrecord->assignid = $assignid;
            $logrecord->userid = $userid;
            $logrecord->submissionid = $submission->id;
            $logrecord->status = 'pending';
            $logrecord->validated = 0; // Not validated yet.
            $logrecord->timecreated = time();
            $logid = $DB->insert_record('local_dreamu_ai_grades', $logrecord);

            try {
                // Extract submission text.
                $submissiontext = \local_dreamu_ai\ai_grader::get_submission_text($assign, $submission, $userid);

                if (empty(trim($submissiontext))) {
                    throw new \moodle_exception('empty_submission', 'local_dreamu_ai', '', null,
                        'Submission is empty or contains only binary files.');
                }

                // Call the AI to grade.
                $result = $grader->grade_submission($submissiontext, $prompt, $maxgrade, $language);

                // Store AI grade — do NOT apply to Moodle gradebook yet.
                // Teacher must validate first via validate.php.
                $DB->update_record('local_dreamu_ai_grades', (object)[
                    'id' => $logid,
                    'grade' => $result->grade,
                    'feedback' => $result->feedback,
                    'rawresponse' => substr(json_encode($result), 0, 65535),
                    'status' => 'graded',
                    'validated' => 0,
                ]);

                $graded++;
                mtrace("    -> AI Grade: {$result->grade}/{$maxgrade} (pending validation)");

            } catch (\Exception $e) {
                $errors++;
                $errormsg = $e->getMessage();
                mtrace("    -> ERROR: {$errormsg}");

                $DB->update_record('local_dreamu_ai_grades', (object)[
                    'id' => $logid,
                    'status' => 'error',
                    'errormessage' => substr($errormsg, 0, 65535),
                ]);
            }
        }

        mtrace("Dream-U AI Grader: Done. {$graded} graded (pending validation), {$errors} errors.");

        // Send notification to the teacher with link to validation page.
        $validateurl = new \moodle_url('/local/dreamu_ai/validate.php', ['id' => $cm->id]);

        $message = new \core\message\message();
        $message->component = 'local_dreamu_ai';
        $message->name = 'grading_complete';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $teacher;
        $message->subject = get_string('pluginname', 'local_dreamu_ai') . ' - '
            . get_string('grading_complete_subject', 'local_dreamu_ai');
        $message->fullmessage = get_string('grading_complete', 'local_dreamu_ai',
            (object)['graded' => $graded, 'errors' => $errors]);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = get_string('grading_complete_html', 'local_dreamu_ai',
            (object)[
                'graded' => $graded,
                'errors' => $errors,
                'assignname' => $assign->get_instance()->name,
                'validateurl' => $validateurl->out(false),
            ]);
        $message->smallmessage = get_string('grading_complete', 'local_dreamu_ai',
            (object)['graded' => $graded, 'errors' => $errors]);
        $message->notification = 1;
        $message->contexturl = $validateurl->out(false);
        $message->contexturlname = get_string('validate_grades', 'local_dreamu_ai');

        try {
            message_send($message);
            mtrace("  Notification sent to teacher.");
        } catch (\Exception $e) {
            mtrace("  Warning: Could not send notification: " . $e->getMessage());
        }
    }
}
