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

// GCHLOL: Cover duplicate-row, capacity, signup-history, and unsupported-status validation
// for both CSV booking-manager implementations.
/**
 * Tests duplicate-user validation for CSV booking uploads.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_facetoface\booking_manager
 * @covers     \mod_facetoface\booking_manager_bulk_attendance
 */
class upload_duplicate_test extends \advanced_testcase {

    /**
     * Set up each test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A booking upload must not overwrite any existing session status.
     *
     * @dataProvider existing_status_provider
     * @param bool $sitewide Whether to use the site-wide manager.
     * @param int $existingstatus Existing current signup status.
     * @return void
     */
    public function test_existing_signup_booking_is_skipped(bool $sitewide, int $existingstatus): void {
        global $DB;

        [$course, $facetoface, $session, $existinguser, $newuser] = $this->create_fixture(3, true);
        $signup = $this->create_signup_with_status(
            $course,
            $facetoface,
            $session,
            $existinguser,
            $existingstatus
        );
        $statusbefore = $this->get_current_status($signup->id);
        $historycountbefore = $DB->count_records('facetoface_signups_status', ['signupid' => $signup->id]);
        $gradebefore = facetoface_get_grade($existinguser->id, $course->id, $facetoface->id)->grade;

        $manager = $this->create_manager($sitewide, $facetoface, [
            $this->create_row($sitewide, $existinguser, $session, 'booked'),
            $this->create_row($sitewide, $newuser, $session, 'booked'),
        ]);
        $errors = $manager->validate();

        $this->assert_row_has_error(
            $errors,
            1,
            get_string('error:useralreadyinsession', 'mod_facetoface', (object)[
                'user' => $existinguser->username,
                'session' => $session->id,
            ])
        );
        $this->assert_row_has_no_error($errors, 2);
        $this->assertTrue($manager->process($errors));

        $statusafter = $this->get_current_status($signup->id);
        $this->assertSame($statusbefore->id, $statusafter->id);
        $this->assertSame($existingstatus, (int)$statusafter->statuscode);
        $this->assertSame(
            $historycountbefore,
            $DB->count_records('facetoface_signups_status', ['signupid' => $signup->id])
        );
        $this->assertEquals(
            $gradebefore,
            facetoface_get_grade($existinguser->id, $course->id, $facetoface->id)->grade
        );

        $newsignup = $DB->get_record(
            'facetoface_signups',
            ['sessionid' => $session->id, 'userid' => $newuser->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame(MDL_F2F_STATUS_BOOKED, (int)$this->get_current_status($newsignup->id)->statuscode);
    }

    /**
     * Blank, booked and waitlisted rows are all attempts to add an existing user again.
     *
     * @dataProvider booking_status_provider
     * @param bool $sitewide Whether to use the site-wide manager.
     * @param string $uploadstatus Booking status supplied by the CSV.
     * @return void
     */
    public function test_each_booking_status_rejects_existing_signup(bool $sitewide, string $uploadstatus): void {
        [$course, $facetoface, $session, $existinguser] = $this->create_fixture(2, false);
        $this->create_signup_with_status(
            $course,
            $facetoface,
            $session,
            $existinguser,
            MDL_F2F_STATUS_BOOKED
        );

        $manager = $this->create_manager($sitewide, $facetoface, [
            $this->create_row($sitewide, $existinguser, $session, $uploadstatus),
        ]);
        $errors = $manager->validate();

        $this->assert_row_has_error(
            $errors,
            1,
            get_string('error:useralreadyinsession', 'mod_facetoface', (object)[
                'user' => $existinguser->username,
                'session' => $session->id,
            ])
        );
    }

    /**
     * Only the first valid user/session row reserves capacity and is processed.
     *
     * @dataProvider duplicate_order_provider
     * @param bool $sitewide Whether to use the site-wide manager.
     * @param string $firststatus Status in the first duplicate row.
     * @param int $expectedstatus Expected final status for the duplicate user.
     * @return void
     */
    public function test_later_duplicate_row_is_skipped_without_consuming_capacity(
        bool $sitewide,
        string $firststatus,
        int $expectedstatus
    ): void {
        global $DB;

        [$course, $facetoface, $session, $firstuser, $seconduser] = $this->create_fixture(2, true);
        $secondstatus = $firststatus === 'booked' ? 'fully_attended' : 'booked';
        $manager = $this->create_manager($sitewide, $facetoface, [
            $this->create_row($sitewide, $firstuser, $session, $firststatus),
            $this->create_row($sitewide, $firstuser, $session, $secondstatus),
            $this->create_row($sitewide, $seconduser, $session, 'booked'),
        ]);
        $errors = $manager->validate();

        $this->assertCount(1, $errors);
        $this->assert_row_has_error(
            $errors,
            2,
            get_string('error:duplicateuserinsessionupload', 'mod_facetoface', (object)[
                'user' => $firstuser->username,
                'session' => $session->id,
            ])
        );
        $this->assert_row_has_no_error($errors, 1);
        $this->assert_row_has_no_error($errors, 3);
        $this->assertTrue($manager->process($errors));

        $firstsignup = $DB->get_record(
            'facetoface_signups',
            ['sessionid' => $session->id, 'userid' => $firstuser->id],
            '*',
            MUST_EXIST
        );
        $secondsignup = $DB->get_record(
            'facetoface_signups',
            ['sessionid' => $session->id, 'userid' => $seconduser->id],
            '*',
            MUST_EXIST
        );

        $this->assertSame($expectedstatus, (int)$this->get_current_status($firstsignup->id)->statuscode);
        $this->assertSame(MDL_F2F_STATUS_BOOKED, (int)$this->get_current_status($secondsignup->id)->statuscode);
        if ($expectedstatus === MDL_F2F_STATUS_FULLY_ATTENDED) {
            $this->assertEquals(100, facetoface_get_grade($firstuser->id, $course->id, $facetoface->id)->grade);
        }
    }

    /**
     * When distinct valid rows exceed capacity, only the rows which do not fit are skipped.
     *
     * @dataProvider manager_provider
     * @param bool $sitewide Whether to use the site-wide manager.
     * @return void
     */
    public function test_capacity_error_skips_only_overflow_row(bool $sitewide): void {
        global $DB;

        [, $facetoface, $session, $firstuser, $seconduser] = $this->create_fixture(1, true);
        $manager = $this->create_manager($sitewide, $facetoface, [
            $this->create_row($sitewide, $firstuser, $session, 'booked'),
            $this->create_row($sitewide, $seconduser, $session, 'booked'),
        ]);
        $errors = $manager->validate();

        $this->assertCount(1, $errors);
        $this->assert_row_has_no_error($errors, 1);
        $this->assert_row_has_error(
            $errors,
            2,
            get_string('error:sessionoverbooked', 'mod_facetoface', (object)[
                'session' => $session->id,
            ])
        );
        $this->assertTrue($manager->process($errors));

        $this->assertTrue($DB->record_exists('facetoface_signups', [
            'sessionid' => $session->id,
            'userid' => $firstuser->id,
        ]));
        $this->assertFalse($DB->record_exists('facetoface_signups', [
            'sessionid' => $session->id,
            'userid' => $seconduser->id,
        ]));
    }

    /**
     * Every overflow row receives an accurate row-level capacity error.
     *
     * @dataProvider manager_provider
     * @param bool $sitewide Whether to use the site-wide manager.
     * @return void
     */
    public function test_each_overflow_row_has_row_level_capacity_error(bool $sitewide): void {
        global $DB;

        [$course, $facetoface, $session, $firstuser, $seconduser] = $this->create_fixture(2, false);
        $thirduser = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $fourthuser = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $manager = $this->create_manager($sitewide, $facetoface, [
            $this->create_row($sitewide, $firstuser, $session, 'booked'),
            $this->create_row($sitewide, $seconduser, $session, 'booked'),
            $this->create_row($sitewide, $thirduser, $session, 'booked'),
            $this->create_row($sitewide, $fourthuser, $session, 'booked'),
        ]);

        $errors = $manager->validate();
        $message = get_string('error:sessionoverbooked', 'mod_facetoface', (object)[
            'session' => $session->id,
        ]);
        $this->assertCount(2, $errors);
        $this->assert_row_has_no_error($errors, 1);
        $this->assert_row_has_no_error($errors, 2);
        $this->assert_row_has_error($errors, 3, $message);
        $this->assert_row_has_error($errors, 4, $message);
        $this->assertTrue($manager->process($errors));

        $this->assertSame(2, $DB->count_records('facetoface_signups', ['sessionid' => $session->id]));
    }

    /**
     * A valid cancellation in the same file releases its seat for a replacement booking.
     *
     * @dataProvider cancellation_order_provider
     * @param bool $sitewide Whether to use the site-wide manager.
     * @param bool $bookingfirst Whether the replacement booking is the first CSV row.
     * @return void
     */
    public function test_cancellation_releases_capacity(
        bool $sitewide,
        bool $bookingfirst
    ): void {
        global $DB;

        [$course, $facetoface, $session, $existinguser, $replacementuser] = $this->create_fixture(1, false);
        $existingsignup = $this->create_signup_with_status(
            $course,
            $facetoface,
            $session,
            $existinguser,
            MDL_F2F_STATUS_BOOKED
        );
        $cancellation = $this->create_row($sitewide, $existinguser, $session, 'cancelled');
        $booking = $this->create_row($sitewide, $replacementuser, $session, 'booked');
        $rows = $bookingfirst ? [$booking, $cancellation] : [$cancellation, $booking];
        $manager = $this->create_manager($sitewide, $facetoface, $rows);

        $errors = $manager->validate();
        $this->assertEmpty($errors);
        $this->assertTrue($manager->process($errors));

        $this->assertSame(
            MDL_F2F_STATUS_USER_CANCELLED,
            (int)$this->get_current_status($existingsignup->id)->statuscode
        );
        $replacementsignup = $DB->get_record(
            'facetoface_signups',
            ['sessionid' => $session->id, 'userid' => $replacementuser->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame(
            MDL_F2F_STATUS_BOOKED,
            (int)$this->get_current_status($replacementsignup->id)->statuscode
        );
    }

    /**
     * Cancelling and rebooking the same existing user in one file still rejects the rebooking row.
     *
     * @dataProvider same_user_rebooking_order_provider
     * @param bool $sitewide Whether to use the site-wide manager.
     * @param bool $bookingfirst Whether the blocked booking row comes first.
     * @return void
     */
    public function test_same_file_cancellation_does_not_allow_rebooking(
        bool $sitewide,
        bool $bookingfirst
    ): void {
        [$course, $facetoface, $session, $user] = $this->create_fixture(1, false);
        $signup = $this->create_signup_with_status(
            $course,
            $facetoface,
            $session,
            $user,
            MDL_F2F_STATUS_BOOKED
        );
        $cancellation = $this->create_row($sitewide, $user, $session, 'cancelled');
        $booking = $this->create_row($sitewide, $user, $session, 'booked');
        $manager = $this->create_manager(
            $sitewide,
            $facetoface,
            $bookingfirst ? [$booking, $cancellation] : [$cancellation, $booking]
        );

        $errors = $manager->validate();
        $bookingrow = $bookingfirst ? 1 : 2;
        $cancellationrow = $bookingfirst ? 2 : 1;
        $this->assertCount(1, $errors);
        $this->assert_row_has_error(
            $errors,
            $bookingrow,
            get_string('error:useralreadyinsession', 'mod_facetoface', (object)[
                'user' => $user->username,
                'session' => $session->id,
            ])
        );
        $this->assert_row_has_no_error($errors, $cancellationrow);
        $this->assertTrue($manager->process($errors));
        $this->assertSame(
            MDL_F2F_STATUS_USER_CANCELLED,
            (int)$this->get_current_status($signup->id)->statuscode
        );
    }

    /**
     * An invalid duplicate does not claim first-row status or reserve capacity.
     *
     * @dataProvider manager_provider
     * @param bool $sitewide Whether to use the site-wide manager.
     * @return void
     */
    public function test_first_valid_duplicate_row_wins(bool $sitewide): void {
        global $DB;

        [$course, $facetoface, $session, $firstuser, $seconduser] = $this->create_fixture(2, true);
        $invalidrow = $this->create_row($sitewide, $firstuser, $session, 'booked');
        $notificationfield = $sitewide ? 'Notification Type' : 'notificationtype';
        $invalidrow->{$notificationfield} = 'invalid';

        $manager = $this->create_manager($sitewide, $facetoface, [
            $invalidrow,
            $this->create_row($sitewide, $firstuser, $session, 'fully_attended'),
            $this->create_row($sitewide, $seconduser, $session, 'booked'),
        ]);
        $errors = $manager->validate();

        $this->assertCount(1, $errors);
        $this->assert_row_has_error(
            $errors,
            1,
            get_string('error:invalidnotificationtypespecified', 'mod_facetoface', 'invalid')
        );
        $this->assert_row_has_no_error($errors, 2);
        $this->assert_row_has_no_error($errors, 3);
        $this->assertTrue($manager->process($errors));

        $firstsignup = $DB->get_record(
            'facetoface_signups',
            ['sessionid' => $session->id, 'userid' => $firstuser->id],
            '*',
            MUST_EXIST
        );
        $secondsignup = $DB->get_record(
            'facetoface_signups',
            ['sessionid' => $session->id, 'userid' => $seconduser->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame(
            MDL_F2F_STATUS_FULLY_ATTENDED,
            (int)$this->get_current_status($firstsignup->id)->statuscode
        );
        $this->assertSame(
            MDL_F2F_STATUS_BOOKED,
            (int)$this->get_current_status($secondsignup->id)->statuscode
        );
        $this->assertEquals(100, facetoface_get_grade($firstuser->id, $course->id, $facetoface->id)->grade);
    }

    /**
     * An errored existing-signup row must not invalidate another session for the same user.
     *
     * @return void
     */
    public function test_errored_row_does_not_contaminate_cross_session_validation(): void {
        global $DB;

        [$course, $facetoface, $firstsession, $user] = $this->create_fixture(2, false);
        $firstsignup = $this->create_signup_with_status(
            $course,
            $facetoface,
            $firstsession,
            $user,
            MDL_F2F_STATUS_USER_CANCELLED
        );
        /** @var \mod_facetoface_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_facetoface');
        $now = time();
        $secondsession = $generator->create_session([
            'facetoface' => $facetoface->id,
            'capacity' => 2,
            'allowoverbook' => 0,
            'sessiondates' => [
                ['timestart' => $now + 3 * DAYSECS, 'timefinish' => $now + 4 * DAYSECS],
            ],
        ]);
        $manager = $this->create_manager(false, $facetoface, [
            $this->create_row(false, $user, $firstsession, 'booked'),
            $this->create_row(false, $user, $secondsession, 'booked'),
        ]);

        $errors = $manager->validate();
        $this->assertCount(1, $errors);
        $this->assert_row_has_error(
            $errors,
            1,
            get_string('error:useralreadyinsession', 'mod_facetoface', (object)[
                'user' => $user->username,
                'session' => $firstsession->id,
            ])
        );
        $this->assert_row_has_no_error($errors, 2);
        $this->assertTrue($manager->process($errors));

        $this->assertSame(
            MDL_F2F_STATUS_USER_CANCELLED,
            (int)$this->get_current_status($firstsignup->id)->statuscode
        );
        $this->assertTrue($DB->record_exists('facetoface_signups', [
            'sessionid' => $secondsession->id,
            'userid' => $user->id,
        ]));
    }

    /**
     * An existing waitlisted row for a historical session has one existing-signup error only.
     *
     * @dataProvider manager_provider
     * @param bool $sitewide Whether to use the site-wide manager.
     * @return void
     */
    public function test_existing_historical_waitlist_has_one_error(bool $sitewide): void {
        [$course, $facetoface, $session, $user] = $this->create_fixture(2, true);
        $this->create_signup_with_status(
            $course,
            $facetoface,
            $session,
            $user,
            MDL_F2F_STATUS_BOOKED
        );
        $manager = $this->create_manager($sitewide, $facetoface, [
            $this->create_row($sitewide, $user, $session, 'waitlisted'),
        ]);

        $errors = $manager->validate();
        $this->assertCount(1, $errors);
        $this->assert_row_has_error(
            $errors,
            1,
            get_string('error:useralreadyinsession', 'mod_facetoface', (object)[
                'user' => $user->username,
                'session' => $session->id,
            ])
        );
    }

    /**
     * Attendance is an authoritative historical update and reactivates inactive signups.
     *
     * @dataProvider inactive_attendance_provider
     * @param bool $sitewide Whether to use the site-wide manager.
     * @param int $existingstatus Existing inactive signup status.
     * @return void
     */
    public function test_attendance_reactivates_inactive_signup(bool $sitewide, int $existingstatus): void {
        global $DB;

        [$course, $facetoface, $session, $user] = $this->create_fixture(1, true);
        $signup = $this->create_signup_with_status(
            $course,
            $facetoface,
            $session,
            $user,
            $existingstatus
        );
        $historycountbefore = $DB->count_records('facetoface_signups_status', ['signupid' => $signup->id]);
        $manager = $this->create_manager($sitewide, $facetoface, [
            $this->create_row($sitewide, $user, $session, 'fully_attended'),
        ]);

        $errors = $manager->validate();
        $this->assertEmpty($errors);
        $this->assertTrue($manager->process($errors));
        $this->assertSame(
            MDL_F2F_STATUS_FULLY_ATTENDED,
            (int)$this->get_current_status($signup->id)->statuscode
        );
        $this->assertGreaterThan(
            $historycountbefore,
            $DB->count_records('facetoface_signups_status', ['signupid' => $signup->id])
        );
        $this->assertEquals(100, facetoface_get_grade($user->id, $course->id, $facetoface->id)->grade);
    }

    /**
     * A runtime attendance failure must not be reported as a successful upload.
     *
     * This simulates the session being moved into the future after validation but before processing.
     *
     * @dataProvider manager_provider
     * @param bool $sitewide Whether to use the site-wide manager.
     * @return void
     */
    public function test_attendance_processing_failure_throws_exception(bool $sitewide): void {
        global $DB;

        [$course, $facetoface, $session, $user] = $this->create_fixture(1, true);
        $signup = $this->create_signup_with_status(
            $course,
            $facetoface,
            $session,
            $user,
            MDL_F2F_STATUS_BOOKED
        );
        $manager = $this->create_manager($sitewide, $facetoface, [
            $this->create_row($sitewide, $user, $session, 'fully_attended'),
        ]);
        $errors = $manager->validate();
        $this->assertEmpty($errors);

        $sessiondate = $DB->get_record(
            'facetoface_sessions_dates',
            ['sessionid' => $session->id],
            '*',
            MUST_EXIST
        );
        $sessiondate->timestart = time() + DAYSECS;
        $sessiondate->timefinish = time() + 2 * DAYSECS;
        $DB->update_record('facetoface_sessions_dates', $sessiondate);

        $expectedmessage = get_string('error:attendanceuploadfailed', 'mod_facetoface', (object)[
            'user' => $user->username,
            'session' => $session->id,
            'line' => 2,
        ]);
        try {
            $manager->process($errors);
            $this->fail('Expected attendance processing to throw an exception.');
        } catch (\moodle_exception $exception) {
            $this->assertStringContainsString($expectedmessage, $exception->getMessage());
        }

        $this->assertSame(MDL_F2F_STATUS_BOOKED, (int)$this->get_current_status($signup->id)->statuscode);
    }

    /**
     * Statuses without a processing path are rejected rather than reported as successful no-ops.
     *
     * @dataProvider unhandled_status_provider
     * @param bool $sitewide Whether to use the site-wide manager.
     * @param string $status Unsupported workflow status.
     * @return void
     */
    public function test_unhandled_status_is_rejected(bool $sitewide, string $status): void {
        global $DB;

        [, $facetoface, $session, $user] = $this->create_fixture(1, false);
        $manager = $this->create_manager($sitewide, $facetoface, [
            $this->create_row($sitewide, $user, $session, $status),
        ]);

        $errors = $manager->validate();
        $this->assertCount(1, $errors);
        $this->assert_row_has_error(
            $errors,
            1,
            get_string('error:invalidstatusspecified', 'mod_facetoface', $status)
        );
        $this->assertTrue($manager->process($errors));
        $this->assertFalse($DB->record_exists('facetoface_signups', [
            'sessionid' => $session->id,
            'userid' => $user->id,
        ]));
    }

    /**
     * Provide all current signup statuses for both booking managers.
     *
     * @return array<string, array{0:bool, 1:int}> Test cases keyed by manager and status name.
     */
    public static function existing_status_provider(): array {
        $statuses = [
            'user cancelled' => MDL_F2F_STATUS_USER_CANCELLED,
            'declined' => MDL_F2F_STATUS_DECLINED,
            'requested' => MDL_F2F_STATUS_REQUESTED,
            'approved' => MDL_F2F_STATUS_APPROVED,
            'waitlisted' => MDL_F2F_STATUS_WAITLISTED,
            'booked' => MDL_F2F_STATUS_BOOKED,
            'no show' => MDL_F2F_STATUS_NO_SHOW,
            'partially attended' => MDL_F2F_STATUS_PARTIALLY_ATTENDED,
            'fully attended' => MDL_F2F_STATUS_FULLY_ATTENDED,
        ];
        $cases = [];

        foreach (['course' => false, 'site-wide' => true] as $manager => $sitewide) {
            foreach ($statuses as $statusname => $statuscode) {
                $cases[$manager . ' - ' . $statusname] = [$sitewide, $statuscode];
            }
        }

        return $cases;
    }

    /**
     * Provide booking-style CSV statuses for both managers.
     *
     * @return array<string, array{0:bool, 1:string}> Test cases keyed by manager and booking status.
     */
    public static function booking_status_provider(): array {
        $cases = [];
        foreach (['course' => false, 'site-wide' => true] as $manager => $sitewide) {
            foreach (['default' => '', 'booked' => 'booked', 'waitlisted' => 'waitlisted'] as $name => $status) {
                $cases[$manager . ' - ' . $name] = [$sitewide, $status];
            }
        }

        return $cases;
    }

    /**
     * Provide duplicate-row orders for both managers.
     *
     * @return array<string, array{0:bool, 1:string, 2:int}> Test cases keyed by manager and first-row status.
     */
    public static function duplicate_order_provider(): array {
        return [
            'course - booking first' => [false, 'booked', MDL_F2F_STATUS_BOOKED],
            'course - attendance first' => [false, 'fully_attended', MDL_F2F_STATUS_FULLY_ATTENDED],
            'site-wide - booking first' => [true, 'booked', MDL_F2F_STATUS_BOOKED],
            'site-wide - attendance first' => [true, 'fully_attended', MDL_F2F_STATUS_FULLY_ATTENDED],
        ];
    }

    /**
     * Provide both CSV orders for a cancellation and its replacement booking.
     *
     * @return array<string, array{0:bool, 1:bool}> Test cases keyed by manager and row order.
     */
    public static function cancellation_order_provider(): array {
        return [
            'course - cancellation first' => [false, false],
            'course - booking first' => [false, true],
            'site-wide - cancellation first' => [true, false],
            'site-wide - booking first' => [true, true],
        ];
    }

    /**
     * Provide both row orders for same-user cancellation/rebooking in both managers.
     *
     * @return array<string, array{0:bool, 1:bool}> Test cases keyed by manager and row order.
     */
    public static function same_user_rebooking_order_provider(): array {
        return [
            'course - cancellation first' => [false, false],
            'course - booking first' => [false, true],
            'site-wide - cancellation first' => [true, false],
            'site-wide - booking first' => [true, true],
        ];
    }

    /**
     * Provide inactive signup statuses for both booking managers.
     *
     * @return array<string, array{0:bool, 1:int}> Test cases keyed by manager and inactive status.
     */
    public static function inactive_attendance_provider(): array {
        $statuses = [
            'user cancelled' => MDL_F2F_STATUS_USER_CANCELLED,
            'declined' => MDL_F2F_STATUS_DECLINED,
            'requested' => MDL_F2F_STATUS_REQUESTED,
        ];
        $cases = [];
        foreach (['course' => false, 'site-wide' => true] as $manager => $sitewide) {
            foreach ($statuses as $statusname => $statuscode) {
                $cases[$manager . ' - ' . $statusname] = [$sitewide, $statuscode];
            }
        }

        return $cases;
    }

    /**
     * Provide unsupported workflow statuses for both booking managers.
     *
     * @return array<string, array{0:bool, 1:string}> Test cases keyed by manager and unsupported status.
     */
    public static function unhandled_status_provider(): array {
        $cases = [];
        foreach (['course' => false, 'site-wide' => true] as $manager => $sitewide) {
            foreach (['user_cancelled', 'requested', 'approved', 'declined'] as $status) {
                $cases[$manager . ' - ' . $status] = [$sitewide, $status];
            }
        }

        return $cases;
    }

    /**
     * Provide both booking managers.
     *
     * @return array<string, array{0:bool}> Booking-manager test cases keyed by scenario name.
     */
    public static function manager_provider(): array {
        return [
            'course-level upload' => [false],
            'site-wide upload' => [true],
        ];
    }

    /**
     * Create a Face-to-Face fixture.
     *
     * @param int $capacity Session capacity.
     * @param bool $historical Whether the session has finished.
     * @return array{0:\stdClass, 1:\stdClass, 2:\stdClass, 3:\stdClass, 4:\stdClass}
     *     Course, activity, session and two users.
     */
    private function create_fixture(int $capacity, bool $historical): array {
        /** @var \mod_facetoface_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_facetoface');
        $course = $this->getDataGenerator()->create_course();
        $facetoface = $generator->create_instance(['course' => $course->id]);
        $firstuser = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $seconduser = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $now = time();
        $timestart = $historical ? $now - 2 * DAYSECS : $now + DAYSECS;
        $timefinish = $historical ? $now - DAYSECS : $now + 2 * DAYSECS;
        $session = $generator->create_session([
            'facetoface' => $facetoface->id,
            'capacity' => $capacity,
            'allowoverbook' => 0,
            'sessiondates' => [
                ['timestart' => $timestart, 'timefinish' => $timefinish],
            ],
        ]);

        $this->setAdminUser();

        return [$course, $facetoface, $session, $firstuser, $seconduser];
    }

    /**
     * Create a signup with the requested current status.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $facetoface Activity record.
     * @param \stdClass $session Session record.
     * @param \stdClass $user User record.
     * @param int $statuscode Current signup status.
     * @return \stdClass Signup record.
     */
    private function create_signup_with_status(
        \stdClass $course,
        \stdClass $facetoface,
        \stdClass $session,
        \stdClass $user,
        int $statuscode
    ): \stdClass {
        global $DB, $USER;

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
        $signup = $DB->get_record(
            'facetoface_signups',
            ['sessionid' => $session->id, 'userid' => $user->id],
            '*',
            MUST_EXIST
        );

        if (in_array($statuscode, [
            MDL_F2F_STATUS_NO_SHOW,
            MDL_F2F_STATUS_PARTIALLY_ATTENDED,
            MDL_F2F_STATUS_FULLY_ATTENDED,
        ], true)) {
            facetoface_take_attendance((object)[
                's' => $session->id,
                'submissionid_' . $signup->id => $statuscode,
            ]);
            return $signup;
        }

        if ($statuscode !== MDL_F2F_STATUS_BOOKED) {
            facetoface_update_signup_status($signup->id, $statuscode, $USER->id);
        }

        return $signup;
    }

    /**
     * Create and load either CSV manager.
     *
     * @param bool $sitewide Whether to create the site-wide manager.
     * @param \stdClass $facetoface Activity record.
     * @param list<\stdClass> $rows CSV row objects.
     * @return booking_manager|booking_manager_bulk_attendance Loaded manager instance.
     */
    private function create_manager(bool $sitewide, \stdClass $facetoface, array $rows): booking_manager|booking_manager_bulk_attendance {
        if ($sitewide) {
            return (new booking_manager_bulk_attendance())->load_from_array($rows);
        }

        return (new booking_manager($facetoface->id))->load_from_array($rows);
    }

    /**
     * Create a row in the selected manager's format.
     *
     * @param bool $sitewide Whether to use site-wide field names.
     * @param \stdClass $user User record.
     * @param \stdClass $session Session record.
     * @param string $status CSV status.
     * @return \stdClass CSV row.
     */
    private function create_row(bool $sitewide, \stdClass $user, \stdClass $session, string $status): \stdClass {
        if ($sitewide) {
            return (object)[
                'Username' => $user->username,
                'Session' => $session->id,
                'Status' => $status,
                'Discount Code' => '',
                'Notification Type' => 'email',
            ];
        }

        return (object)[
            'username' => $user->username,
            'session' => $session->id,
            'status' => $status,
            'discountcode' => '',
            'notificationtype' => 'email',
        ];
    }

    /**
     * Return the current signup status record.
     *
     * @param int $signupid Signup ID.
     * @return \stdClass Current status record.
     */
    private function get_current_status(int $signupid): \stdClass {
        global $DB;

        return $DB->get_record(
            'facetoface_signups_status',
            ['signupid' => $signupid, 'superceded' => 0],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Assert that a row has the expected validation error.
     *
     * @param list<array{0:int|string, 1:string|\lang_string}> $errors Validation errors.
     * @param int $row CSV row number.
     * @param string $message Expected message.
     * @return void
     */
    private function assert_row_has_error(array $errors, int $row, string $message): void {
        foreach ($errors as $error) {
            if ($this->error_contains_row($error, $row) && (string)$error[1] === $message) {
                return;
            }
        }

        $this->fail('Expected validation error was not found for row ' . $row . ': ' . $message);
    }

    /**
     * Assert that a row has no validation errors.
     *
     * @param list<array{0:int|string, 1:string|\lang_string}> $errors Validation errors.
     * @param int $row CSV row number.
     * @return void
     */
    private function assert_row_has_no_error(array $errors, int $row): void {
        foreach ($errors as $error) {
            $this->assertFalse(
                $this->error_contains_row($error, $row),
                'Unexpected validation error for row ' . $row . ': ' . (string)$error[1]
            );
        }
    }

    /**
     * Return whether an error applies to a row.
     *
     * @param array{0:int|string, 1:string|\lang_string} $error Validation error.
     * @param int $row CSV row number.
     * @return bool True when the validation error applies to the supplied CSV row.
     */
    private function error_contains_row(array $error, int $row): bool {
        $rows = array_map('trim', explode(',', (string)$error[0]));

        return in_array((string)$row, $rows, true);
    }
}
// GCHLOL ends.
