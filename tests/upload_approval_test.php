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

/**
 * Tests approval handling for CSV booking uploads.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_facetoface\booking_manager
 * @covers     \mod_facetoface\booking_manager_bulk_attendance
 * @covers     ::facetoface_user_signup
 */
class upload_approval_test extends \advanced_testcase {

    /**
     * Set up each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Both CSV upload paths must book users directly into historical sessions.
     *
     * @dataProvider booking_manager_provider
     * @param bool $sitewide Whether to exercise the site-wide booking manager.
     */
    public function test_historical_csv_booking_bypasses_approval(bool $sitewide): void {
        global $DB;

        [$facetoface, $session, $user] = $this->create_booking_fixture(true);
        $manager = $this->create_booking_manager($sitewide, $facetoface, $session, $user);

        $errors = $manager->validate();
        $this->assertEmpty($errors);

        $sink = $this->redirectEmails();
        $this->assertTrue($manager->process($errors));
        $this->assertCount(0, $sink->get_messages());
        $sink->close();

        $signup = $DB->get_record(
            'facetoface_signups',
            ['sessionid' => $session->id, 'userid' => $user->id],
            '*',
            MUST_EXIST
        );
        $status = $DB->get_record(
            'facetoface_signups_status',
            ['signupid' => $signup->id, 'superceded' => 0],
            '*',
            MUST_EXIST
        );

        $this->assertSame(MDL_F2F_STATUS_BOOKED, (int) $status->statuscode);
        $this->assertSame(
            1,
            $DB->count_records('facetoface_signups_status', ['signupid' => $signup->id]),
            'A historical booking should not create an intermediate approval request.'
        );

        $attendees = facetoface_get_attendees($session->id);
        $this->assertArrayHasKey($user->id, $attendees);
    }

    /**
     * Attendance imports should record a real historical booking before the attendance outcome.
     *
     * @dataProvider booking_manager_provider
     * @param bool $sitewide Whether to exercise the site-wide booking manager.
     */
    public function test_historical_csv_attendance_bypasses_approval_during_prebooking(bool $sitewide): void {
        global $DB;

        [$facetoface, $session, $user] = $this->create_booking_fixture(true);
        $manager = $this->create_booking_manager($sitewide, $facetoface, $session, $user, 'fully_attended');

        $errors = $manager->validate();
        $this->assertEmpty($errors);

        $sink = $this->redirectEmails();
        $this->assertTrue($manager->process($errors));
        $this->assertCount(0, $sink->get_messages());
        $sink->close();

        $signup = $DB->get_record(
            'facetoface_signups',
            ['sessionid' => $session->id, 'userid' => $user->id],
            '*',
            MUST_EXIST
        );
        $statusrecords = $DB->get_records(
            'facetoface_signups_status',
            ['signupid' => $signup->id],
            'id ASC',
            'id, statuscode'
        );
        $statuses = array_map(fn($status) => (int) $status->statuscode, array_values($statusrecords));

        $this->assertSame(
            [MDL_F2F_STATUS_BOOKED, MDL_F2F_STATUS_FULLY_ATTENDED],
            $statuses
        );
        $grade = facetoface_get_grade($user->id, $facetoface->course, $facetoface->id);
        $this->assertEquals(100, $grade->grade);
    }

    /**
     * Future CSV bookings must continue to use the configured approval workflow.
     *
     * @dataProvider booking_manager_provider
     * @param bool $sitewide Whether to exercise the site-wide booking manager.
     */
    public function test_future_csv_booking_still_requires_approval(bool $sitewide): void {
        global $DB;

        [$facetoface, $session, $user, $linemanager] = $this->create_booking_fixture(false);
        $manager = $this->create_booking_manager($sitewide, $facetoface, $session, $user);

        $errors = $manager->validate();
        $this->assertEmpty($errors);

        $sink = $this->redirectEmails();
        $this->assertTrue($manager->process($errors));
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(2, $messages);
        $this->assertEqualsCanonicalizing(
            [$user->email, $linemanager->email],
            array_column($messages, 'to')
        );

        $signup = $DB->get_record(
            'facetoface_signups',
            ['sessionid' => $session->id, 'userid' => $user->id],
            '*',
            MUST_EXIST
        );
        $status = $DB->get_record(
            'facetoface_signups_status',
            ['signupid' => $signup->id, 'superceded' => 0],
            '*',
            MUST_EXIST
        );

        $this->assertSame(MDL_F2F_STATUS_REQUESTED, (int) $status->statuscode);
        $this->assertArrayNotHasKey($user->id, facetoface_get_attendees($session->id));
    }

    /**
     * Manual historical signups must retain the normal approval workflow.
     */
    public function test_manual_historical_booking_still_requires_approval(): void {
        global $DB;

        [$facetoface, $session, $user] = $this->create_booking_fixture(true);
        facetoface_user_signup(
            $session,
            $facetoface,
            $DB->get_record('course', ['id' => $facetoface->course], '*', MUST_EXIST),
            '',
            MDL_F2F_ICAL,
            MDL_F2F_STATUS_BOOKED,
            $user->id,
            false
        );

        $signup = $DB->get_record(
            'facetoface_signups',
            ['sessionid' => $session->id, 'userid' => $user->id],
            '*',
            MUST_EXIST
        );
        $status = $DB->get_record(
            'facetoface_signups_status',
            ['signupid' => $signup->id, 'superceded' => 0],
            '*',
            MUST_EXIST
        );

        $this->assertSame(MDL_F2F_STATUS_REQUESTED, (int) $status->statuscode);
    }

    /**
     * Provide both CSV booking-manager implementations.
     *
     * @return array[]
     */
    public static function booking_manager_provider(): array {
        return [
            'course-level upload' => [false],
            'site-wide upload' => [true],
        ];
    }

    /**
     * Create an approval-required Face-to-Face activity and dated session.
     *
     * @param bool $historical Whether the session should already have finished.
     * @return array The Face-to-Face activity, session, user and line-manager records.
     */
    private function create_booking_fixture(bool $historical): array {
        set_config('enableapprovals', '1', 'facetoface');

        /** @var \mod_facetoface_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_facetoface');
        $course = $this->getDataGenerator()->create_course();
        $facetoface = $generator->create_instance([
            'course' => $course->id,
            'approvalreqd' => 1,
            'confirmationsubject' => 'Booking confirmation',
            'confirmationmessage' => 'Your booking has been confirmed.',
            'requestsubject' => 'Booking request',
            'requestmessage' => 'Your booking request has been submitted.',
            'requestinstrmngr' => 'Please review this booking request.',
        ]);
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => F2F_MDL_MANAGERSEMAIL_FIELD,
            'name' => 'Line manager email',
        ]);
        $linemanager = $this->getDataGenerator()->create_user();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student', [
            'profile_field_' . F2F_MDL_MANAGERSEMAIL_FIELD => $linemanager->email,
        ]);

        $now = time();
        $timestart = $now + DAYSECS;
        $timefinish = $now + 2 * DAYSECS;
        if ($historical) {
            $timestart = $now - 2 * DAYSECS;
            $timefinish = $now - DAYSECS;
        }

        $session = $generator->create_session([
            'facetoface' => $facetoface->id,
            'capacity' => 5,
            'allowoverbook' => 0,
            'sessiondates' => [
                ['timestart' => $timestart, 'timefinish' => $timefinish],
            ],
        ]);

        $this->setAdminUser();

        return [$facetoface, $session, $user, $linemanager];
    }

    /**
     * Create and load one of the CSV booking managers.
     *
     * @param bool $sitewide Whether to create the site-wide manager.
     * @param \stdClass $facetoface Face-to-Face activity record.
     * @param \stdClass $session Session record.
     * @param \stdClass $user User record.
     * @param string $status CSV status to load.
     * @return booking_manager|booking_manager_bulk_attendance The loaded manager.
     */
    private function create_booking_manager(
        bool $sitewide,
        \stdClass $facetoface,
        \stdClass $session,
        \stdClass $user,
        string $status = 'booked'
    ) {
        if ($sitewide) {
            $manager = new booking_manager_bulk_attendance();
            $manager->load_from_array([
                (object) [
                    'Username' => $user->username,
                    'Session' => $session->id,
                    'Status' => $status,
                    'Discount Code' => '',
                    'Notification Type' => 'email',
                ],
            ]);

            return $manager;
        }

        $manager = new booking_manager($facetoface->id);
        $manager->load_from_array([
            (object) [
                'username' => $user->username,
                'session' => $session->id,
                'status' => $status,
                'discountcode' => '',
                'notificationtype' => 'email',
            ],
        ]);

        return $manager;
    }
}
