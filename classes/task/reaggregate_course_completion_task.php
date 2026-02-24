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
 * Correct course completion times using Moodle completion reaggregation.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_facetoface\task;

use mod_facetoface\util\completion_util;

/**
 * Correct course completion times for Face-to-Face courses.
 */
class reaggregate_course_completion_task extends \core\task\adhoc_task {

    /** @var int Emit progress output every N records. */
    private const PROGRESS_INTERVAL = 500;

    /**
     * Run the ad hoc task.
     */
    public function execute() {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');

        $processed = 0;
        $errors = 0;
        $beforets = null;

        // The output `course_completions.id` values must be the same as `course_completions.id` in
        // helper-projects/GS-1212-correct-f2f-completion-times-to-session-finish-time/incorrect_completion_times_configurable_report.sql
        $targetcoursecompletionsql = "
            SELECT DISTINCT
                    course_completions.id,
                    course_completions.course,
                    course_completions.userid

            FROM (
                SELECT
                    candidate_rows.courseid,
                    candidate_rows.coursemoduleid,
                    candidate_rows.userid,
                    candidate_rows.marked_off_time AS wrong_time

                FROM (
                    SELECT
                        course_modules.id AS coursemoduleid,
                        course_modules.course AS courseid,
                        facetoface_sessions.id AS sessionid,
                        session_finish.session_finish,
                        attended_mark_per_signup.userid,
                        attended_mark_per_signup.marked_off_time

                    FROM
                        {course_modules} course_modules
                        JOIN {modules} modules ON
                            modules.id = course_modules.module AND
                            modules.name = 'facetoface'
                        JOIN {facetoface_sessions} facetoface_sessions ON
                            facetoface_sessions.facetoface = course_modules.instance
                        JOIN (
                            SELECT
                                facetoface_sessions_dates.sessionid,
                                MAX(facetoface_sessions_dates.timefinish) AS session_finish

                            FROM
                                {facetoface_sessions_dates} facetoface_sessions_dates

                            WHERE
                                facetoface_sessions_dates.timefinish > 0

                            GROUP BY
                                facetoface_sessions_dates.sessionid
                        ) session_finish ON
                            session_finish.sessionid = facetoface_sessions.id
                        JOIN (
                            SELECT
                                facetoface_signups.id AS signupid,
                                facetoface_signups.sessionid,
                                facetoface_signups.userid,
                                MAX(facetoface_signups_status.timecreated) AS marked_off_time

                            FROM
                                {facetoface_signups} facetoface_signups
                                JOIN {facetoface_signups_status} facetoface_signups_status ON
                                    facetoface_signups_status.signupid = facetoface_signups.id AND
                                    facetoface_signups_status.superceded = 0

                            WHERE
                                facetoface_signups_status.statuscode = 100

                            GROUP BY
                                facetoface_signups.id,
                                facetoface_signups.sessionid,
                                facetoface_signups.userid
                        ) attended_mark_per_signup ON
                            attended_mark_per_signup.sessionid = facetoface_sessions.id

                    WHERE
                        :beforets1 IS NULL OR
                        session_finish.session_finish < :beforets2
                ) candidate_rows

                GROUP BY
                    candidate_rows.courseid,
                    candidate_rows.coursemoduleid,
                    candidate_rows.userid,
                    candidate_rows.marked_off_time

                HAVING
                    MAX(candidate_rows.session_finish) <> candidate_rows.marked_off_time
            ) f2f_completion_fix

            JOIN {course_completions} course_completions ON
                course_completions.course = f2f_completion_fix.courseid AND
                course_completions.userid = f2f_completion_fix.userid AND
                course_completions.timecompleted = f2f_completion_fix.wrong_time

            ORDER BY
                course_completions.id
        ";
        $params = ['beforets1' => $beforets, 'beforets2' => $beforets];

        $recordset = $DB->get_recordset_sql($targetcoursecompletionsql, $params);

        foreach ($recordset as $record) {
            try {
                // The helper clears `timecompleted` and then reaggregates via Moodle's completion API.
                completion_util::recalculate_course_for_user((int) $record->course, (int) $record->userid);
                $processed++;
                mtrace(
                    'mod_facetoface reaggregate_course_completion_task processed ' .
                    'completion_id=' . (int) $record->id . ', ' .
                    'course=' . (int) $record->course . ', ' .
                    'user=' . (int) $record->userid
                );

            } catch (\Throwable $e) {
                $errors++;
                mtrace(
                    'mod_facetoface reaggregate_course_completion_task failed ' .
                    'completion_id=' . (int) $record->id . ', ' .
                    'course=' . (int) $record->course . ', ' .
                    'user=' . (int) $record->userid . ', ' .
                    'message=' . str_replace(["\r", "\n"], ' ', $e->getMessage())
                );
            }

            if (($processed + $errors) % self::PROGRESS_INTERVAL === 0) {
                \core_php_time_limit::raise();
                mtrace('mod_facetoface reaggregate_course_completion_task progress: ' .
                    $processed . ' processed, ' . $errors . ' errors');
            }
        }
        $recordset->close();

        mtrace('mod_facetoface reaggregate_course_completion_task finished: ' .
            $processed . ' processed, ' . $errors . ' errors');
    }
}
