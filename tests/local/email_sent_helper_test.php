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

namespace mod_facetoface\local;

use mod_facetoface\booking_manager;
use mod_facetoface\booking_manager_bulk_attendance;
use mod_facetoface\event\confirmation_sent;
use mod_facetoface\event\reminder_sent;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/facetoface/lib.php");

/**
 * Tests for confirmation and reminder email log events.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_facetoface\local\email_sent_helper
 * @covers \mod_facetoface\event\email_sent
 * @covers \mod_facetoface\event\confirmation_sent
 * @covers \mod_facetoface\event\reminder_sent
 */
final class email_sent_helper_test extends \advanced_testcase {
    /**
     * Prepare each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Confirmation and reminder emails for the same session produce distinct events.
     */
    public function test_flush_keeps_confirmation_and_reminder_groups_separate(): void {
        [$course, $facetoface, $session] = $this->create_fixture();
        $first = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $second = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $batch = new email_sent_helper(confirmation_sent::SOURCE_BOOK_VIA_UPLOAD);
        $events = $this->redirectEvents();

        $batch->record_confirmation($facetoface, $session, (int)$first->id);
        $batch->record_reminder($facetoface, $session, (int)$second->id);
        $batch->flush();
        $batch->flush();

        $confirmations = $this->get_email_events($events, confirmation_sent::class);
        $reminders = $this->get_email_events($events, reminder_sent::class);
        $this->assertCount(2, $events->get_events());
        $this->assertCount(1, $confirmations);
        $this->assertCount(1, $reminders);
        $this->assertSame(
            [
                'userids' => [ (int)$first->id ],
                'source' => 'book_via_upload',
            ],
            $confirmations[0]->other
        );
        $this->assertSame([ 'userids' => [ (int)$second->id ] ], $reminders[0]->other);
        foreach ([ $confirmations[0], $reminders[0] ] as $event) {
            $this->assertSame('c', $event->crud);
            $this->assertSame(\core\event\base::LEVEL_OTHER, $event->edulevel);
            $this->assertSame('facetoface_sessions', $event->objecttable);
            $this->assertEquals(new \moodle_url('/mod/facetoface/attendees.php', [ 's' => $session->id ]), $event->get_url());
            $this->assertSame(
                [
                    'db' => 'facetoface_sessions',
                    'restore' => 'facetoface_session',
                ],
                $event::get_objectid_mapping()
            );
            $this->assertSame([ 'userids' => \core\event\base::NOT_MAPPED ], $event::get_other_mapping());
        }

        $this->assertDebuggingNotCalled();
    }

    /**
     * A self signup logs one attendee even when several email formats or dates are sent.
     *
     * @dataProvider notification_provider
     * @param int $notificationtype The requested email format.
     * @param bool $oneemailperday Whether each date receives its own invitation.
     * @param int $emailcount The number of emails expected.
     */
    public function test_self_signup_logs_successful_confirmation(int $notificationtype, bool $oneemailperday, int $emailcount): void {
        [$course, $facetoface, $session] = $this->create_fixture();
        if ($oneemailperday) {
            $session = $this->create_session($facetoface, time() + 2 * DAYSECS, true);
        }

        set_config('oneemailperday', $oneemailperday, 'facetoface');
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($user);
        $emails = $this->redirectEmails();
        $events = $this->redirectEvents();

        $this->assertTrue(facetoface_user_signup(
            $session,
            $facetoface,
            $course,
            '',
            $notificationtype,
            MDL_F2F_STATUS_BOOKED
        ));

        $this->assertCount($emailcount, $emails->get_messages());
        $logs = $this->get_email_events($events, confirmation_sent::class);
        $this->assertCount(1, $logs);
        $event = $logs[0];
        $this->assertSame((int)$user->id, (int)$event->userid);
        $this->assertSame([ (int)$user->id ], $event->other['userids']);
        $this->assertSame('self_sign_up', $event->other['source']);
        $this->assertSame((int)$session->id, $event->objectid);
        $this->assertSame((int)$course->id, (int)$event->courseid);
        $this->assertSame((int)$facetoface->cmid, (int)$event->contextinstanceid);
        $this->assertStringContainsString("user ids '$user->id'", $event->get_description());
        $this->assertStringContainsString('self sign-up via the GUI', $event->get_description());
        $this->assertEquals($session, $event->get_record_snapshot('facetoface_sessions', $session->id));
        $this->assertEquals($facetoface, $event->get_record_snapshot('facetoface', $facetoface->id));
        $this->assertDebuggingNotCalled();
    }

    /**
     * Requested notification formats and multi-date invitations.
     *
     * @return array<string, array{int, bool, int}> Test cases.
     */
    public static function notification_provider(): array {
        return [
            'text' => [ MDL_F2F_TEXT, false, 1 ],
            'calendar' => [ MDL_F2F_ICAL, false, 1 ],
            'both' => [ MDL_F2F_BOTH, false, 2 ],
            'calendar per date' => [ MDL_F2F_ICAL, true, 2 ],
        ];
    }

    /**
     * A batched booking loop logs all successful attendees when it ends and omits suppressed recipients.
     */
    public function test_booking_others_batches_only_emailed_attendees(): void {
        [$course, $facetoface, $session] = $this->create_fixture();
        $first = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $second = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $suspended = $this->getDataGenerator()->create_and_enrol($course, 'student', [ 'suspended' => 1 ]);
        $silent = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $emails = $this->redirectEmails();
        $events = $this->redirectEvents();

        foreach (email_sent_helper::batch([ $second, $suspended, $first, $first, $silent ]) as $user) {
            $this->assertTrue(facetoface_user_signup(
                $session,
                $facetoface,
                $course,
                '',
                MDL_F2F_TEXT,
                MDL_F2F_STATUS_BOOKED,
                $user->id,
                $user !== $silent
            ));
            $this->assertEmpty($this->get_email_events($events, confirmation_sent::class));
        }

        $this->assertCount(3, $emails->get_messages());
        $logs = $this->get_email_events($events, confirmation_sent::class);
        $this->assertCount(1, $logs);
        $this->assertSame([ (int)$first->id, (int)$second->id ], $logs[0]->other['userids']);
        $this->assertSame('book_others', $logs[0]->other['source']);
        $this->assertStringContainsString("user ids '$first->id, $second->id'", $logs[0]->get_description());
        $this->assertDebuggingNotCalled();
    }

    /**
     * A batched booking loop left by an exception logs the confirmations it already sent before the exception arrives.
     */
    public function test_batch_logs_sent_confirmations_when_loop_throws(): void {
        [$course, $facetoface, $session] = $this->create_fixture();
        $first = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $second = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->redirectEmails();
        $events = $this->redirectEvents();

        try {
            foreach (email_sent_helper::batch([ $first, $second ]) as $user) {
                $this->assertTrue(facetoface_user_signup(
                    $session,
                    $facetoface,
                    $course,
                    '',
                    MDL_F2F_TEXT,
                    MDL_F2F_STATUS_BOOKED,
                    $user->id
                ));
                throw new \RuntimeException('Stop the booking loop.');
            }

        } catch (\RuntimeException $exception) {
            $logs = $this->get_email_events($events, confirmation_sent::class);
            $this->assertCount(1, $logs);
            $this->assertSame([ (int)$first->id ], $logs[0]->other['userids']);
        }

        // The batch closed with the loop, so a later confirmation is logged at once.
        $this->assertTrue(facetoface_user_signup(
            $session,
            $facetoface,
            $course,
            '',
            MDL_F2F_TEXT,
            MDL_F2F_STATUS_BOOKED,
            $second->id
        ));
        $logs = $this->get_email_events($events, confirmation_sent::class);
        $this->assertCount(2, $logs);
        $this->assertSame([ (int)$second->id ], $logs[1]->other['userids']);
        $this->assertDebuggingNotCalled();
    }

    /**
     * Batches nest, log with their own source and log only once when closed again.
     */
    public function test_open_batch_nests_and_logs_once(): void {
        [$course, $facetoface, $session] = $this->create_fixture();
        $first = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $second = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $third = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->redirectEmails();
        $events = $this->redirectEvents();
        $signup = function (\stdClass $user) use ($course, $facetoface, $session): void {
            $this->assertTrue(facetoface_user_signup(
                $session,
                $facetoface,
                $course,
                '',
                MDL_F2F_TEXT,
                MDL_F2F_STATUS_BOOKED,
                $user->id
            ));
        };

        $upload = email_sent_helper::open_batch(confirmation_sent::SOURCE_BOOK_VIA_UPLOAD);
        try {
            foreach (email_sent_helper::batch([ $first ]) as $user) {
                $signup($user);
            }

            $signup($second);
            $logs = $this->get_email_events($events, confirmation_sent::class);
            $this->assertCount(1, $logs);
            $this->assertSame([ (int)$first->id ], $logs[0]->other['userids']);
            $this->assertSame('book_others', $logs[0]->other['source']);

        } finally {
            $upload->close();
        }

        $upload->close();
        $signup($third);

        $logs = $this->get_email_events($events, confirmation_sent::class);
        $this->assertCount(3, $logs);
        $this->assertSame([ (int)$second->id ], $logs[1]->other['userids']);
        $this->assertSame('book_via_upload', $logs[1]->other['source']);
        $this->assertSame([ (int)$third->id ], $logs[2]->other['userids']);
        $this->assertSame('book_others', $logs[2]->other['source']);
        $this->assertDebuggingNotCalled();
    }

    /**
     * Both upload processors group all emailed attendees by session.
     *
     * @dataProvider upload_provider
     * @param bool $sitelevel Whether to use the site administration upload processor.
     * @param bool $usefile Whether to read a real uploaded CSV file.
     */
    public function test_upload_batches_confirmation_emails(bool $sitelevel, bool $usefile): void {
        [$course, $facetoface, $session] = $this->create_fixture();
        $secondsession = $this->create_session($facetoface, time() + 3 * DAYSECS);
        $first = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $second = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $suppressed = $this->getDataGenerator()->create_and_enrol($course, 'student', [ 'suspended' => 1 ]);
        $manager = $this->create_upload_manager(
            $facetoface,
            $sitelevel,
            $usefile,
            [
                [ $second, $session ],
                [ $suppressed, $session ],
                [ $first, $secondsession ],
                [ $first, $session ],
            ]
        );
        $emails = $this->redirectEmails();
        $events = $this->redirectEvents();

        // These prepared rows are valid; exercise email logging through processing directly.
        $this->assertTrue($manager->process([]));

        $this->assertCount(3, $emails->get_messages());
        $logs = $this->get_email_events($events, confirmation_sent::class);
        $this->assertCount(2, $logs);
        $bysession = [];
        foreach ($logs as $event) {
            $bysession[$event->objectid] = $event;
            $this->assertSame($usefile ? 'book_via_upload' : 'book_others', $event->other['source']);
            $this->assertStringContainsString($usefile ? 'CSV upload' : 'booking others via the GUI', $event->get_description());
        }

        $this->assertSame([ (int)$first->id, (int)$second->id ], $bysession[$session->id]->other['userids']);
        $this->assertSame([ (int)$first->id ], $bysession[$secondsession->id]->other['userids']);
        $this->assertDebuggingNotCalled();
    }

    /**
     * Upload processors with both supported input modes.
     *
     * @return array<string, array{bool, bool}> Test cases.
     */
    public static function upload_provider(): array {
        return [
            'activity CSV' => [ false, true ],
            'activity memory' => [ false, false ],
            'site CSV' => [ true, true ],
            'site memory' => [ true, false ],
        ];
    }

    /**
     * The upload suppression setting produces no emails and no email log.
     *
     * @dataProvider upload_provider
     * @param bool $sitelevel Whether to use the site administration upload processor.
     * @param bool $usefile Whether to read a real uploaded CSV file.
     */
    public function test_upload_email_suppression_does_not_log(bool $sitelevel, bool $usefile): void {
        [$course, $facetoface, $session] = $this->create_fixture();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $manager = $this->create_upload_manager(
            $facetoface,
            $sitelevel,
            $usefile,
            [ [ $user, $session ] ]
        );
        $manager->suppress_email();
        $emails = $this->redirectEmails();
        $events = $this->redirectEvents();

        $this->assertTrue($manager->process([]));

        $this->assertEmpty($emails->get_messages());
        $this->assertEmpty($this->get_email_events($events, confirmation_sent::class));
        $this->assertDebuggingNotCalled();
    }

    /**
     * An upload stopped by an exception logs the confirmations it already sent and closes its batch.
     *
     * @dataProvider upload_provider
     * @param bool $sitelevel Whether to use the site administration upload processor.
     * @param bool $usefile Whether to read a real uploaded CSV file.
     */
    public function test_upload_logs_sent_confirmations_when_processing_throws(bool $sitelevel, bool $usefile): void {
        global $DB;

        [$course, $facetoface, $session] = $this->create_fixture();
        $first = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $second = $this->getDataGenerator()->create_and_enrol($course, 'student');
        // The failed copy to this third party stops the upload after its first attendee is emailed.
        $DB->set_field('facetoface', 'thirdparty', 'invalid-address', [ 'id' => $facetoface->id ]);
        $manager = $this->create_upload_manager(
            $facetoface,
            $sitelevel,
            $usefile,
            [
                [ $first, $session ],
                [ $second, $session ],
            ]
        );
        $this->redirectEmails();
        $events = $this->redirectEvents();

        try {
            $manager->process([]);
            $this->fail('The failed third-party copy should stop the upload.');

        } catch (\moodle_exception $exception) {
            $this->assertSame('error:cannotsendconfirmationthirdparty', $exception->errorcode);
        }

        $logs = $this->get_email_events($events, confirmation_sent::class);
        $this->assertCount(1, $logs);
        $this->assertSame([ (int)$first->id ], $logs[0]->other['userids']);
        $this->assertSame($usefile ? 'book_via_upload' : 'book_others', $logs[0]->other['source']);
        $this->assertDebuggingCalled();

        // The batch closed with the upload, so a later confirmation is logged at once.
        $this->assertTrue(facetoface_user_signup(
            $session,
            $facetoface,
            $course,
            '',
            MDL_F2F_TEXT,
            MDL_F2F_STATUS_BOOKED,
            $second->id
        ));
        $logs = $this->get_email_events($events, confirmation_sent::class);
        $this->assertCount(2, $logs);
        $this->assertSame([ (int)$second->id ], $logs[1]->other['userids']);
        $this->assertSame('book_others', $logs[1]->other['source']);
        $this->assertDebuggingNotCalled();
    }

    /**
     * Bookings that never send a confirmation must not record an email log.
     *
     * @dataProvider no_confirmation_provider
     * @param string $reason Why the confirmation is not sent.
     */
    public function test_unsent_confirmations_are_not_logged(string $reason): void {
        global $CFG;

        [$course, $facetoface, $session] = $this->create_fixture();
        $fields = [];
        if ($reason === 'suspended') {
            $fields['suspended'] = 1;

        } else if ($reason === 'nologin') {
            $fields['auth'] = 'nologin';

        } else if ($reason === 'invalid domain') {
            $fields['email'] = 'attendee@example.invalid';

        } else if ($reason === 'failed email') {
            $fields['email'] = 'invalid-address';
        }

        $user = $this->getDataGenerator()->create_and_enrol($course, 'student', $fields);
        if ($reason === 'missing subject') {
            $facetoface->confirmationsubject = '';

        } else if ($reason === 'missing body') {
            $facetoface->confirmationmessage = '';

        } else if ($reason === 'started session') {
            $session = $this->create_session($facetoface, time() - HOURSECS);

        } else if ($reason === 'site suppression') {
            $CFG->noemailever = true;
        }

        $emails = $this->redirectEmails();
        $events = $this->redirectEvents();
        try {
            $this->assertTrue(facetoface_user_signup(
                $session,
                $facetoface,
                $course,
                '',
                MDL_F2F_TEXT,
                MDL_F2F_STATUS_BOOKED,
                $user->id,
                $reason !== 'notify disabled'
            ));

        } finally {
            if ($reason === 'site suppression') {
                unset($CFG->noemailever);
            }
        }

        $this->assertEmpty($emails->get_messages());
        $this->assertEmpty($this->get_email_events($events, confirmation_sent::class));
        if (in_array($reason, [ 'invalid domain', 'failed email', 'site suppression' ], true)) {
            $this->assertDebuggingCalled();

        } else {
            $this->assertDebuggingNotCalled();
        }
    }

    /**
     * No-send conditions, including successful suppression responses from Moodle.
     *
     * @return array<string, array{string}> Test cases.
     */
    public static function no_confirmation_provider(): array {
        return [
            'notify disabled' => [ 'notify disabled' ],
            'missing subject' => [ 'missing subject' ],
            'missing body' => [ 'missing body' ],
            'started session' => [ 'started session' ],
            'suspended' => [ 'suspended' ],
            'nologin' => [ 'nologin' ],
            'invalid domain' => [ 'invalid domain' ],
            'failed email' => [ 'failed email' ],
            'site suppression' => [ 'site suppression' ],
        ];
    }

    /**
     * A third-party failure does not lose the log of an already delivered attendee email.
     */
    public function test_later_copy_failure_preserves_confirmation_log(): void {
        [$course, $facetoface, $session] = $this->create_fixture();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $facetoface->thirdparty = 'invalid-address';
        $emails = $this->redirectEmails();
        $events = $this->redirectEvents();

        $error = facetoface_send_confirmation_notice(
            $facetoface,
            $session,
            $user->id,
            MDL_F2F_TEXT,
            false
        );

        $this->assertSame('error:cannotsendconfirmationthirdparty', $error);
        $this->assertCount(1, $emails->get_messages());
        $logs = $this->get_email_events($events, confirmation_sent::class);
        $this->assertCount(1, $logs);
        $this->assertSame([ (int)$user->id ], $logs[0]->other['userids']);
        $this->assertSame('book_others', $logs[0]->other['source']);
        $this->assertDebuggingCalled();
    }

    /**
     * A successful copy to a third party does not imply the attendee received an email.
     */
    public function test_copy_only_delivery_does_not_log_attendee_as_emailed(): void {
        [$course, $facetoface, $session] = $this->create_fixture();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student', [ 'email' => 'attendee@example.invalid' ]);
        $facetoface->thirdparty = 'copy@example.com';
        $emails = $this->redirectEmails();
        $events = $this->redirectEvents();

        $this->assertSame(
            '',
            facetoface_send_confirmation_notice(
                $facetoface,
                $session,
                $user->id,
                MDL_F2F_TEXT,
                false
            )
        );

        $this->assertCount(1, $emails->get_messages());
        $this->assertSame('copy@example.com', $emails->get_messages()[0]->to);
        $this->assertEmpty($this->get_email_events($events, confirmation_sent::class));
        $this->assertDebuggingCalled();
    }

    /**
     * The separate waitlist template is not logged as a confirmation message.
     */
    public function test_waitlist_message_does_not_create_confirmation_log(): void {
        [$course, $facetoface, $session] = $this->create_fixture();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $facetoface->waitlistedsubject = 'Waitlist notice';
        $facetoface->waitlistedmessage = 'You have joined the waitlist.';
        $emails = $this->redirectEmails();
        $events = $this->redirectEvents();

        $this->assertSame(
            '',
            facetoface_send_confirmation_notice(
                $facetoface,
                $session,
                $user->id,
                MDL_F2F_TEXT,
                true
            )
        );

        $this->assertCount(1, $emails->get_messages());
        $this->assertEmpty($this->get_email_events($events, confirmation_sent::class));
        $this->assertDebuggingNotCalled();
    }

    /**
     * Cron logs each session's successfully emailed attendees once, omitting failures, started sessions and empty templates.
     */
    public function test_reminders_batch_successful_attendees_and_do_not_log_again(): void {
        global $DB;

        [$course, $facetoface, $session] = $this->create_fixture(time() + DAYSECS);
        $secondsession = $this->create_session($facetoface, time() + DAYSECS + HOURSECS);
        $startedsession = $this->create_session($facetoface, time() - HOURSECS);
        $first = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $second = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $suppressed = $this->getDataGenerator()->create_and_enrol($course, 'student', [ 'suspended' => 1 ]);
        $failed = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $DB->set_field(
            'user',
            'email',
            'invalid-address',
            [ 'id' => $failed->id ]
        );
        foreach ([ $second, $suppressed, $failed, $first ] as $user) {
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

        facetoface_user_signup(
            $secondsession,
            $facetoface,
            $course,
            '',
            MDL_F2F_TEXT,
            MDL_F2F_STATUS_BOOKED,
            $first->id,
            false
        );
        facetoface_user_signup(
            $startedsession,
            $facetoface,
            $course,
            '',
            MDL_F2F_TEXT,
            MDL_F2F_STATUS_BOOKED,
            $second->id,
            false
        );
        [$silentcourse, $silentactivity, $silentsession] = $this->create_fixture(time() + DAYSECS);
        $DB->set_field(
            'facetoface',
            'remindermessage',
            '',
            [ 'id' => $silentactivity->id ]
        );
        $silentuser = $this->getDataGenerator()->create_and_enrol($silentcourse, 'student');
        facetoface_user_signup(
            $silentsession,
            $silentactivity,
            $silentcourse,
            '',
            MDL_F2F_TEXT,
            MDL_F2F_STATUS_BOOKED,
            $silentuser->id,
            false
        );
        $emails = $this->redirectEmails();
        $events = $this->redirectEvents();

        $this->run_cron();

        $this->assertCount(3, $emails->get_messages());
        $logs = $this->get_email_events($events, reminder_sent::class);
        $this->assertCount(2, $logs);
        $bysession = [];
        foreach ($logs as $event) {
            $bysession[$event->objectid] = $event;
        }

        $this->assertSame([ (int)$first->id, (int)$second->id ], $bysession[$session->id]->other['userids']);
        $this->assertSame([ (int)$first->id ], $bysession[$secondsession->id]->other['userids']);
        $this->assertStringContainsString("user ids '$first->id, $second->id'", $bysession[$session->id]->get_description());
        $this->assertSame(
            1,
            (int)$DB->get_field(
                'facetoface_signups',
                'mailedreminder',
                [
                    'sessionid' => $startedsession->id, 'userid' => $second->id,
                ]
            )
        );
        $this->assertDebuggingCalled();

        $this->run_cron();

        $this->assertCount(3, $emails->get_messages());
        $this->assertCount(2, $this->get_email_events($events, reminder_sent::class));
        $this->assertSame(
            0,
            (int)$DB->get_field(
                'facetoface_signups',
                'mailedreminder',
                [
                    'sessionid' => $session->id, 'userid' => $failed->id,
                ]
            )
        );
        $this->assertDebuggingCalled();
    }

    /**
     * Create an activity and dated session with configured email templates.
     *
     * @param int|null $timestart The session start time.
     * @return array{\stdClass, \stdClass, \stdClass} Course, activity and session records.
     */
    private function create_fixture(?int $timestart = null): array {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_facetoface');
        $facetoface = $generator->create_instance([
            'course' => $course->id,
            'signuptype' => MOD_FACETOFACE_SIGNUP_MULTIPLE,
            'showoncalendar' => 0,
            'usercalentry' => 0,
            'confirmationsubject' => 'Booking confirmation',
            'confirmationmessage' => 'Your place is confirmed.',
            'remindersubject' => 'Booking reminder',
            'remindermessage' => 'Your session is coming up.',
            'reminderperiod' => 7,
        ]);

        return [ $course, $facetoface, $this->create_session($facetoface, $timestart ?? time() + 2 * DAYSECS) ];
    }

    /**
     * Create a session with one or two dates.
     *
     * @param \stdClass $facetoface The activity record.
     * @param int $timestart The first start time.
     * @param bool $twodates Whether to add a second date.
     * @return \stdClass The saved session.
     */
    private function create_session(\stdClass $facetoface, int $timestart, bool $twodates = false): \stdClass {
        $dates = [ [ 'timestart' => $timestart, 'timefinish' => $timestart + HOURSECS ] ];
        if ($twodates) {
            $dates[] = [ 'timestart' => $timestart + DAYSECS, 'timefinish' => $timestart + DAYSECS + HOURSECS ];
        }

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_facetoface');

        return $generator->create_session([ 'facetoface' => $facetoface->id, 'sessiondates' => $dates ]);
    }

    /**
     * Load an upload processor from canonical records or a real draft CSV file.
     *
     * @param \stdClass $facetoface The activity record.
     * @param bool $sitelevel Whether to use the site upload processor.
     * @param bool $usefile Whether to create an uploaded file.
     * @param array<int, array{\stdClass, \stdClass}> $bookings Pairs of user and session records.
     * @return booking_manager|booking_manager_bulk_attendance The configured upload processor.
     */
    private function create_upload_manager(
        \stdClass $facetoface,
        bool $sitelevel,
        bool $usefile,
        array $bookings
    ): booking_manager|booking_manager_bulk_attendance {
        global $USER;

        $manager = $sitelevel ? new booking_manager_bulk_attendance() : new booking_manager($facetoface->id);
        $headers = $manager::get_headers();
        $rows = [];
        $records = [];
        foreach ($bookings as [$user, $session]) {
            $row = [ $user->username, $session->id, 'booked', '', 'email' ];
            $rows[] = implode(',', $row);
            $records[] = (object)array_combine($headers, $row);
        }

        if ($usefile) {
            $itemid = file_get_unused_draft_itemid();
            get_file_storage()->create_file_from_string(
                [
                    'contextid' => \context_user::instance($USER->id)->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => $itemid,
                    'filepath' => '/',
                    'filename' => 'bookings.csv',
                ],
                implode(',', $headers) . "\n" . implode("\n", $rows) . "\n"
            );
            $manager->load_from_file($itemid);

        } else {
            $manager->load_from_array($records);
        }

        return $manager;
    }

    /**
     * Filter out other events generated during booking and emailing.
     *
     * @param \phpunit_event_sink $sink The captured event sink.
     * @param string $eventclass The exact event class.
     * @return \core\event\base[] Matching events.
     */
    private function get_email_events(\phpunit_event_sink $sink, string $eventclass): array {
        return array_values(array_filter($sink->get_events(), fn(\core\event\base $event): bool => get_class($event) === $eventclass));
    }

    /**
     * Run cron while capturing its normal progress output.
     */
    private function run_cron(): void {
        ob_start();
        try {
            $this->assertTrue(facetoface_cron());

        } finally {
            ob_end_clean();
        }
    }
}
