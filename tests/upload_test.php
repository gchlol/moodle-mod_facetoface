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

use mod_facetoface\booking_manager;
use lang_string;

/**
 * Test the upload helper class.
 *
 * @package    mod_facetoface
 * @author     Kevin Pham <kevinpham@catalyst-au.net>
 * @copyright  Catalyst IT, 2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_facetoface\booking_manager
 */
class upload_test extends \advanced_testcase {

    /**
     * This method runs before every test.
     */
    public function setUp(): void {
        $this->resetAfterTest();
    }

    /**
     * Test permissions to ensure a user can only for sessions they have editing rights to.
     * - those who see the edit button and actions on the view page.
     */
    public function test_session_validation() {
        /** @var \mod_facetoface_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_facetoface');

        $course = $this->getDataGenerator()->create_course();
        $facetoface = $generator->create_instance(['course' => $course->id]);
        // Generate users.
        $student1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $student2 = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setCurrentTimeStart();
        $now = time();
        // Overbooking a session should not be allowed, if allowoverbook is set to 0.
        $nooverbooksession = $generator->create_session([
            'facetoface' => $facetoface->id,
            'capacity' => '1',
            'allowoverbook' => '0',
            'details' => 'xyz',
            'duration' => '1.5', // One and half hours.
            'normalcost' => '111',
            'discountcost' => '11',
            'allowcancellations' => '0',
            'sessiondates' => [
                ['timestart' => $now + 3 * DAYSECS, 'timefinish' => $now + 2 * DAYSECS],
            ],
        ]);
        $overbookablesession = $generator->create_session([
            'facetoface' => $facetoface->id,
            'capacity' => '1',
            'allowoverbook' => '1',
            'details' => 'xyz',
            'duration' => '1.5', // One and half hours.
            'normalcost' => '111',
            'discountcost' => '11',
            'allowcancellations' => '0',
            'sessiondates' => [
                ['timestart' => $now + 3 * DAYSECS, 'timefinish' => $now + 2 * DAYSECS],
            ],
        ]);

        $bm = new booking_manager($facetoface->id);

        // Expect an error for overbooking.
        $records = [
            // Test user does not exist.
            (object) [
                'email' => $student1->email,
                'session' => $nooverbooksession->id,
            ],
            // Test user exist, but is not enrolled into the course.
            (object) [
                'email' => $student2->email,
                'session' => $nooverbooksession->id,
            ],
        ];
        $bm->load_from_array($records);
        $errors = $bm->validate();
        $expectederr = new lang_string(
            'error:sessionoverbooked',
            'mod_facetoface',
            (object) ['session' => $nooverbooksession->id, 'amount' => 1]
        );
        $this->assertCount(1, $errors);
        $this->assertEquals($expectederr, $errors[0][1]);

        // Expect no errors for a session which allows overbookings.
        $records = [
            // Test user does not exist.
            (object) [
                'email' => $student1->email,
                'session' => $overbookablesession->id,
            ],
            // Test user exist, but is not enrolled into the course.
            (object) [
                'email' => $student2->email,
                'session' => $overbookablesession->id,
            ],
        ];
        $bm->load_from_array($records);
        $errors = $bm->validate();
        $this->assertCount(0, $errors);
    }

    /**
     * Test user validation to ensure that details and fields are valid and can be booked into a session.
     */
    public function test_user_validation() {
        /** @var \mod_facetoface_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_facetoface');

        $course = $this->getDataGenerator()->create_course();
        $anothercourse = $this->getDataGenerator()->create_course();
        $facetoface = $generator->create_instance(['course' => $course->id]);
        $anotherfacetoface = $generator->create_instance(['course' => $anothercourse->id]);

        // Generate users.
        $user = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $student2 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $student3 = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setCurrentTimeStart();
        $now = time();
        $session = $generator->create_session([
            'facetoface' => $facetoface->id,
            'capacity' => '3',
            'allowoverbook' => '0',
            'details' => 'xyz',
            'duration' => '1.5', // One and half hours.
            'normalcost' => '111',
            'discountcost' => '11',
            'allowcancellations' => '0',
            'sessiondates' => [
                ['timestart' => $now + 3 * DAYSECS, 'timefinish' => $now + 2 * DAYSECS],
            ],
        ]);

        $secondsession = $generator->create_session([
            'facetoface' => $facetoface->id,
            'capacity' => '3',
            'allowoverbook' => '0',
            'details' => 'xyz',
            'duration' => '1.5', // One and half hours.
            'normalcost' => '111',
            'discountcost' => '11',
            'allowcancellations' => '0',
            'sessiondates' => [
                ['timestart' => $now + 4 * DAYSECS, 'timefinish' => $now + 5 * DAYSECS],
            ],
        ]);

        $remotesession = $generator->create_session([
            'facetoface' => $anotherfacetoface->id,
            'capacity' => '3',
            'allowoverbook' => '0',
            'details' => 'xyz',
            'duration' => '1.5', // One and half hours.
            'normalcost' => '111',
            'discountcost' => '11',
            'allowcancellations' => '0',
            'sessiondates' => [
                ['timestart' => $now + 3 * DAYSECS, 'timefinish' => $now + 2 * DAYSECS],
            ],
        ]);

        facetoface_user_signup($session, $facetoface, $course, '',
            MDL_F2F_TEXT, MDL_F2F_STATUS_BOOKED, $student3->id);

        $bm = new booking_manager($facetoface->id);

        $records = [
            // Test user does not exist.
            (object) [
                'email' => 'whoami@example.com',
                'session' => $session->id,
                'status' => '',
                'notificationtype' => '',
                'discountcode' => '',
            ],
            // Test user exist, but is not enrolled into the course.
            (object) [
                'email' => $user->email,
                'session' => $session->id,
                'status' => '',
                'notificationtype' => '',
                'discountcode' => '',
            ],
            // Test student who is enrolled into the course (no issues).
            (object) [
                'email' => $student1->email,
                'session' => $session->id,
                'status' => '',
                'notificationtype' => '',
                'discountcode' => '',
            ],
            // Test invalid options.
            (object) [
                'email' => $student2->email,
                'session' => $session->id,
                'status' => 'helloworld',
                'notificationtype' => 'phone',
                'discountcode' => '',
            ],
            // Test multiple rows for the same student when f2f signup type is single.
            (object) [
                'email' => $student2->email,
                'session' => $secondsession->id,
                'status' => '',
                'notificationtype' => '',
                'discountcode' => '',
            ],
            // Test permissions (e.g. user not able to upload/process for a f2f activity loaded).
            (object) [
                'email' => $student2->email,
                'session' => $remotesession->id,
                'status' => '',
                'notificationtype' => '',
                'discountcode' => '',
            ],
            // Test email does not match case-wise (in the default case sensitive mode).
            (object) [
                'email' => strtoupper($student2->email),
                'session' => $session->id,
                'status' => '',
                'notificationtype' => '',
                'discountcode' => '',
            ],
            // Test student already in a session when f2f signup type is single.
            (object) [
                'email' => $student3->email,
                'session' => $secondsession->id,
                'status' => '',
                'notificationtype' => '',
                'discountcode' => '',
            ],
        ];

        $bm->load_from_array($records);

        $errors = $bm->validate();
        $this->assertTrue(
            $this->check_row_validation_error_exists(
                $errors,
                1,
                new lang_string('error:userdoesnotexist', 'mod_facetoface', $records[0]->email)
            ),
            'Expecting user to not exist.'
        );

        $this->assertTrue(
            $this->check_row_validation_error_exists(
                $errors,
                2,
                new lang_string('error:userisnotenrolledintocourse', 'mod_facetoface', $user->email)
            ),
            'Expected error for user not enrolled in a course.'
        );

        $this->assertFalse(
            $this->check_row_validation_error_exists(
                $errors,
                3,
                ''
            ),
            'Expecting no specific errors for this user.'
        );

        $this->assertTrue(
            $this->check_row_validation_error_exists(
                $errors,
                4,
                new lang_string('error:invalidnotificationtypespecified', 'mod_facetoface', $records[3]->notificationtype)
            ),
            'Expecting notification type error, as an invalid type was provided.'
        );

        $this->assertTrue(
            $this->check_row_validation_error_exists(
                $errors,
                4,
                new lang_string('error:invalidstatusspecified', 'mod_facetoface', $records[3]->status)
            ),
            'Expecting status error, since the status should be either booked or cancelled.'
        );

        $this->assertTrue(
            $this->check_row_validation_error_exists(
                $errors,
                '4, 5, 6, 7',
                new lang_string('error:multipleusersessions', 'mod_facetoface', $student2->email)
            ),
            'Expecting multiple sessions error for user when f2f signup type is single.'
        );

        $this->assertTrue(
            $this->check_row_validation_error_exists(
                $errors,
                6,
                new lang_string('error:tryingtoupdatesessionfromanothermodule', 'mod_facetoface', (object) [
                    'session' => $remotesession->id,
                    'f' => $facetoface->id,
                ])
            ),
            'Expecting permission check conflict due to session->facetoface + facetoface id mismatcherror.'
        );

        $this->assertTrue(
            $this->check_row_validation_error_exists(
                $errors,
                7,
                new lang_string('error:userdoesnotexist', 'mod_facetoface', strtoupper($student2->email))
            ),
            'Expecting user to not exist because email does not match case-wise.'
        );

        $this->assertTrue(
            $this->check_row_validation_error_exists(
                $errors,
                8,
                new lang_string('error:addalreadysignedupattendee', 'mod_facetoface', $student3->email)
            ),
            'Expecting user already signed up to another session error.'
        );
    }

    /**
     * Helper function to check if a specific error exists in the array of errors.
     *
     * @param array $errors Array of errors.
     * @param string $expectedrownumber Expected row number string.
     * @param string $expectederrormsg Expected error message.
     * @return bool True if the error exists, false otherwise.
     */
    private function check_row_validation_error_exists(array $errors, string $expectedrownumber, string $expectederrormsg): bool {
        foreach ($errors as $error) {
            // Note: row number is based on a CSV file human readable format, where there is a header and row data.
            [$rownumber, $errormsg] = $error;
            // Check if the error exists in the array.
            if ($rownumber == $expectedrownumber && $errormsg == $expectederrormsg) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tests uploading booking fails if it matches multiple users when ignoring case.
     */
    public function test_processing_booking_case_insensitive_match_multiple() {
        /** @var \mod_facetoface_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_facetoface');

        $course = $this->getDataGenerator()->create_course();
        $facetoface = $generator->create_instance(['course' => $course->id]);

        // Create two users, with emails that would be the same when made lowercase.
        $this->getDataGenerator()->create_and_enrol($course, 'student', ['email' => 'test@test.com']);
        $this->getDataGenerator()->create_and_enrol($course, 'student', ['email' => 'TEST@test.com']);

        $this->setCurrentTimeStart();
        $now = time();
        $session = $generator->create_session([
            'facetoface' => $facetoface->id,
            'capacity' => '3',
            'allowoverbook' => '0',
            'details' => 'xyz',
            'duration' => '3.0',
            'normalcost' => '111',
            'discountcost' => '11',
            'allowcancellations' => '0',
            'sessiondates' => [
                ['timestart' => $now + 3 * DAYSECS, 'timefinish' => $now + 2 * DAYSECS],
            ],
        ]);

        $bm = new booking_manager($facetoface->id);
        $record = (object) [
            'email' => 'TEST@test.com',
            'session' => $session->id,
            'status' => 'booked',
            'notificationtype' => 'ical',
            'discountcode' => 'mycode',
        ];
        $records = [$record];
        $bm->set_case_insensitive(true);

        $bm->load_from_array($records);

        $errors = $bm->validate();
        $this->assertNotEmpty($errors);

        $this->assertTrue(
            $this->check_row_validation_error_exists(
                $errors,
                1,
                new lang_string('error:multipleusersmatched', 'mod_facetoface', 'TEST@test.com')
            ),
            'Expecting to error due to matching multiple users when ignoring case.',
        );
    }

    /**
     * Tests uploading a booking where the emails should match regardless of case.
     */
    public function test_processing_booking_case_insensitive() {
        /** @var \mod_facetoface_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_facetoface');

        $course = $this->getDataGenerator()->create_course();
        $facetoface = $generator->create_instance(['course' => $course->id]);
        $this->getDataGenerator()->create_and_enrol($course, 'student', ['email' => 'test@test.com']);

        $this->setCurrentTimeStart();
        $now = time();
        $session = $generator->create_session([
            'facetoface' => $facetoface->id,
            'capacity' => '3',
            'allowoverbook' => '0',
            'details' => 'xyz',
            'duration' => '1.5', // One and half hours.
            'normalcost' => '111',
            'discountcost' => '11',
            'allowcancellations' => '0',
            'sessiondates' => [
                ['timestart' => $now + 3 * DAYSECS, 'timefinish' => $now + 2 * DAYSECS],
            ],
        ]);

        $bm = new booking_manager($facetoface->id);
        $record = (object) [
            'email' => 'TEST@test.com',
            'session' => $session->id,
            'status' => 'booked',
            'notificationtype' => 'ical',
            'discountcode' => 'MYSPECIALCODE',
        ];
        $records = [$record];
        $bm->set_case_insensitive(true);

        $bm->load_from_array($records);

        $errors = $bm->validate();
        $this->assertEmpty($errors);
        $this->assertTrue($bm->process());

        // Check users are as expected.
        $users = facetoface_get_attendees($session->id);
        $this->assertCount(1, $users);
        $this->assertEquals(strtolower($record->email), strtolower(current($users)->email));
        $this->assertEquals($record->discountcode, current($users)->discountcode);
        $this->assertEquals(MDL_F2F_ICAL, current($users)->notificationtype);
        $this->assertEquals(MDL_F2F_STATUS_BOOKED, current($users)->statuscode);

        // Re-booking the same user shouldn't cause any isssues. Run the validate again and check.
        $errors = $bm->validate();
        $this->assertEmpty($errors);
    }

    /**
     * Test upload processing to ensure the happy path is working as expected, and users can be booked into a session.
     */
    public function test_processing_booking() {
        /** @var \mod_facetoface_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_facetoface');

        $course = $this->getDataGenerator()->create_course();
        $facetoface = $generator->create_instance(['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setCurrentTimeStart();
        $now = time();
        $session = $generator->create_session([
            'facetoface' => $facetoface->id,
            'capacity' => '3',
            'allowoverbook' => '0',
            'details' => 'xyz',
            'duration' => '1.5', // One and half hours.
            'normalcost' => '111',
            'discountcost' => '11',
            'allowcancellations' => '0',
            'sessiondates' => [
                ['timestart' => $now + 3 * DAYSECS, 'timefinish' => $now + 2 * DAYSECS],
            ],
        ]);

        $bm = new booking_manager($facetoface->id);
        $record = (object) [
            'email' => $student->email,
            'session' => $session->id,
            'status' => 'booked',
            'notificationtype' => 'ical',
            'discountcode' => 'MYSPECIALCODE',
        ];
        $records = [$record];

        $bm->load_from_array($records);

        $errors = $bm->validate();
        $this->assertEmpty($errors);
        $this->assertTrue($bm->process());

        // Check users are as expected.
        $users = facetoface_get_attendees($session->id);
        $this->assertCount(1, $users);
        $this->assertEquals($record->email, current($users)->email);
        $this->assertEquals($record->discountcode, current($users)->discountcode);
        $this->assertEquals(MDL_F2F_ICAL, current($users)->notificationtype);
        $this->assertEquals(MDL_F2F_STATUS_BOOKED, current($users)->statuscode);

        // Re-booking the same user shouldn't cause any isssues. Run the validate again and check.
        $errors = $bm->validate();
        $this->assertEmpty($errors);
    }

    /**
     * Test upload processing to ensure the happy path is working as expected, and users can be cancelled from a session.
     *
     * To do this, we will book the user, then cancel them. There should be no
     * errors, and we should confirm they are booked and are removed
     * afterwards.
     */
    public function test_processing_cancellation() {
        /** @var \mod_facetoface_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_facetoface');

        $course = $this->getDataGenerator()->create_course();
        $facetoface = $generator->create_instance(['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setCurrentTimeStart();
        $now = time();
        $session = $generator->create_session([
            'facetoface' => $facetoface->id,
            'capacity' => '5',
            'allowoverbook' => '0',
            'details' => 'xyz',
            'duration' => '1.5', // One and half hours.
            'normalcost' => '111',
            'discountcost' => '11',
            'allowcancellations' => '0',
            'sessiondates' => [
                ['timestart' => $now + 3 * DAYSECS, 'timefinish' => $now + 2 * DAYSECS],
            ],
        ]);

        $bm = new booking_manager($facetoface->id);
        $record = (object) [
            'email' => $student->email,
            'session' => $session->id,
            'status' => 'booked',
        ];
        $records = [$record];

        $bm->load_from_array($records);

        $errors = $bm->validate();
        $this->assertEmpty($errors);
        $this->assertTrue($bm->process());

        // Check users are as expected.
        $users = facetoface_get_attendees($session->id);
        $this->assertCount(1, $users);
        $this->assertEquals($record->email, current($users)->email);
        $this->assertEquals(MDL_F2F_STATUS_BOOKED, current($users)->statuscode);

        // Now, let's cancel their booking via the booking manager.
        $record = (object) [
            'email' => $student->email,
            'session' => $session->id,
            'status' => 'cancelled',
        ];
        $records = [$record];
        $bm->load_from_array($records);

        $errors = $bm->validate();
        $this->assertEmpty($errors);
        $this->assertTrue($bm->process());

        // Check the users are removed (since their booking was cancelled).
        $users = facetoface_get_attendees($session->id);
        $this->assertEmpty($users);

        // Check and ensure the users were properly cancelled.
        $users = facetoface_get_cancellations($session->id);
        $this->assertCount(1, $users);
        $this->assertEquals($student->id, current($users)->id);
        $this->assertNotEmpty(current($users)->timecancelled);
    }

    /**
     * Updates via uploads can be done for previous sessions, only if they are to update attendance.
     *
     * Book someone in, then once the session is over, update their attendance. This should work.
     */
    public function test_updates_for_previous_sessions() {
        global $DB;
        /** @var \mod_facetoface_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_facetoface');

        $course = $this->getDataGenerator()->create_course();
        $facetoface = $generator->create_instance(['course' => $course->id]);

        // Generate users.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setCurrentTimeStart();
        $now = time();
        $session = $generator->create_session([
            'facetoface' => $facetoface->id,
            'capacity' => '3',
            'allowoverbook' => '0',
            'details' => 'xyz',
            'duration' => '2', // One and half hours.
            'normalcost' => '111',
            'discountcost' => '11',
            'allowcancellations' => '0',
            'sessiondates' => [
                ['timestart' => $now + 5, 'timefinish' => $now + 10],
            ],
        ]);
        $bm = new booking_manager($facetoface->id);

        // Book the student.
        $records = [
            (object) [
                'email' => $student->email,
                'session' => $session->id,
                'status' => 'booked',
            ],
        ];

        $bm->load_from_array($records);
        $errors = $bm->validate();
        $this->assertFalse(
            $this->check_row_validation_error_exists(
                $errors,
                1,
                ''
            ),
            'Expecting user to be booked without issues.'
        );
        $bm->process();

        $DB->update_record(
            'facetoface_sessions_dates',
            (object) [
                'timestart' => 0,
                'timefinish' => 1,
                'id' => $session->sessiondates[0]->id,
            ],
        );

        // It should detect an error (e.g. cannot book a session in progress).
        $errors = $bm->validate(time() + 1);
        $this->assertTrue(
            $this->check_row_validation_error_exists(
                $errors,
                1,
                get_string('cannotsignupsessionover', 'facetoface')
            ),
            'Expecting user to not be bookable since the session has started.'
        );

        // Update the student's attendance after the session finishes.
        $attendanceupdates = [
            (object) [
                'email' => $student->email,
                'session' => $session->id,
                'status' => 'no_show',
                'grade_expected' => 0,
            ],
            (object) [
                'email' => $student->email,
                'session' => $session->id,
                'status' => 'partially_attended',
                'grade_expected' => 50,
            ],
            (object) [
                'email' => $student->email,
                'session' => $session->id,
                'status' => 'fully_attended',
                'grade_expected' => 100,
            ],
        ];

        $timenow = time() + 4 * DAYSECS; // Two days after the session started.
        foreach ($attendanceupdates as $update) {
            $bm->load_from_array([$update]);

            $errors = $bm->validate($timenow);
            $this->assertFalse(
                $this->check_row_validation_error_exists(
                    $errors,
                    1,
                    ''
                ),
                'Expecting update to be valid (even though session has started or finished).'
            );
            $bm->process();

            // Check to ensure the grade is as expected from the update.
            $grade = facetoface_get_grade($student->id, $course->id, $facetoface->id);
            $this->assertEquals($update->grade_expected, $grade->grade);
        }
    }

    /**
     * Provides values to test_email_suppression
     * @return array
     */
    public static function email_suppression_provider(): array {
        return [
            'no suppression, booked' => [
                'status' => 'booked',
                'shouldsuppress' => false,
            ],
            'suppressed emails, booked' => [
                'status' => 'booked',
                'shouldsuppress' => true,
            ],
            'no suppression, cancel' => [
                'status' => 'cancelled',
                'shouldsuppress' => false,
            ],
            'suppressed emails, cancel' => [
                'status' => 'cancelled',
                'shouldsuppress' => true,
            ],
        ];
    }

    /**
     * Tests that the email suppression property is considered when signing up users.
     * @param string $status status of session to use in csv
     * @param bool $shouldsuppress
     * @dataProvider email_suppression_provider
     */
    public function test_email_suppression(string $status, bool $shouldsuppress) {
        /** @var \mod_facetoface_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_facetoface');

        // Create f2f in course.
        // Note the f2f must have a confirmation message & subject set, otherwise no emails will be sent ever.
        $course = $this->getDataGenerator()->create_course();
        $facetoface = $generator->create_instance(['course' => $course->id, 'confirmationmessage' => 'test',
            'confirmationsubject' => 'test', 'cancellationmessage' => 'test', 'cancellationsubject' => 'test']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setCurrentTimeStart();
        $now = time();
        $session = $generator->create_session([
            'facetoface' => $facetoface->id,
            'capacity' => '10',
            'allowoverbook' => '0',
            'details' => 'xyzabc',
            'duration' => '1.5', // One and half hours.
            'normalcost' => '123',
            'discountcost' => '12',
            'allowcancellations' => '0',
            'sessiondates' => [
                ['timestart' => $now + 5 * DAYSECS, 'timefinish' => $now + 2 * DAYSECS],
            ],
        ]);

        $bm = new booking_manager($facetoface->id);
        $record = (object) [
            'email' => $student->email,
            'session' => $session->id,
            'status' => $status,
        ];

        $records = [$record];
        $bm->load_from_array($records);

        // Set the email suppression value.
        if ($shouldsuppress) {
            $bm->suppress_email();
        }

        // Setup email sink and process.
        $sink = $this->redirectEmails();
        $bm->process();

        // Check emails got sent per the config.
        $emails = $sink->get_messages();
        if ($shouldsuppress) {
            $this->assertEmpty($emails);
        } else {
            $this->assertNotEmpty($emails);
        }
    }

    /**
     * Test upload processing multiple sessions with signup type set to multiple.
     */
    public function test_processing_signup_multiple() {
        /** @var \mod_facetoface_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_facetoface');

        $course = $this->getDataGenerator()->create_course();
        // Facetoface with signuptype set to multiple.
        $facetoface = $generator->create_instance(['course' => $course->id, 'signuptype' => MOD_FACETOFACE_SIGNUP_MULTIPLE]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $now = time();
        $record = [
            'facetoface' => $facetoface->id,
            'capacity' => '3',
            'allowoverbook' => '0',
            'details' => 'xyz',
            'duration' => '1.5', // One and half hours.
            'normalcost' => '111',
            'discountcost' => '11',
            'allowcancellations' => '0',
            'sessiondates' => [
                ['timestart' => $now + 3 * DAYSECS, 'timefinish' => $now + 4 * DAYSECS],
            ],
        ];
        $session1 = $generator->create_session($record);
        $session2 = $generator->create_session($record);
        $session3 = $generator->create_session($record);

        // Signup student to session3 to test already signed up validating.
        facetoface_user_signup($session3, $facetoface, $course, '',
            MDL_F2F_TEXT, MDL_F2F_STATUS_BOOKED, $student->id);

        $bm = new booking_manager($facetoface->id);

        $records = [
            // Test user can be added to session 1.
            (object) [
                'email' => $student->email,
                'session' => $session1->id,
                'status' => '',
                'notificationtype' => '',
                'discountcode' => '',
            ],
            // Test user can be added to session 2.
            (object) [
                'email' => $student->email,
                'session' => $session2->id,
                'status' => '',
                'notificationtype' => '',
                'discountcode' => '',
            ],
        ];

        $bm->load_from_array($records);
        $errors = $bm->validate();

        $this->assertEmpty($errors);
        $this->assertTrue($bm->process());

        $sessions = facetoface_get_user_submissions($facetoface->id, $student->id);
        $this->assertCount(3, $sessions);
        $sessionids = array_column($sessions, 'sessionid');
        $this->assertContains($session1->id, $sessionids);
        $this->assertContains($session2->id, $sessionids);
        $this->assertContains($session3->id, $sessionids);
    }
}
