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
 * Behat steps for Face-to-Face.
 *
 * @package    mod_facetoface
 * @category   test
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by Behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;

/**
 * Face-to-Face step definitions.
 *
 * @package    mod_facetoface
 * @category   test
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_facetoface extends behat_base {

    /**
     * Make an activity an activity-based course completion criterion.
     *
     * This is fixture setup rather than a browser action so the visible scenario can begin by
     * showing the session settings instead of the course-completion configuration form.
     *
     * @Given course completion for :course requires completion of :activity
     * @param string $course Course short name.
     * @param string $activity Activity name.
     * @return void
     * @throws ExpectationException If the course or activity cannot be found unambiguously.
     */
    public function course_completion_requires_activity(string $course, string $activity): void {
        global $CFG, $DB;

        $courserecord = $DB->get_record('course', ['shortname' => $course]);
        if (!$courserecord) {
            throw new ExpectationException(
                "Unable to find a course with the short name '$course'.",
                $this->getSession()
            );
        }

        $matches = [];
        foreach (get_fast_modinfo($courserecord)->get_cms() as $cm) {
            if ($cm->get_formatted_name() === $activity) {
                $matches[] = $cm;
            }
        }
        if (count($matches) !== 1) {
            throw new ExpectationException(
                "Expected exactly one activity named '$activity' in '$course', but found " . count($matches) . '.',
                $this->getSession()->getDriver()
            );
        }
        $coursemodule = reset($matches);

        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');

        $criteriadata = (object) [
            'id' => $courserecord->id,
            'criteria_activity' => [$coursemodule->id => 1],
        ];
        $criterion = new completion_criteria_activity();
        $criterion->update_config($criteriadata);

        require_once($CFG->dirroot . '/completion/completion_aggregation.php');
        foreach ([null, COMPLETION_CRITERIA_TYPE_ACTIVITY] as $criteriatype) {
            $aggregation = new completion_aggregation([
                'course' => $courserecord->id,
                'criteriatype' => $criteriatype,
            ]);
            $aggregation->setMethod(COMPLETION_AGGREGATION_ALL);
            $aggregation->save();
        }
    }

    /**
     * Create and upload a one-record booking CSV for an activity's only session.
     *
     * Behat intentionally assigns non-deterministic database sequence values, so the generated
     * session ID cannot be stored in a static fixture.
     *
     * @When I upload a :status booking CSV for :username to :activity using the :filemanager filemanager
     * @param string $status Booking status for the CSV record.
     * @param string $username User name for the CSV record.
     * @param string $activity Face-to-Face activity name.
     * @param string $filemanager File manager field label.
     * @return void
     * @throws ExpectationException If the activity, session, or temporary CSV cannot be created unambiguously.
     */
    public function upload_booking_csv_for_session(
        string $status,
        string $username,
        string $activity,
        string $filemanager
    ): void {
        global $DB;

        $activities = $DB->get_records('facetoface', ['name' => $activity], '', 'id');
        if (count($activities) !== 1) {
            throw new ExpectationException(
                "Expected exactly one Face-to-Face activity named '$activity', but found " . count($activities) . '.',
                $this->getSession()
            );
        }

        $facetoface = reset($activities);
        $sessions = $DB->get_records('facetoface_sessions', ['facetoface' => $facetoface->id], '', 'id');
        if (count($sessions) !== 1) {
            throw new ExpectationException(
                "Expected exactly one session in '$activity', but found " . count($sessions) . '.',
                $this->getSession()
            );
        }

        $session = reset($sessions);
        $tempdir = make_request_directory();
        $filepath = $tempdir . '/facetoface-booking.csv';
        $handle = fopen($filepath, 'w');
        if ($handle === false) {
            throw new ExpectationException('Unable to create the temporary booking CSV.', $this->getSession());
        }

        try {
            $headerwritten = fputcsv(
                $handle,
                ['Username', 'Session', 'Status', 'Discount Code', 'Notification Type'],
                ',',
                '"',
                ''
            );
            $recordwritten = fputcsv($handle, [$username, $session->id, $status, '', ''], ',', '"', '');
            if ($headerwritten === false || $recordwritten === false) {
                throw new ExpectationException('Unable to write the temporary booking CSV.', $this->getSession());
            }
        } finally {
            fclose($handle);
        }

        $uploadcontext = behat_context_helper::get('behat_repository_upload');
        $uploadcontext->i_upload_file_to_filemanager($filepath, $filemanager);
    }

    /**
     * Seed active bookings without sending notifications.
     *
     * This is used for cancellation rows because cancelling a user who has no booking is a no-op.
     *
     * @Given the following Face-to-Face bookings exist:
     * @param TableNode $table Booking fixture rows.
     * @return void
     */
    public function the_following_facetoface_bookings_exist(TableNode $table): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/facetoface/lib.php');

        $this->require_table_headers($table, ['username', 'activity', 'timing']);
        foreach ($table->getHash() as $row) {
            $user = $DB->get_record('user', ['username' => $row['username']], '*', MUST_EXIST);
            $facetoface = $this->get_facetoface_by_name($row['activity']);
            $course = $DB->get_record('course', ['id' => $facetoface->course], '*', MUST_EXIST);
            $session = $this->get_session_by_timing($facetoface, $row['timing']);
            $context = context_course::instance($course->id);

            if (!facetoface_enrol_user($context, $course->id, $user->id)) {
                throw new ExpectationException(
                    "Unable to enrol '{$row['username']}' in '{$course->shortname}'.",
                    $this->getSession()->getDriver()
                );
            }

            facetoface_user_signup(
                $session,
                $facetoface,
                $course,
                '',
                MDL_F2F_TEXT,
                MDL_F2F_STATUS_BOOKED,
                $user->id,
                false
            );
        }
    }

    /**
     * Build and upload a site-wide booking CSV from human-readable activity and timing values.
     *
     * @When I upload the following booking CSV using the :filemanager filemanager:
     * @param string $filemanager File manager field label.
     * @param TableNode $table CSV source rows.
     * @return void
     */
    public function upload_booking_csv_table(string $filemanager, TableNode $table): void {
        $this->require_table_headers(
            $table,
            ['username', 'activity', 'timing', 'status', 'notification']
        );

        $tempdir = make_request_directory();
        $filepath = $tempdir . '/facetoface-booking-matrix.csv';
        $handle = fopen($filepath, 'w');
        if ($handle === false) {
            throw new ExpectationException(
                'Unable to create the temporary booking CSV.',
                $this->getSession()->getDriver()
            );
        }

        try {
            $written = fputcsv(
                $handle,
                ['Username', 'Session', 'Status', 'Discount Code', 'Notification Type'],
                ',',
                '"',
                ''
            );
            foreach ($table->getHash() as $row) {
                $facetoface = $this->get_facetoface_by_name($row['activity']);
                $session = $this->get_session_by_timing($facetoface, $row['timing']);
                $written = $written !== false && fputcsv(
                    $handle,
                    [$row['username'], $session->id, $row['status'], '', $row['notification']],
                    ',',
                    '"',
                    ''
                ) !== false;
            }
            if (!$written) {
                throw new ExpectationException(
                    'Unable to write the temporary booking CSV.',
                    $this->getSession()->getDriver()
                );
            }
        } finally {
            fclose($handle);
        }

        $uploadcontext = behat_context_helper::get('behat_repository_upload');
        $uploadcontext->i_upload_file_to_filemanager($filepath, $filemanager);
    }

    /**
     * Build and upload a course-scoped booking CSV from human-readable activity and timing values.
     *
     * The course upload parser uses its legacy lower-case, unspaced headers, unlike the
     * site-administration upload parser.
     *
     * @When I upload the following course booking CSV using the :filemanager filemanager:
     * @param string $filemanager File manager field label.
     * @param TableNode $table CSV source rows.
     * @return void
     */
    public function upload_course_booking_csv_table(string $filemanager, TableNode $table): void {
        $this->require_table_headers(
            $table,
            ['username', 'activity', 'timing', 'status', 'notification']
        );

        $tempdir = make_request_directory();
        $filepath = $tempdir . '/facetoface-course-booking-matrix.csv';
        $handle = fopen($filepath, 'w');
        if ($handle === false) {
            throw new ExpectationException(
                'Unable to create the temporary course booking CSV.',
                $this->getSession()->getDriver()
            );
        }

        try {
            $written = fputcsv(
                $handle,
                ['username', 'session', 'status', 'discountcode', 'notificationtype'],
                ',',
                '"',
                ''
            );
            foreach ($table->getHash() as $row) {
                $facetoface = $this->get_facetoface_by_name($row['activity']);
                $session = $this->get_session_by_timing($facetoface, $row['timing']);
                $written = $written !== false && fputcsv(
                    $handle,
                    [$row['username'], $session->id, $row['status'], '', $row['notification']],
                    ',',
                    '"',
                    ''
                ) !== false;
            }
            if (!$written) {
                throw new ExpectationException(
                    'Unable to write the temporary course booking CSV.',
                    $this->getSession()->getDriver()
                );
            }
        } finally {
            fclose($handle);
        }

        $uploadcontext = behat_context_helper::get('behat_repository_upload');
        $uploadcontext->i_upload_file_to_filemanager($filepath, $filemanager);
    }

    /**
     * Assert every persisted outcome from the booking upload matrix.
     *
     * The two courses use the same learner/status/timing combinations. The first course is
     * completed by its Face-to-Face activity; the second has an additional incomplete criterion.
     *
     * @Then the booking upload outcomes for courses :sufficient and :insufficient should be:
     * @param string $sufficient Short name of the course whose Face-to-Face activity is sufficient.
     * @param string $insufficient Short name of the course with an additional completion criterion.
     * @param TableNode $table Expected outcomes.
     * @return void
     */
    public function booking_upload_outcomes_should_be(
        string $sufficient,
        string $insufficient,
        TableNode $table
    ): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/facetoface/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/enrollib.php');

        $this->require_table_headers($table, [
            'username',
            'timing',
            'result',
            'status',
            'history',
            'enrolled',
            'activity',
            'sufficientcourse',
            'insufficientcourse',
            'email',
        ]);

        $courses = [
            'sufficient' => $DB->get_record('course', ['shortname' => $sufficient], '*', MUST_EXIST),
            'insufficient' => $DB->get_record('course', ['shortname' => $insufficient], '*', MUST_EXIST),
        ];
        $activities = [
            'sufficient' => $this->get_only_facetoface_in_course($courses['sufficient']),
            'insufficient' => $this->get_only_facetoface_in_course($courses['insufficient']),
        ];
        $messages = $this->get_caught_emails();

        foreach ($table->getHash() as $row) {
            $user = $DB->get_record('user', ['username' => $row['username']], '*', MUST_EXIST);
            if (!in_array($row['result'], ['accepted', 'rejected', 'accepted (known defect)'], true)) {
                throw new ExpectationException(
                    "Unknown expected import result '{$row['result']}'.",
                    $this->getSession()->getDriver()
                );
            }

            foreach ($courses as $coursetype => $course) {
                $facetoface = $activities[$coursetype];
                $session = $this->get_session_by_timing($facetoface, $row['timing']);
                $label = "{$row['username']} / {$course->shortname} / {$row['timing']} / {$row['result']}";

                $this->assert_enrolment($course, $user, $row['enrolled'], $label);
                $this->assert_signup($session, $user, $row['status'], (int) $row['history'], $label);
                $this->assert_activity_completion($course, $facetoface, $user, $row['activity'], $label);

                $courseexpectation = $coursetype === 'sufficient'
                    ? $row['sufficientcourse']
                    : $row['insufficientcourse'];
                $this->assert_course_completion($course, $user, $courseexpectation, $label);
            }

            $this->assert_email_outcome($messages, $user, $activities, $row['email']);
        }
    }

    /**
     * Require an exact set and order of Behat table headers.
     *
     * @param TableNode $table Behat table.
     * @param string[] $expected Expected headers.
     * @return void
     */
    private function require_table_headers(TableNode $table, array $expected): void {
        $rows = $table->getRows();
        $actual = $rows[0] ?? [];
        if ($actual !== $expected) {
            throw new ExpectationException(
                'Expected table headers "' . implode(' | ', $expected) . '", found "' .
                    implode(' | ', $actual) . '".',
                $this->getSession()->getDriver()
            );
        }
    }

    /**
     * Find one uniquely named Face-to-Face activity across the site.
     *
     * @param string $name Activity name.
     * @return stdClass
     */
    private function get_facetoface_by_name(string $name): stdClass {
        global $DB;

        $records = $DB->get_records('facetoface', ['name' => $name]);
        if (count($records) !== 1) {
            throw new ExpectationException(
                "Expected exactly one Face-to-Face activity named '$name', but found " . count($records) . '.',
                $this->getSession()->getDriver()
            );
        }

        return reset($records);
    }

    /**
     * Find the sole Face-to-Face activity in a course.
     *
     * @param stdClass $course Course record.
     * @return stdClass
     */
    private function get_only_facetoface_in_course(stdClass $course): stdClass {
        global $DB;

        $records = $DB->get_records('facetoface', ['course' => $course->id]);
        if (count($records) !== 1) {
            throw new ExpectationException(
                "Expected exactly one Face-to-Face activity in '{$course->shortname}', but found " .
                    count($records) . '.',
                $this->getSession()->getDriver()
            );
        }

        return reset($records);
    }

    /**
     * Resolve a session by its relative timing classification.
     *
     * @param stdClass $facetoface Face-to-Face record.
     * @param string $timing future, in_progress, historical, or undated.
     * @return stdClass
     */
    private function get_session_by_timing(stdClass $facetoface, string $timing): stdClass {
        global $DB;

        $matches = [];
        $now = time();
        $sessions = $DB->get_records('facetoface_sessions', ['facetoface' => $facetoface->id]);
        foreach ($sessions as $session) {
            if (empty($session->datetimeknown)) {
                $actualtiming = 'undated';
            } else {
                $dates = $DB->get_records('facetoface_sessions_dates', ['sessionid' => $session->id]);
                if (empty($dates)) {
                    throw new ExpectationException(
                        "Dated session {$session->id} has no date records.",
                        $this->getSession()->getDriver()
                    );
                }
                $starts = array_column(array_values($dates), 'timestart');
                $finishes = array_column(array_values($dates), 'timefinish');
                if (min($starts) > $now) {
                    $actualtiming = 'future';
                } else if (max($finishes) < $now) {
                    $actualtiming = 'historical';
                } else {
                    $actualtiming = 'in_progress';
                }
            }

            if ($actualtiming === $timing) {
                $matches[] = $session;
            }
        }

        if (count($matches) !== 1) {
            throw new ExpectationException(
                "Expected exactly one '$timing' session in '{$facetoface->name}', but found " . count($matches) . '.',
                $this->getSession()->getDriver()
            );
        }

        return facetoface_get_session(reset($matches)->id);
    }

    /**
     * Assert active enrolment state.
     *
     * @param stdClass $course Course record.
     * @param stdClass $user User record.
     * @param string $expected yes or no.
     * @param string $label Assertion label.
     * @return void
     */
    private function assert_enrolment(stdClass $course, stdClass $user, string $expected, string $label): void {
        $actual = is_enrolled(context_course::instance($course->id), $user->id, '', true) ? 'yes' : 'no';
        $this->assert_same($expected, $actual, "$label enrolment");
    }

    /**
     * Assert signup existence, current status, and complete status-history length.
     *
     * @param stdClass $session Session record.
     * @param stdClass $user User record.
     * @param string $expectedstatus Expected status alias.
     * @param int $expectedhistory Expected history length.
     * @param string $label Assertion label.
     * @return void
     */
    private function assert_signup(
        stdClass $session,
        stdClass $user,
        string $expectedstatus,
        int $expectedhistory,
        string $label
    ): void {
        global $DB;

        $signups = $DB->get_records('facetoface_signups', [
            'sessionid' => $session->id,
            'userid' => $user->id,
        ]);
        if ($expectedstatus === 'none') {
            $this->assert_same(0, count($signups), "$label signup count");
            $this->assert_same(0, $expectedhistory, "$label expected history for no signup");
            return;
        }

        $this->assert_same(1, count($signups), "$label signup count");
        $signup = reset($signups);
        $currentstatuses = $DB->get_records('facetoface_signups_status', [
            'signupid' => $signup->id,
            'superceded' => 0,
        ]);
        $this->assert_same(1, count($currentstatuses), "$label current status count");
        $currentstatus = reset($currentstatuses);

        $statuscodes = [
            'booked' => MDL_F2F_STATUS_BOOKED,
            'waitlisted' => MDL_F2F_STATUS_WAITLISTED,
            'cancelled' => MDL_F2F_STATUS_USER_CANCELLED,
            'no_show' => MDL_F2F_STATUS_NO_SHOW,
            'partially_attended' => MDL_F2F_STATUS_PARTIALLY_ATTENDED,
            'fully_attended' => MDL_F2F_STATUS_FULLY_ATTENDED,
        ];
        if (!array_key_exists($expectedstatus, $statuscodes)) {
            throw new ExpectationException(
                "Unknown expected booking status '$expectedstatus'.",
                $this->getSession()->getDriver()
            );
        }

        $this->assert_same($statuscodes[$expectedstatus], (int) $currentstatus->statuscode, "$label status");
        $actualhistory = $DB->count_records('facetoface_signups_status', ['signupid' => $signup->id]);
        $this->assert_same($expectedhistory, $actualhistory, "$label history count");
    }

    /**
     * Assert activity completion state.
     *
     * @param stdClass $course Course record.
     * @param stdClass $facetoface Face-to-Face record.
     * @param stdClass $user User record.
     * @param string $expected complete or incomplete.
     * @param string $label Assertion label.
     * @return void
     */
    private function assert_activity_completion(
        stdClass $course,
        stdClass $facetoface,
        stdClass $user,
        string $expected,
        string $label
    ): void {
        $cm = get_coursemodule_from_instance('facetoface', $facetoface->id, $course->id, false, MUST_EXIST);
        $completion = new completion_info($course);
        $data = $completion->get_data($cm, false, $user->id);
        $actual = (int) $data->completionstate === COMPLETION_COMPLETE ? 'complete' : 'incomplete';
        $this->assert_same($expected, $actual, "$label activity completion");
    }

    /**
     * Assert stored course completion state without triggering recalculation.
     *
     * @param stdClass $course Course record.
     * @param stdClass $user User record.
     * @param string $expected complete or incomplete.
     * @param string $label Assertion label.
     * @return void
     */
    private function assert_course_completion(
        stdClass $course,
        stdClass $user,
        string $expected,
        string $label
    ): void {
        $completion = new completion_info($course);
        $actual = $completion->is_course_complete($user->id) ? 'complete' : 'incomplete';
        $this->assert_same($expected, $actual, "$label course completion");
    }

    /**
     * Fetch full messages from the configured Mailpit service once for the matrix.
     *
     * @return array
     */
    private function get_caught_emails(): array {
        if (!defined('TEST_EMAILCATCHER_API_SERVER')) {
            throw new ExpectationException(
                'TEST_EMAILCATCHER_API_SERVER is not configured.',
                $this->getSession()->getDriver()
            );
        }

        $catcher = new \core\test\mailpit_email_catcher(TEST_EMAILCATCHER_API_SERVER);
        return iterator_to_array($catcher->get_messages(true), false);
    }

    /**
     * Assert the exact Face-to-Face messages sent to a learner.
     *
     * @param array $messages All caught messages.
     * @param stdClass $user User record.
     * @param stdClass[] $activities The activity in each course.
     * @param string $expected none, confirmation, waitlist, or cancellation.
     * @return void
     */
    private function assert_email_outcome(
        array $messages,
        stdClass $user,
        array $activities,
        string $expected
    ): void {
        $usermessages = array_values(array_filter(
            $messages,
            static fn($message): bool => $message->has_recipient($user->email)
        ));
        $expectedcount = $expected === 'none' ? 0 : count($activities);
        $this->assert_same($expectedcount, count($usermessages), "{$user->username} email count");
        if ($expected === 'none') {
            return;
        }

        $subjectprefixes = [
            'confirmation' => 'Booking confirmation: ',
            'waitlist' => 'Waitlist notification: ',
            'cancellation' => 'Booking cancellation: ',
        ];
        if (!array_key_exists($expected, $subjectprefixes)) {
            throw new ExpectationException(
                "Unknown expected email outcome '$expected'.",
                $this->getSession()->getDriver()
            );
        }

        foreach ($activities as $facetoface) {
            $expectedsubject = $subjectprefixes[$expected] . $facetoface->name;
            $matching = array_values(array_filter(
                $usermessages,
                static fn($message): bool => str_contains($message->get_subject(), $expectedsubject)
            ));
            $this->assert_same(1, count($matching), "{$user->username} '$expectedsubject' email count");
            $message = reset($matching);
            $body = ($message->get_body_text() ?? '') . ($message->get_body_html() ?? '');
            if (!str_contains($body, 'Face-to-Face matrix notification')) {
                throw new ExpectationException(
                    "{$user->username} '$expectedsubject' email did not contain the expected body text.",
                    $this->getSession()->getDriver()
                );
            }
        }
    }

    /**
     * Compare two scalar values and provide the matrix cell in failures.
     *
     * @param mixed $expected Expected value.
     * @param mixed $actual Actual value.
     * @param string $label Assertion label.
     * @return void
     */
    private function assert_same($expected, $actual, string $label): void {
        if ($expected !== $actual) {
            throw new ExpectationException(
                "$label: expected '" . var_export($expected, true) . "', found '" . var_export($actual, true) . "'.",
                $this->getSession()->getDriver()
            );
        }
    }
}
