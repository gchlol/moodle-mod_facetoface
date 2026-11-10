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

use mod_facetoface\event\confirmation_email;
use mod_facetoface\event\confirmation_sent;
use mod_facetoface\event\confirmation_suppressed;
use mod_facetoface\event\reminder_sent;

/**
 * Collect the attendee emails that were sent or suppressed into session log events.
 *
 * Confirmation emails sent while a batch is open are logged as one event per session when the batch closes.
 * Confirmation emails sent while no batch is open are logged at once. Suppressed confirmation emails are
 * logged only in a batch, because only the booking pages that open one let the booker suppress them.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class email_sent_helper {
    /** @var self|null The batch that collects confirmation emails, if one is open. */
    private static ?self $openbatch = null;

    /** @var int The number of notices delivered to their attendees during this request. */
    private static int $attendeenotices = 0;

    /** @var string The booking source for confirmation emails. */
    private string $source;

    /** @var self|null The batch that was open when this batch was opened. */
    private ?self $previous = null;

    /**
     * Pending groups of sent or suppressed emails, keyed by event class and session ID.
     *
     * @var array<string, array{
     *     eventclass: string,
     *     facetoface: \stdClass,
     *     session: \stdClass,
     *     userids: array<int, int>
     * }>
     */
    private array $groups = [];

    /**
     * Create an email log batch for a booking operation.
     *
     * @param string $source The booking source for confirmation emails.
     */
    public function __construct(string $source = confirmation_sent::SOURCE_BOOK_OTHERS) {
        $this->source = $source;
    }

    /**
     * Batch the confirmation emails sent or suppressed while a booking loop runs.
     *
     * Loop over the returned generator instead of the items. The batch opens when the loop starts and closes
     * when the loop ends, breaks or is left by an exception, so each session's emails are logged as one event.
     *
     * @param iterable $items The items that the booking loop iterates over.
     * @param string $source The booking source for confirmation emails.
     * @return \Generator The same items, with their keys.
     */
    public static function batch(iterable $items, string $source = confirmation_sent::SOURCE_BOOK_OTHERS): \Generator {
        $emaillog = static::open_batch($source);
        try {
            yield from $items;

        } finally {
            $emaillog->close();
        }
    }

    /**
     * Batch the confirmation emails sent while a booking upload processes its rows.
     *
     * Loop over the returned generator instead of the rows, as with batch(). Rows read from an uploaded CSV file
     * are logged as a CSV upload, and rows passed in by code as booking others.
     *
     * @param iterable $rows The upload rows that the processing loop iterates over.
     * @param bool $usefile Whether the rows come from an uploaded CSV file.
     * @return \Generator The same rows, with their keys.
     */
    public static function upload_batch(iterable $rows, bool $usefile): \Generator {
        return static::batch(
            $rows,
            $usefile ? confirmation_sent::SOURCE_BOOK_VIA_UPLOAD : confirmation_sent::SOURCE_BOOK_OTHERS
        );
    }

    /**
     * Open a batch that collects the confirmation emails sent or suppressed until it closes.
     *
     * @param string $source The booking source for confirmation emails.
     * @return self The open batch, which the caller must close.
     */
    public static function open_batch(string $source = confirmation_sent::SOURCE_BOOK_OTHERS): self {
        $emaillog = new self($source);
        $emaillog->previous = static::$openbatch;
        static::$openbatch = $emaillog;

        return $emaillog;
    }

    /**
     * Log the emails in this batch and reopen the batch that it replaced.
     *
     * @return void
     */
    public function close(): void {
        if (static::$openbatch === $this) {
            static::$openbatch = $this->previous;
        }

        $this->flush();
    }

    /**
     * Count a notice as delivered if none of its attendee emails failed.
     *
     * facetoface_send_notice() calls this after emailing the attendee and before emailing anyone else. It always
     * sends the attendee at least one email: it adds a plain text email when no format is requested, and every
     * session has at least one date row for calendar emails. A notice with a failed attendee email does not count,
     * even if another of its emails was sent, which matches the error that facetoface_send_notice() shows.
     *
     * @param \stdClass $user The attendee recipient.
     * @param bool $emailerror Whether any email to the attendee failed.
     * @return void
     */
    public static function count_attendee_notice(\stdClass $user, bool $emailerror): void {
        if (!$emailerror && static::was_email_sent($user)) {
            static::$attendeenotices++;
        }
    }

    /**
     * Send a prepared confirmation notice and log it if the attendee was emailed.
     *
     * @param string|null $postsubject The email subject template.
     * @param string|null $posttext The email message template.
     * @param string|null $posttextmgrheading The manager message heading template.
     * @param int $notificationtype The notification formats and invite flag.
     * @param \stdClass $facetoface The Face-to-Face activity record.
     * @param \stdClass $session The session record.
     * @param int $userid The attendee ID.
     * @param bool $iswaitlisted Whether the notice is a waitlist message.
     * @return string Error message (or empty string if successful).
     */
    public static function send_confirmation_notice(
        ?string $postsubject,
        ?string $posttext,
        ?string $posttextmgrheading,
        int $notificationtype,
        \stdClass $facetoface,
        \stdClass $session,
        int $userid,
        bool $iswaitlisted
    ): string {
        $attendeenotices = static::$attendeenotices;
        try {
            return facetoface_send_notice(
                $postsubject,
                $posttext,
                $posttextmgrheading,
                $notificationtype,
                $facetoface,
                $session,
                $userid
            );

        } finally {
            // Record successful attendee sends even if a later recipient fails.
            if (!$iswaitlisted && static::$attendeenotices > $attendeenotices) {
                static::log_confirmation(
                    $facetoface,
                    $session,
                    $userid
                );
            }
        }
    }

    /**
     * Log a sent confirmation in the open batch, or at once if no batch is open.
     *
     * @param \stdClass $facetoface The Face-to-Face activity record.
     * @param \stdClass $session The session record.
     * @param int $userid The emailed attendee ID.
     * @return void
     */
    public static function log_confirmation(
        \stdClass $facetoface,
        \stdClass $session,
        int $userid
    ): void {
        global $USER;

        $emaillog = static::$openbatch;
        $flush = $emaillog === null;
        $emaillog = $emaillog ?? new self(
            (int)$USER->id === $userid
                ? confirmation_sent::SOURCE_SELF_SIGN_UP
                : confirmation_sent::SOURCE_BOOK_OTHERS
        );

        $emaillog->record_confirmation($facetoface, $session, $userid);
        if ($flush) {
            $emaillog->flush();
        }
    }

    /**
     * Log a signup's confirmation email as suppressed if the booker stopped one that would have been sent.
     *
     * facetoface_user_signup() calls this after each signup. Only the attendees page and the booking uploads let
     * the booker tick "Suppress email notification", and they book inside a batch, where a signup leaves the
     * attendee unnotified only when that box is ticked. Signups outside a batch, such as signing up for all
     * sessions at once, are not logged. The attendee is logged only if, with the box unticked, a confirmation
     * would have been sent and logged as sent: the attendee is booked rather than waitlisted or awaiting
     * approval, the session has not started, the activity has a confirmation subject and message, and Moodle
     * would email the attendee.
     *
     * @param \stdClass $facetoface The Face-to-Face activity record.
     * @param \stdClass $session The session record.
     * @param int $userid The attendee ID.
     * @param int $status The attendee's new signup status.
     * @param bool $notifyuser Whether the signup notified the attendee.
     * @param int $timenow The signup time.
     * @return void
     */
    public static function log_suppressed_confirmation(
        \stdClass $facetoface,
        \stdClass $session,
        int $userid,
        int $status,
        bool $notifyuser,
        int $timenow
    ): void {
        global $DB;

        if (
            static::$openbatch === null ||
            $notifyuser ||
            $status !== MDL_F2F_STATUS_BOOKED ||
            facetoface_has_session_started($session, $timenow) ||
            empty($facetoface->confirmationsubject) ||
            empty($facetoface->confirmationmessage)
        ) {
            return;
        }

        $user = $DB->get_record('user', [ 'id' => $userid ]);
        if (!$user || !static::would_email_be_sent($user)) {
            return;
        }

        static::$openbatch->record_confirmation($facetoface, $session, $userid, true);
    }

    /**
     * Distinguish a delivered email from Moodle's successful suppression responses.
     *
     * Call this only after email_to_user() returns true. This checks the conditions
     * under which that API reports success without handing an email to the mailer.
     *
     * @param \stdClass $user The attendee recipient passed to email_to_user().
     * @return bool Whether a successful email API result represents a sent email.
     */
    public static function was_email_sent(\stdClass $user): bool {
        global $CFG;

        if (
            !empty($CFG->noemailever) ||
            !empty($user->suspended) ||
            ($user->auth ?? '') === 'nologin' ||
            (
                defined('BEHAT_SITE_RUNNING') &&
                !defined('TEST_EMAILCATCHER_MAIL_SERVER') &&
                !defined('TEST_EMAILCATCHER_API_SERVER')
            )
        ) {
            return false;
        }

        $email = $user->email;
        if (email_should_be_diverted($email)) {
            $email = $CFG->divertallemailsto;
        }

        return substr($email, -8) !== '.invalid';
    }

    /**
     * Predict whether email_to_user() would send an email to the user.
     *
     * This checks the conditions under which that API fails before calling the mailer,
     * then the conditions that was_email_sent() checks. A mailer failure cannot be predicted.
     *
     * @param \stdClass $user The user record.
     * @return bool Whether an email to the user would be handed to the mailer.
     */
    private static function would_email_be_sent(\stdClass $user): bool {
        global $CFG;

        if (
            empty($user->email) ||
            !empty($user->deleted) ||
            over_bounce_threshold($user)
        ) {
            return false;
        }

        $email = $user->email;
        if (email_should_be_diverted($email)) {
            $email = $CFG->divertallemailsto;
        }

        return validate_email($email) && static::was_email_sent($user);
    }

    /**
     * Log the reminders that a cron run emailed to attendees, as one event per session.
     *
     * The run sets mailedreminder to its start time on each signup whose reminder email succeeds,
     * so this reads those values back instead of hooking each email.
     *
     * @param \stdClass[] $signupsdata The unmailed signups that the run processed.
     * @param int $timenow The run's start time, which it stores in mailedreminder.
     * @return void
     */
    public static function log_reminders(array $signupsdata, int $timenow): void {
        global $DB;

        $signups = [];
        foreach ($signupsdata as $signupdata) {
            $signups[(int)$signupdata->id] = $signupdata;
        }

        $sentids = [];
        foreach (array_chunk(array_keys($signups), 1000) as $signupids) {
            [$insql, $params] = $DB->get_in_or_equal($signupids, SQL_PARAMS_NAMED);
            $params['timenow'] = $timenow;
            $sentids = array_merge(
                $sentids,
                $DB->get_fieldset_select('facetoface_signups', 'id', "id $insql AND mailedreminder = :timenow", $params)
            );
        }

        $emaillog = new self();
        $facetofaces = [];
        $sessions = [];
        foreach ($sentids as $signupid) {
            $signup = $signups[(int)$signupid];
            $user = $DB->get_record('user', [ 'id' => $signup->userid ]);
            if (!$user || !static::was_email_sent($user)) {
                continue;
            }

            $facetofaceid = (int)$signup->facetofaceid;
            $sessionid = (int)$signup->sessionid;
            $facetofaces[$facetofaceid] ??= $DB->get_record('facetoface', [ 'id' => $facetofaceid ], '*', MUST_EXIST);
            $sessions[$sessionid] ??= facetoface_get_session($sessionid);
            $emaillog->record_reminder($facetofaces[$facetofaceid], $sessions[$sessionid], (int)$user->id);
        }

        $emaillog->flush();
    }

    /**
     * Record an attendee whose confirmation email was sent successfully or suppressed by the booker.
     *
     * @param \stdClass $facetoface The Face-to-Face activity record.
     * @param \stdClass $session The session record.
     * @param int $userid The attendee ID.
     * @param bool $suppressed Whether the booker suppressed the email instead of it being sent.
     * @return void
     * @throws \coding_exception If the booking source is unsupported.
     */
    public function record_confirmation(\stdClass $facetoface, \stdClass $session, int $userid, bool $suppressed = false): void {
        $sources = [
            confirmation_sent::SOURCE_SELF_SIGN_UP,
            confirmation_sent::SOURCE_BOOK_OTHERS,
            confirmation_sent::SOURCE_BOOK_VIA_UPLOAD,
        ];
        if (!in_array($this->source, $sources, true)) {
            throw new \coding_exception('Unsupported confirmation booking source.');
        }

        $this->record(
            $suppressed ? confirmation_suppressed::class : confirmation_sent::class,
            $facetoface,
            $session,
            $userid
        );
    }

    /**
     * Record an attendee whose reminder email was sent successfully.
     *
     * @param \stdClass $facetoface The Face-to-Face activity record.
     * @param \stdClass $session The session record.
     * @param int $userid The emailed attendee ID.
     * @return void
     */
    public function record_reminder(\stdClass $facetoface, \stdClass $session, int $userid): void {
        $this->record(
            reminder_sent::class,
            $facetoface,
            $session,
            $userid
        );
    }

    /**
     * Emit one event for each event class and session with sent or suppressed emails.
     *
     * @return void
     */
    public function flush(): void {
        foreach ($this->groups as $key => $group) {
            $facetoface = $group['facetoface'];
            $session = $group['session'];
            $cm = get_coursemodule_from_instance(
                'facetoface',
                (int)$facetoface->id,
                (int)$facetoface->course,
                false,
                MUST_EXIST
            );
            $userids = array_values($group['userids']);
            sort($userids, SORT_NUMERIC);

            $other = [ 'userids' => $userids ];
            $eventclass = $group['eventclass'];
            if (is_subclass_of($eventclass, confirmation_email::class)) {
                $other['source'] = $this->source;
            }

            $event = $eventclass::create([
                'context' => \context_module::instance((int)$cm->id),
                'objectid' => (int)$session->id,
                'other' => $other,
            ]);
            $event->add_record_snapshot('facetoface_sessions', $session);
            $event->add_record_snapshot('facetoface', $facetoface);
            $event->trigger();
            unset($this->groups[$key]);
        }
    }

    /**
     * Add a sent or suppressed email to its session group, omitting duplicate attendees.
     *
     * @param string $eventclass The email event class.
     * @param \stdClass $facetoface The Face-to-Face activity record.
     * @param \stdClass $session The session record.
     * @param int $userid The attendee ID.
     * @return void
     * @throws \coding_exception If the attendee ID is invalid.
     */
    private function record(
        string $eventclass,
        \stdClass $facetoface,
        \stdClass $session,
        int $userid
    ): void {
        if ($userid <= 0) {
            throw new \coding_exception('An attendee user ID must be a positive integer.');
        }

        $key = "$eventclass:" . (int)$session->id;
        $this->groups[$key] = $this->groups[$key] ?? [
            'eventclass' => $eventclass,
            'facetoface' => $facetoface,
            'session' => $session,
            'userids' => [],
        ];

        $this->groups[$key]['userids'][$userid] = $userid;
    }
}
