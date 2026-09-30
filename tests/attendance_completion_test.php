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

namespace mod_facetoface;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/facetoface/lib.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');

/**
 * Regression tests for attendance updates after an earlier completion.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::facetoface_take_attendance
 * @covers ::facetoface_take_individual_attendance
 */
class attendance_completion_test extends \advanced_testcase {
    /** Set up an attendance-only completion environment. */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablecompletion', 1);
        set_config('sessioncompletiondate', 1, 'facetoface');
        set_config('enablecompletionarchive', 0, 'local_lolcompletion');
    }

    /**
     * Build a course whose only completion criterion is attendance at one activity.
     *
     * @param int $requiredattendance Required attendance status.
     * @param int $tracking Completion tracking mode.
     * @return \stdClass
     */
    private function create_fixture(
        int $requiredattendance = MDL_F2F_STATUS_FULLY_ATTENDED,
        int $tracking = COMPLETION_TRACKING_AUTOMATIC
    ): \stdClass {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $student = $generator->create_and_enrol($course, 'student');
        $facetoface = $generator->get_plugin_generator('mod_facetoface')->create_instance([
            'course' => $course->id,
            'completion' => $tracking,
            'completionattendance' => $requiredattendance,
            'completiongradeitemnumber' => null,
            'completionusegrade' => 0,
            'completionpassgrade' => 0,
            'completionview' => 0,
            'signuptype' => MOD_FACETOFACE_SIGNUP_MULTIPLE,
            'multiplesignupmethod' => MOD_FACETOFACE_SIGNUP_MULTIPLE_PER_SESSION,
            'usercalentry' => 0,
            'showoncalendar' => 0,
        ]);
        $cm = get_coursemodule_from_instance('facetoface', $facetoface->id, $course->id);
        $this->assertNull($cm->completiongradeitemnumber);
        $criterion = new \completion_criteria_activity();
        $criteria = (object) ['id' => $course->id, 'criteria_activity' => [$cm->id => 1]];
        $criterion->update_config($criteria);
        $completion = new \completion_info($course);
        return (object) compact('course', 'student', 'facetoface', 'cm', 'criterion', 'completion');
    }

    /**
     * Create a past session and book the learner without sending notifications.
     *
     * @param \stdClass $fixture Course fixture.
     * @param int $finish Session finish time.
     * @return \stdClass
     */
    private function create_signup(\stdClass $fixture, int $finish): \stdClass {
        global $DB;
        $session = $this->getDataGenerator()->get_plugin_generator('mod_facetoface')->create_session([
            'facetoface' => $fixture->facetoface->id,
            'sessiondates' => [['timestart' => $finish - HOURSECS, 'timefinish' => $finish]],
        ]);
        $this->assertTrue(facetoface_user_signup($session, $fixture->facetoface, $fixture->course, '',
            MDL_F2F_TEXT, MDL_F2F_STATUS_BOOKED, $fixture->student->id, false));
        $signup = $DB->get_record('facetoface_signups', [
            'sessionid' => $session->id, 'userid' => $fixture->student->id,
        ], '*', MUST_EXIST);
        return (object) ['session' => $session, 'signup' => $signup, 'finish' => $finish];
    }

    /**
     * Save through the attendance entry point used by the attendance form.
     *
     * @param \stdClass $booking Session and signup.
     * @param int $status Attendance status, rather than a raw grade.
     */
    private function take_attendance(\stdClass $booking, int $status): void {
        $this->assertTrue(facetoface_take_attendance((object) [
            's' => $booking->session->id,
            'submissionid_' . $booking->signup->id => $status,
        ]));
    }

    /**
     * Model an older or uploaded completion date without changing its attendance.
     *
     * @param \stdClass $fixture Course fixture.
     * @param int $timestamp Completion timestamp.
     */
    private function set_completion_date(\stdClass $fixture, int $timestamp): void {
        global $DB;
        $activity = $fixture->completion->get_data($fixture->cm, false, $fixture->student->id);
        $this->assertEquals(COMPLETION_COMPLETE, $activity->completionstate);
        $DB->set_field('course_modules_completion', 'timemodified', $timestamp, ['id' => $activity->id]);
        $criterion = $fixture->completion->get_user_completion($fixture->student->id, $fixture->criterion);
        $criterion->mark_complete($timestamp);
        $DB->set_field('course_completions', 'timecompleted', $timestamp, [
            'course' => $fixture->course->id, 'userid' => $fixture->student->id,
        ]);
        \cache::make('core', 'completion')->purge();
        \cache::make('core', 'coursecompletion')->purge();
    }

    /**
     * Read persisted completion records, including their IDs and timestamps.
     *
     * @param \stdClass $fixture Course fixture.
     * @return array
     */
    private function completion_records(\stdClass $fixture): array {
        global $DB;
        $params = ['course' => $fixture->course->id, 'userid' => $fixture->student->id];
        return [
            $DB->get_record('course_modules_completion', [
                'coursemoduleid' => $fixture->cm->id, 'userid' => $fixture->student->id,
            ], '*', MUST_EXIST),
            $DB->get_record('course_completion_crit_compl', $params, '*', MUST_EXIST),
            $DB->get_record('course_completions', $params, '*', MUST_EXIST),
        ];
    }

    /**
     * Attendance which cannot meet the full-attendance rule, with either date setting.
     *
     * @return array
     */
    public static function insufficient_attendance_provider(): array {
        return [
            'no show, session time' => [MDL_F2F_STATUS_NO_SHOW, 1],
            'no show, mark-off time' => [MDL_F2F_STATUS_NO_SHOW, 0],
            'partial, session time' => [MDL_F2F_STATUS_PARTIALLY_ATTENDED, 1],
            'partial, mark-off time' => [MDL_F2F_STATUS_PARTIALLY_ATTENDED, 0],
        ];
    }

    /**
     * A still-valid completion must not be changed by an unsuccessful later session.
     *
     * @dataProvider insufficient_attendance_provider
     * @param int $status Later attendance status.
     * @param int $sessiondate Whether to use session finish for completion.
     */
    public function test_existing_completion_is_preserved(int $status, int $sessiondate): void {
        $fixture = $this->create_fixture();
        $old = $this->create_signup($fixture, time() - 60 * DAYSECS);
        $this->take_attendance($old, MDL_F2F_STATUS_FULLY_ATTENDED);
        $this->set_completion_date($fixture, $old->finish - HOURSECS);
        $new = $this->create_signup($fixture, time() - DAYSECS);
        $before = $this->completion_records($fixture);
        set_config('sessioncompletiondate', $sessiondate, 'facetoface');

        $this->take_attendance($new, $status);

        $this->assertEquals($before, $this->completion_records($fixture));
    }

    /**
     * A reset must not be undone using a previous session's successful attendance.
     *
     * @dataProvider insufficient_attendance_provider
     * @param int $status Later attendance status.
     * @param int $sessiondate Whether to use session finish for completion.
     */
    public function test_unsuccessful_attendance_does_not_recomplete_after_reset(int $status, int $sessiondate): void {
        global $DB;
        $fixture = $this->create_fixture();
        $old = $this->create_signup($fixture, time() - 60 * DAYSECS);
        $this->take_attendance($old, MDL_F2F_STATUS_FULLY_ATTENDED);
        $fixture->completion->delete_all_completion_data();
        $this->assertTrue($DB->record_exists('facetoface_signups_status', [
            'signupid' => $old->signup->id, 'statuscode' => MDL_F2F_STATUS_FULLY_ATTENDED, 'superceded' => 0,
        ]));
        $new = $this->create_signup($fixture, time() - DAYSECS);
        set_config('sessioncompletiondate', $sessiondate, 'facetoface');

        $this->take_attendance($new, $status);

        $actual = $fixture->completion->get_data($fixture->cm, false, $fixture->student->id);
        $this->assertEquals(COMPLETION_INCOMPLETE, $actual->completionstate);
        $this->assertEmpty($actual->timemodified);
        $params = ['course' => $fixture->course->id, 'userid' => $fixture->student->id];
        $this->assertEmpty($DB->get_field('course_completions', 'timecompleted', $params));
        $this->assertFalse($DB->record_exists('course_completion_crit_compl', $params));
    }

    /**
     * Correcting the only successful attendance must still invalidate the activity.
     *
     * @dataProvider insufficient_attendance_provider
     * @param int $status Corrected attendance status.
     * @param int $sessiondate Whether to use session finish for completion.
     */
    public function test_correcting_only_successful_attendance_invalidates_activity(int $status, int $sessiondate): void {
        $fixture = $this->create_fixture();
        $booking = $this->create_signup($fixture, time() - DAYSECS);
        $this->take_attendance($booking, MDL_F2F_STATUS_FULLY_ATTENDED);
        set_config('sessioncompletiondate', $sessiondate, 'facetoface');

        $this->take_attendance($booking, $status);

        $actual = $fixture->completion->get_data($fixture->cm, false, $fixture->student->id);
        $this->assertEquals(COMPLETION_INCOMPLETE, $actual->completionstate);
    }

    /**
     * Qualifying attendance and supported completion date modes.
     *
     * @return array
     */
    public static function qualifying_attendance_provider(): array {
        return [
            'full, session time' => [MDL_F2F_STATUS_FULLY_ATTENDED, 1],
            'full, mark-off time' => [MDL_F2F_STATUS_FULLY_ATTENDED, 0],
            'partial, session time' => [MDL_F2F_STATUS_PARTIALLY_ATTENDED, 1],
            'partial, mark-off time' => [MDL_F2F_STATUS_PARTIALLY_ATTENDED, 0],
        ];
    }

    /**
     * A successful new attendance can recomplete, including allowed partial attendance.
     *
     * @dataProvider qualifying_attendance_provider
     * @param int $status Required and submitted attendance status.
     * @param int $sessiondate Whether to use session finish for completion.
     */
    public function test_qualifying_attendance_recompletes(int $status, int $sessiondate): void {
        $fixture = $this->create_fixture($status);
        $old = $this->create_signup($fixture, time() - 60 * DAYSECS);
        $this->take_attendance($old, MDL_F2F_STATUS_FULLY_ATTENDED);
        $fixture->completion->delete_all_completion_data();
        $new = $this->create_signup($fixture, time() - DAYSECS);
        set_config('sessioncompletiondate', $sessiondate, 'facetoface');
        $before = time();

        $this->take_attendance($new, $status);

        $actual = $fixture->completion->get_data($fixture->cm, false, $fixture->student->id);
        $this->assertEquals(COMPLETION_COMPLETE, $actual->completionstate);
        if ($sessiondate) {
            [$activity, $criterion, $course] = $this->completion_records($fixture);
            $this->assertEquals($new->finish, $activity->timemodified);
            $this->assertEquals($new->finish, $criterion->timecompleted);
            $this->assertEquals($new->finish, $course->timecompleted);
        } else {
            $this->assertGreaterThanOrEqual($before, $actual->timemodified);
            $this->assertLessThanOrEqual(time(), $actual->timemodified);
        }
    }

    /**
     * The original mismatch must not invoke automatic completion archiving.
     *
     * @dataProvider insufficient_attendance_provider
     * @param int $status Later attendance status.
     * @param int $sessiondate Whether to use session finish for completion.
     */
    public function test_unsuccessful_attendance_does_not_archive_valid_completion(int $status, int $sessiondate): void {
        global $DB;
        if (!class_exists(\local_lolcompletion\local\api\completion::class) ||
                !class_exists(\local_recompletion\task\check_recompletion::class)) {
            $this->markTestSkipped('The automatic archiving integration requires LOL Completion and Recompletion.');
        }
        $fixture = $this->create_fixture();
        $old = $this->create_signup($fixture, time() - 60 * DAYSECS);
        $this->take_attendance($old, MDL_F2F_STATUS_FULLY_ATTENDED);
        $this->set_completion_date($fixture, $old->finish - HOURSECS);
        foreach (['recompletiontype' => 'period', 'recompletionduration' => YEARSECS,
                'archivecompletiondata' => 1] as $name => $value) {
            $DB->insert_record('local_recompletion_config', (object) [
                'course' => $fixture->course->id, 'name' => $name, 'value' => $value,
            ]);
        }
        set_config('enablecompletionarchive', 1, 'local_lolcompletion');
        set_config('sessioncompletiondate', $sessiondate, 'facetoface');
        $new = $this->create_signup($fixture, time() - DAYSECS);
        $this->assertTrue(\local_lolcompletion\local\api\completion::has_f2f_attendance_after_completion(
            $fixture->course->id, $fixture->cm->id, $fixture->student->id));
        $before = $this->completion_records($fixture);

        $this->take_attendance($new, $status);

        $this->assertEquals($before, $this->completion_records($fixture));
        $params = ['course' => $fixture->course->id, 'userid' => $fixture->student->id];
        foreach (['local_recompletion_cc', 'local_recompletion_cc_cc', 'local_recompletion_cmc'] as $table) {
            $this->assertFalse($DB->record_exists($table, $params));
        }
    }

    /** An automatic completion overridden by a teacher must stay completed. */
    public function test_unsuccessful_attendance_preserves_completion_override(): void {
        $fixture = $this->create_fixture();
        $booking = $this->create_signup($fixture, time() - DAYSECS);
        $fixture->completion->update_state($fixture->cm, COMPLETION_COMPLETE, $fixture->student->id, true);
        $before = $fixture->completion->get_data($fixture->cm, false, $fixture->student->id);
        $this->assertNotEmpty($before->overrideby);

        $this->take_attendance($booking, MDL_F2F_STATUS_NO_SHOW);

        $actual = $fixture->completion->get_data($fixture->cm, false, $fixture->student->id);
        $this->assertEquals(COMPLETION_COMPLETE, $actual->completionstate);
        $this->assertEquals($before->timemodified, $actual->timemodified);
        $this->assertEquals($before->overrideby, $actual->overrideby);
    }

    /** Attendance must not alter a manually tracked completion even with a stale custom rule. */
    public function test_unsuccessful_attendance_preserves_manual_completion(): void {
        $fixture = $this->create_fixture(MDL_F2F_STATUS_FULLY_ATTENDED, COMPLETION_TRACKING_MANUAL);
        $booking = $this->create_signup($fixture, time() - DAYSECS);
        $fixture->completion->update_state($fixture->cm, COMPLETION_COMPLETE, $fixture->student->id);
        $before = $fixture->completion->get_data($fixture->cm, false, $fixture->student->id);

        $this->take_attendance($booking, MDL_F2F_STATUS_NO_SHOW);

        $actual = $fixture->completion->get_data($fixture->cm, false, $fixture->student->id);
        $this->assertEquals(COMPLETION_COMPLETE, $actual->completionstate);
        $this->assertEquals($before->timemodified, $actual->timemodified);
    }
}
