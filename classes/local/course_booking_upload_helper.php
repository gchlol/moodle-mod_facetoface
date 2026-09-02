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

use Closure;
use completion_completion;
use completion_info;
use context_course;
use lang_string;
use moodle_exception;

// GCHLOL: Isolate the branch-specific course-level CSV upload validation and historical
// booking and attendance workflow so the third-party manager file keeps only thin hooks.
/**
 * Handles the branch-specific course-level CSV upload workflow.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_booking_upload_helper {

    /** @var string[] Statuses that record attendance rather than create a plain booking. */
    private const ATTENDANCE_STATUSES = ['no_show', 'partially_attended', 'fully_attended'];

    /** @var string[] Statuses that add a user to a session. */
    private const BOOKING_STATUSES = ['', 'booked', 'waitlisted'];

    /** @var \stdClass */
    private \stdClass $facetoface;

    /** @var \stdClass */
    private \stdClass $course;

    /** @var context_course */
    private context_course $coursecontext;

    /** @var int */
    private int $facetofaceid;

    /** @var bool */
    private bool $usefile;

    /** @var bool */
    private bool $suppressemail;

    /** @var Closure */
    private Closure $recorditerator;

    /** @var Closure */
    private Closure $usermatcher;

    /** @var Closure */
    private Closure $notificationtypetransformer;

    /**
     * Constructor.
     *
     * @param \stdClass $facetoface Face-to-face activity record.
     * @param \stdClass $course Course record.
     * @param context_course $coursecontext Course context.
     * @param int $facetofaceid Face-to-face activity ID.
     * @param bool $usefile Whether the manager is processing a real file upload.
     * @param bool $suppressemail Whether confirmation emails are suppressed.
     * @param Closure $recorditerator Callback that returns a row iterator.
     * @param Closure $usermatcher Callback that matches a username to user records.
     * @param Closure $notificationtypetransformer Callback that maps notification strings to MDL_F2F_* codes.
     */
    public function __construct(
        \stdClass $facetoface,
        \stdClass $course,
        context_course $coursecontext,
        int $facetofaceid,
        bool $usefile,
        bool $suppressemail,
        Closure $recorditerator,
        Closure $usermatcher,
        Closure $notificationtypetransformer
    ) {
        $this->facetoface = $facetoface;
        $this->course = $course;
        $this->coursecontext = $coursecontext;
        $this->facetofaceid = $facetofaceid;
        $this->usefile = $usefile;
        $this->suppressemail = $suppressemail;
        $this->recorditerator = $recorditerator;
        $this->usermatcher = $usermatcher;
        $this->notificationtypetransformer = $notificationtypetransformer;
    }

    /**
     * Validate the records provided to ensure they can be processed without errors.
     *
     * @param int|null $timenow The current time to use for validation.
     * @return list<array{0:int|string, 1:string|\lang_string}> Validation errors keyed by CSV row.
     */
    public function validate(?int $timenow = null): array {
        $errors = [];
        $validationrows = [];
        $activesignupcache = [];
        $signupexistencecache = [];
        $timenow ??= time();

        foreach ($this->get_iterator() as $index => $entry) {
            $row = $index + 1;
            $errorcountbefore = count($errors);

            $entry->status = $entry->status ?? '';
            $entry->notificationtype = $entry->notificationtype ?? '';
            $entry->discountcode = $entry->discountcode ?? '';

            $userid = null;
            $userids = $this->match_users_username($entry->username, 'id');

            if (count($userids) > 1) {
                $errors[] = [$row, new lang_string('error:multipleusersmatched', 'mod_facetoface', $entry->username)];
            }

            if (empty($userids)) {
                $errors[] = [$row, new lang_string('error:userdoesnotexist', 'mod_facetoface', $entry->username)];
            }

            if (count($userids) === 1) {
                $userid = current($userids)->id;
            }

            $session = facetoface_get_session($entry->session);
            if (!$session) {
                $errors[] = [$row, new lang_string('error:sessiondoesnotexist', 'mod_facetoface', $entry->session)];
            }

            if ($session && $session->facetoface != $this->facetofaceid) {
                $errors[] = [
                    $row,
                    new lang_string('error:tryingtoupdatesessionfromanothermodule', 'mod_facetoface', (object)[
                        'session' => $entry->session,
                        'f' => $this->facetofaceid,
                    ]),
                ];
            }

            if (
                $session &&
                $session->facetoface == $this->facetofaceid &&
                isset($userid) &&
                !$this->validate_existing_booking_upload(
                    $row,
                    $entry->username,
                    $session,
                    $entry->status,
                    (int)$userid,
                    $errors,
                    $signupexistencecache,
                    $activesignupcache
                )
            ) {
                continue;
            }

            if ($session) {
                $this->validate_session_status_rules(
                    $row,
                    $entry->session,
                    $entry->status,
                    $session,
                    $timenow,
                    $errors
                );

                if (
                    isset($userid) &&
                    $entry->status !== 'cancelled' &&
                    $this->facetoface->signuptype != MOD_FACETOFACE_SIGNUP_MULTIPLE &&
                    ($currusersessions = facetoface_get_user_submissions($this->facetofaceid, $userid))
                ) {
                    foreach ($currusersessions as $currusersession) {
                        if ($currusersession->sessionid != $session->id) {
                            $errors[] = [
                                $row,
                                new lang_string(
                                    'error:addalreadysignedupattendee',
                                    'mod_facetoface',
                                    $entry->email ?? $entry->username
                                ),
                            ];
                            break;
                        }
                    }
                }
            }

            if (isset($userid) && !is_enrolled($this->coursecontext, $userid)) {
                $isenrolled = facetoface_enrol_user($this->coursecontext, $this->course->id, $userid);
                if (!$isenrolled) {
                    $errors[] = [
                        $row,
                        get_string('error:enrolmentfailed', 'mod_facetoface', $entry->username),
                    ];
                }
            }

            if (
                isset($entry->notificationtype) &&
                !in_array(
                    $this->transform_notification_type($entry->notificationtype),
                    [MDL_F2F_BOTH, MDL_F2F_TEXT, MDL_F2F_ICAL],
                    true
                )
            ) {
                $errors[] = [
                    $row,
                    new lang_string('error:invalidnotificationtypespecified', 'mod_facetoface', $entry->notificationtype),
                ];
            }

            if (
                isset($entry->status) &&
                !in_array(
                    $entry->status,
                    array_merge(self::BOOKING_STATUSES, ['cancelled'], self::ATTENDANCE_STATUSES),
                    true
                )
            ) {
                $errors[] = [
                    $row,
                    new lang_string('error:invalidstatusspecified', 'mod_facetoface', $entry->status),
                ];
            }

            if (
                $session &&
                isset($userid) &&
                count($errors) === $errorcountbefore &&
                $this->is_processable_status($entry->status)
            ) {
                $this->cache_validation_row(
                    $row,
                    $entry->username,
                    $entry->status,
                    $session,
                    (int)$userid,
                    $validationrows,
                    $activesignupcache
                );
            }
        }

        if ($this->facetoface->signuptype != MOD_FACETOFACE_SIGNUP_MULTIPLE) {
            $this->validate_multiple_user_sessions($validationrows, $errors);
        }

        $this->validate_unique_rows_and_capacity($validationrows, $errors);

        return $errors;
    }

    /**
     * Process the bookings in the file.
     *
     * @param list<array{0:int|string, 1:string|\lang_string}> $errors Validation errors returned from validate().
     * @return bool True after all rows without blocking errors are processed.
     * @throws moodle_exception When an attendance row cannot be applied.
     * @throws \Exception When cancellation fails.
     */
    public function process(array $errors): bool {
        $skip = self::extract_rows_to_skip($errors);

        $index = -1;
        foreach ($this->get_iterator() as $entry) {
            $index++;
            $row = $index + 1;

            if (isset($skip[$row])) {
                continue;
            }

            $this->process_row($entry, $row);
        }

        return true;
    }

    /**
     * Get an iterator for the records.
     *
     * @return \Generator Iterator yielding CSV row objects for validation or processing.
     */
    private function get_iterator(): \Generator {
        $recorditerator = $this->recorditerator;

        return $recorditerator();
    }

    /**
     * Match users for a given username.
     *
     * @param string $username Username to search.
     * @param string $fields Fields to return.
     * @return array<int, \stdClass> Matching user records keyed by user ID.
     */
    private function match_users_username(string $username, string $fields): array {
        $usermatcher = $this->usermatcher;

        return $usermatcher($username, $fields);
    }

    /**
     * Transform notification type to internal representation.
     *
     * @param string $type Notification type.
     * @return int|null Notification type constant, or null when the value is invalid.
     */
    private function transform_notification_type(string $type): ?int {
        $notificationtypetransformer = $this->notificationtypetransformer;

        return $notificationtypetransformer($type);
    }

    /**
     * Validate duplicate CSV rows and projected session capacity.
     *
     * @param array<int, array{session:\stdClass, userid:int, username:string, status:string, hasactivesignup:bool}>
     *     $validationrows Resolved, processable CSV rows keyed by row number.
     * @param list<array{0:int|string, 1:string|\lang_string}> $errors Validation errors, updated in place.
     * @return void
     */
    private function validate_unique_rows_and_capacity(array $validationrows, array &$errors): void {
        $skip = self::extract_rows_to_skip($errors);
        $seen = [];
        $validrows = [];

        foreach ($validationrows as $row => $details) {
            if (isset($skip[$row])) {
                continue;
            }

            $key = "{$details['session']->id}:{$details['userid']}";
            if (isset($seen[$key])) {
                $errors[] = [
                    $row,
                    new lang_string('error:duplicateuserinsessionupload', 'mod_facetoface', (object)[
                        'user' => $details['username'],
                        'session' => $details['session']->id,
                    ]),
                ];
                continue;
            }

            $seen[$key] = true;
            $validrows[$details['session']->id][$row] = $details;
        }

        foreach ($validrows as $sessionid => $sessionrows) {
            $firstrow = reset($sessionrows);
            $session = $firstrow['session'];
            if ($session->allowoverbook) {
                continue;
            }

            $available = (int)$session->capacity
                - facetoface_get_num_attendees($sessionid, MDL_F2F_STATUS_APPROVED);

            foreach ($sessionrows as $details) {
                if ($details['status'] === 'cancelled' && $details['hasactivesignup']) {
                    $available++;
                }
            }

            foreach ($sessionrows as $row => $details) {
                if ($details['status'] === 'cancelled' || $details['hasactivesignup']) {
                    continue;
                }

                if ($available > 0) {
                    $available--;
                    continue;
                }

                $errors[] = [
                    $row,
                    new lang_string('error:sessionoverbooked', 'mod_facetoface', (object)[
                        'session' => $sessionid,
                        'amount' => 1,
                    ]),
                ];
            }
        }
    }

    /**
     * Validate that a booking-style upload does not reuse existing signup history.
     *
     * @param int $row CSV row number.
     * @param string $username Username from the CSV row.
     * @param \stdClass $session Session record.
     * @param string $status CSV status.
     * @param int $userid Matched user ID.
     * @param list<array{0:int|string, 1:string|\lang_string}> $errors Validation errors, updated in place.
     * @param array<string, bool> $signupexistencecache Cached signup existence checks.
     * @param array<string, bool> $activesignupcache Cached active signup checks.
     * @return bool True when the row can continue validation.
     */
    private function validate_existing_booking_upload(
        int $row,
        string $username,
        \stdClass $session,
        string $status,
        int $userid,
        array &$errors,
        array &$signupexistencecache,
        array &$activesignupcache
    ): bool {
        global $DB;

        if (!$this->is_booking_status($status)) {
            return true;
        }

        $signupkey = $this->get_signup_cache_key($session->id, $userid);
        if (!array_key_exists($signupkey, $signupexistencecache)) {
            $signupexistencecache[$signupkey] = $DB->record_exists('facetoface_signups', [
                'sessionid' => $session->id,
                'userid' => $userid,
            ]);
        }

        if ($signupexistencecache[$signupkey]) {
            $errors[] = [
                $row,
                new lang_string('error:useralreadyinsession', 'mod_facetoface', (object)[
                    'user' => $username,
                    'session' => $session->id,
                ]),
            ];

            return false;
        }

        $activesignupcache[$signupkey] = false;

        return true;
    }

    /**
     * Validate session timing rules for branch-introduced statuses.
     *
     * @param int $row CSV row number.
     * @param string $sessionref Session reference from the CSV row.
     * @param string $status CSV status.
     * @param \stdClass $session Session record.
     * @param int $timenow Timestamp used for validation.
     * @param list<array{0:int|string, 1:string|\lang_string}> $errors Validation errors, updated in place.
     * @return void
     */
    private function validate_session_status_rules(
        int $row,
        string $sessionref,
        string $status,
        \stdClass $session,
        int $timenow,
        array &$errors
    ): void {
        if ($status === 'cancelled' && facetoface_has_session_started($session, $timenow)) {
            $errors[] = [$row, new lang_string('error:sessionalreadystarted', 'mod_facetoface', $sessionref)];
        }

        if ($status === 'waitlisted' && facetoface_has_session_started($session, $timenow)) {
            $errors[] = [
                $row,
                new lang_string('error:cannotwaitliststartedsession', 'mod_facetoface', $sessionref),
            ];
        }

        if (
            $session->datetimeknown &&
            $this->is_attendance_status($status) &&
            !facetoface_has_session_started($session, $timenow)
        ) {
            $errors[] = [
                $row,
                new lang_string('error:attendancesessionnotstarted', 'mod_facetoface', $sessionref),
            ];
        }
    }

    /**
     * Cache a processable row for the cross-row validation passes.
     *
     * @param int $row CSV row number.
     * @param string $username Username from the CSV row.
     * @param string $status CSV status.
     * @param \stdClass $session Session record.
     * @param int $userid Matched user ID.
     * @param array<int, array{session:\stdClass, userid:int, username:string, status:string, hasactivesignup:bool}>
     *     $validationrows Cached row metadata, updated in place.
     * @param array<string, bool> $activesignupcache Cached active signup checks.
     * @return void
     */
    private function cache_validation_row(
        int $row,
        string $username,
        string $status,
        \stdClass $session,
        int $userid,
        array &$validationrows,
        array &$activesignupcache
    ): void {
        $signupkey = $this->get_signup_cache_key($session->id, $userid);
        if (!array_key_exists($signupkey, $activesignupcache)) {
            $activesignupcache[$signupkey] = $this->has_active_signup_for_validation($session, $userid, $status);
        }

        $validationrows[$row] = [
            'session' => $session,
            'userid' => $userid,
            'username' => $username,
            'status' => $status,
            'hasactivesignup' => $activesignupcache[$signupkey],
        ];
    }

    /**
     * Validate rows for single-session activities after per-row validation succeeds.
     *
     * @param array<int, array{session:\stdClass, userid:int, username:string, status:string, hasactivesignup:bool}>
     *     $validationrows Resolved, processable CSV rows keyed by row number.
     * @param list<array{0:int|string, 1:string|\lang_string}> $errors Validation errors, updated in place.
     * @return void
     */
    private function validate_multiple_user_sessions(array $validationrows, array &$errors): void {
        $skip = self::extract_rows_to_skip($errors);
        $usersessions = [];

        foreach ($validationrows as $row => $details) {
            if ($details['status'] === 'cancelled' || isset($skip[$row])) {
                continue;
            }

            $userid = $details['userid'];
            if (!isset($usersessions[$userid])) {
                $usersessions[$userid] = [
                    'username' => $details['username'],
                    'rows' => [],
                    'sessions' => [],
                ];
            }

            $usersessions[$userid]['rows'][] = $row;
            if (!in_array($details['session']->id, $usersessions[$userid]['sessions'], true)) {
                $usersessions[$userid]['sessions'][] = $details['session']->id;
            }
        }

        $doublebookedusers = array_filter($usersessions, function($usersession) {
            return count($usersession['sessions']) > 1;
        });

        foreach ($doublebookedusers as $details) {
            $errors[] = [
                implode(', ', $details['rows']),
                new lang_string('error:multipleusersessions', 'mod_facetoface', $details['username']),
            ];
        }
    }

    /**
     * Return whether a CSV status has a corresponding processing path.
     *
     * @param string $status CSV status.
     * @return bool True when the status has a corresponding processing path.
     */
    private function is_processable_status(string $status): bool {
        return $status === 'cancelled'
            || $this->is_booking_status($status)
            || $this->is_attendance_status($status);
    }

    /**
     * Return whether a CSV status creates a booking.
     *
     * @param string $status CSV status.
     * @return bool True when the status creates or updates a booking.
     */
    private function is_booking_status(string $status): bool {
        return in_array($status, self::BOOKING_STATUSES, true);
    }

    /**
     * Return whether a CSV status records attendance.
     *
     * @param string $status CSV status.
     * @return bool True when the status records attendance.
     */
    private function is_attendance_status(string $status): bool {
        return in_array($status, self::ATTENDANCE_STATUSES, true);
    }

    /**
     * Return whether a status lookup is required to project capacity.
     *
     * @param \stdClass $session Session record.
     * @param int $userid Matched user ID.
     * @param string $status CSV status.
     * @return bool True when the user already holds an active signup relevant to validation.
     */
    private function has_active_signup_for_validation(\stdClass $session, int $userid, string $status): bool {
        $needsactivelookup = !$session->allowoverbook
            && ($status === 'cancelled' || $this->is_attendance_status($status));

        return $needsactivelookup && $this->get_active_signup_id($session->id, $userid) !== null;
    }

    /**
     * Build the signup cache key used during validation.
     *
     * @param int $sessionid Session ID.
     * @param int $userid User ID.
     * @return string Cache key in sessionid:userid format.
     */
    private function get_signup_cache_key(int $sessionid, int $userid): string {
        return "{$sessionid}:{$userid}";
    }

    /**
     * Get the user's active signup ID for a session, if any.
     *
     * @param int $sessionid The session ID.
     * @param int $userid The user ID.
     * @return int|null The signup ID, or null if the user holds no active signup.
     */
    private function get_active_signup_id(int $sessionid, int $userid): ?int {
        global $DB;

        $sql = "SELECT su.id
                  FROM {facetoface_signups} su
                  JOIN {facetoface_signups_status} ss ON ss.signupid = su.id
                 WHERE su.sessionid = ?
                   AND su.userid = ?
                   AND ss.superceded = 0
                   AND ss.statuscode >= ?";
        $signupid = $DB->get_field_sql($sql, [$sessionid, $userid, MDL_F2F_STATUS_APPROVED]);

        return $signupid ? (int)$signupid : null;
    }

    /**
     * Get the user's signup ID for a session regardless of its current status.
     *
     * @param int $sessionid The session ID.
     * @param int $userid The user ID.
     * @return int The signup ID.
     */
    private function get_signup_id(int $sessionid, int $userid): int {
        global $DB;

        return (int)$DB->get_field(
            'facetoface_signups',
            'id',
            ['sessionid' => $sessionid, 'userid' => $userid],
            MUST_EXIST
        );
    }

    /**
     * Process a single validated booking row.
     *
     * @param \stdClass $entry CSV row data.
     * @param int $row CSV row number.
     * @return void
     * @throws moodle_exception When attendance cannot be applied.
     * @throws \Exception When cancellation fails.
     */
    private function process_row(\stdClass $entry, int $row): void {
        $user = current($this->match_users_username($entry->username, '*'));
        $session = facetoface_get_session($entry->session);

        if ($entry->status === 'cancelled') {
            $this->process_cancellation($session, (int)$user->id);

            return;
        }

        $statuscode = $this->get_status_code($entry->status);
        if ($this->is_booking_status_code($statuscode)) {
            $this->process_signup_row(
                $session,
                $user,
                $entry,
                $statuscode
            );

            return;
        }

        if ($this->is_attendance_status_code($statuscode)) {
            $this->process_attendance_row(
                $session,
                $user,
                $entry,
                $row,
                $statuscode
            );
        }
    }

    /**
     * Cancel a booking for a validated row.
     *
     * @param \stdClass $session Session record.
     * @param int $userid User ID.
     * @return void
     * @throws \Exception When cancellation fails.
     */
    private function process_cancellation(\stdClass $session, int $userid): void {
        if (!facetoface_user_cancel(
            $session,
            $userid,
            true,
            $cancelerr
        )) {
            throw new \Exception($cancelerr);
        }

        $timenow = time();
        if (!facetoface_has_session_started($session, $timenow) && !$this->suppressemail) {
            facetoface_send_cancellation_notice($this->facetoface, $session, $userid);
        }
    }

    /**
     * Create or update a booking-style signup for a validated row.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $user User record.
     * @param \stdClass $entry CSV row data.
     * @param int $statuscode Signup status code.
     * @return void
     */
    private function process_signup_row(
        \stdClass $session,
        \stdClass $user,
        \stdClass $entry,
        int $statuscode
    ): void {
        $statuscode = $this->normalise_booking_status_code($session, $statuscode);

        $this->create_import_signup(
            $session,
            $user,
            $entry->discountcode ?? '',
            $this->transform_notification_type($entry->notificationtype) ?? -1,
            $statuscode
        );

        $this->trigger_bulk_booking_created_event($session, (int)$user->id);
    }

    /**
     * Create the signup required by a validated import row.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $user User record.
     * @param string $discountcode Discount code from the CSV row.
     * @param int $notificationtype Notification type code.
     * @param int $statuscode Signup status code.
     * @return int Signup ID.
     */
    private function create_import_signup(
        \stdClass $session,
        \stdClass $user,
        string $discountcode,
        int $notificationtype,
        int $statuscode
    ): int {
        facetoface_enrol_user($this->coursecontext, $this->course->id, $user->id);

        if ($this->should_bypass_approval_for_past_booking($session, $statuscode)) {
            return $this->upsert_historical_booking_signup(
                $session,
                (int)$user->id,
                $discountcode,
                $notificationtype,
                $statuscode
            );
        }

        facetoface_user_signup(
            $session,
            $this->facetoface,
            $this->course,
            $discountcode,
            $notificationtype,
            $statuscode,
            $user->id,
            !$this->suppressemail
        );

        return $this->get_signup_id($session->id, $user->id);
    }

    /**
     * Return whether the import must bypass approval to preserve a historical booking.
     *
     * @param \stdClass $session Session record.
     * @param int $statuscode Signup status code.
     * @return bool True when the historical booking should bypass the approval workflow.
     */
    private function should_bypass_approval_for_past_booking(\stdClass $session, int $statuscode): bool {
        return $statuscode === MDL_F2F_STATUS_BOOKED
            && \mod_facetoface\helper::is_approval_required((object)$this->facetoface)
            && facetoface_has_session_started($session, time());
    }

    /**
     * Create or reactivate a historical booking without the legacy approval workflow.
     *
     * @param \stdClass $session Session record.
     * @param int $userid User ID.
     * @param string $discountcode Discount code from the CSV row.
     * @param int $notificationtype Notification type code.
     * @param int $statuscode Signup status code.
     * @return int Signup ID.
     * @throws moodle_exception When the signup or status cannot be stored.
     */
    private function upsert_historical_booking_signup(
        \stdClass $session,
        int $userid,
        string $discountcode,
        int $notificationtype,
        int $statuscode
    ): int {
        global $DB;

        $timenow = time();
        $usersignup = $DB->get_record('facetoface_signups', ['sessionid' => $session->id, 'userid' => $userid]);
        if (!$usersignup) {
            $usersignup = new \stdClass();
            $usersignup->sessionid = $session->id;
            $usersignup->userid = $userid;
        }

        $usersignup->mailedreminder = 0;
        $usersignup->notificationtype = $notificationtype;
        $usersignup->discountcode = trim(strtoupper($discountcode));
        if (empty($usersignup->discountcode)) {
            $usersignup->discountcode = null;
        }

        $usersignup = $this->persist_signup_record($usersignup);

        if (!facetoface_update_signup_status($usersignup->id, $statuscode, $userid)) {
            throw new moodle_exception('error:f2ffailedupdatestatus', 'facetoface');
        }

        if ($this->facetoface->usercalentry && $this->is_booking_status_code($statuscode)) {
            facetoface_add_session_to_calendar(
                $session,
                $this->facetoface,
                'user',
                $userid,
                'booking'
            );
        }

        $this->mark_booking_completion_in_progress_if_enabled($statuscode, $userid, $timenow);

        return (int)$usersignup->id;
    }

    /**
     * Mark course completion as in progress when a historical booking becomes active.
     *
     * @param int $statuscode Signup status code.
     * @param int $userid User ID.
     * @param int $timenow Timestamp used for the completion record.
     * @return void
     */
    private function mark_booking_completion_in_progress_if_enabled(
        int $statuscode,
        int $userid,
        int $timenow
    ): void {
        if (!$this->is_booking_status_code($statuscode)) {
            return;
        }

        $completion = new completion_info($this->course);
        if (!$completion->is_enabled()) {
            return;
        }

        $completionrecord = new completion_completion([
            'course' => $this->course->id,
            'userid' => $userid,
        ]);
        $completionrecord->mark_inprogress($timenow);
    }

    /**
     * Insert or update a signup record.
     *
     * @param \stdClass $usersignup Signup record to persist.
     * @return \stdClass Persisted signup record.
     * @throws moodle_exception When the signup cannot be stored.
     */
    private function persist_signup_record(\stdClass $usersignup): \stdClass {
        if (empty($usersignup->id)) {
            $usersignup->id = $this->insert_signup_record($usersignup);

            return $usersignup;
        }

        $this->update_signup_record($usersignup);

        return $usersignup;
    }

    /**
     * Insert a new signup record.
     *
     * @param \stdClass $usersignup Signup record to persist.
     * @return int Persisted signup ID.
     * @throws moodle_exception When the signup cannot be stored.
     */
    private function insert_signup_record(\stdClass $usersignup): int {
        global $DB;

        $signupid = $DB->insert_record('facetoface_signups', $usersignup);
        if ($signupid) {
            return (int)$signupid;
        }

        throw new moodle_exception('error:couldnotupdatef2frecord', 'facetoface');
    }

    /**
     * Update an existing signup record.
     *
     * @param \stdClass $usersignup Signup record to persist.
     * @return void
     * @throws moodle_exception When the signup cannot be stored.
     */
    private function update_signup_record(\stdClass $usersignup): void {
        global $DB;

        if ($DB->update_record('facetoface_signups', $usersignup)) {
            return;
        }

        throw new moodle_exception('error:couldnotupdatef2frecord', 'facetoface');
    }

    /**
     * Apply attendance for a validated row.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $user User record.
     * @param \stdClass $entry CSV row data.
     * @param int $row CSV row number.
     * @param int $statuscode Attendance status code.
     * @return void
     * @throws moodle_exception When attendance cannot be applied.
     */
    private function process_attendance_row(
        \stdClass $session,
        \stdClass $user,
        \stdClass $entry,
        int $row,
        int $statuscode
    ): void {
        $signupid = $this->ensure_signup_for_attendance($session, $user, $entry);
        if (!$this->apply_attendance_signup_status($signupid, $statuscode)) {
            throw new moodle_exception(
                'error:attendanceuploadfailed',
                'mod_facetoface',
                '',
                (object)[
                    'user' => $entry->username,
                    'session' => $session->id,
                    'line' => $row + 1,
                ]
            );
        }
    }

    /**
     * Ensure that an attendance row has an active signup to update.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $user User record.
     * @param \stdClass $entry CSV row data.
     * @return int Active signup ID.
     */
    private function ensure_signup_for_attendance(\stdClass $session, \stdClass $user, \stdClass $entry): int {
        $signupid = $this->get_active_signup_id($session->id, $user->id);
        if ($signupid !== null) {
            return $signupid;
        }

        $signupid = $this->create_import_signup(
            $session,
            $user,
            $entry->discountcode ?? '',
            $this->transform_notification_type($entry->notificationtype) ?? -1,
            MDL_F2F_STATUS_BOOKED
        );

        $this->trigger_bulk_booking_created_event($session, (int)$user->id);

        return $signupid;
    }

    /**
     * Apply an attendance status to a signup.
     *
     * @param int $signupid Signup ID.
     * @param int $statuscode Attendance status code.
     * @return bool True when both the signup status and attendance grade are stored.
     */
    private function apply_attendance_signup_status(int $signupid, int $statuscode): bool {
        global $USER;

        $grade = $this->get_attendance_grade($statuscode);

        return facetoface_update_signup_status(
            $signupid,
            $statuscode,
            $USER->id,
            '',
            $grade
        )
            && facetoface_take_individual_attendance($signupid, $grade);
    }

    /**
     * Convert an attendance status code into its grade.
     *
     * @param int $statuscode Attendance status code.
     * @return int Attendance grade percentage for the supplied status code.
     */
    private function get_attendance_grade(int $statuscode): int {
        switch ($statuscode) {
            case MDL_F2F_STATUS_NO_SHOW:
                return 0;
            case MDL_F2F_STATUS_PARTIALLY_ATTENDED:
                return 50;
            case MDL_F2F_STATUS_FULLY_ATTENDED:
                return 100;
        }

        throw new moodle_exception(
            'error:invalidstatusspecified',
            'mod_facetoface',
            '',
            $statuscode
        );
    }

    /**
     * Trigger the branch-introduced bulk booking event when processing a file upload.
     *
     * @param \stdClass $session Session record.
     * @param int $userid User ID.
     * @return void
     */
    private function trigger_bulk_booking_created_event(\stdClass $session, int $userid): void {
        \mod_facetoface\event\bulk_booking_created::trigger_from_bulk_upload_if_needed(
            $this->usefile,
            $this->facetoface,
            $session,
            $userid
        );
    }

    /**
     * Convert a CSV status into the corresponding status code.
     *
     * @param string $status CSV status.
     * @return int Signup status code derived from the CSV value.
     */
    private function get_status_code(string $status): int {
        return array_search($status, facetoface_statuses()) ?: MDL_F2F_STATUS_BOOKED;
    }

    /**
     * Normalise booking status codes for sessions without a known date/time.
     *
     * @param \stdClass $session Session record.
     * @param int $statuscode Signup status code.
     * @return int Booking status code adjusted for the session timing rules.
     */
    private function normalise_booking_status_code(\stdClass $session, int $statuscode): int {
        if ($statuscode === MDL_F2F_STATUS_BOOKED && !$session->datetimeknown) {
            return MDL_F2F_STATUS_WAITLISTED;
        }

        return $statuscode;
    }

    /**
     * Return whether the status code creates a booking.
     *
     * @param int $statuscode Signup status code.
     * @return bool True when the status code represents a booking state.
     */
    private function is_booking_status_code(int $statuscode): bool {
        return in_array($statuscode, [MDL_F2F_STATUS_BOOKED, MDL_F2F_STATUS_WAITLISTED], true);
    }

    /**
     * Return whether the status code records attendance.
     *
     * @param int $statuscode Signup status code.
     * @return bool True when the status code represents an attendance state.
     */
    private function is_attendance_status_code(int $statuscode): bool {
        return in_array($statuscode, [
            MDL_F2F_STATUS_NO_SHOW,
            MDL_F2F_STATUS_PARTIALLY_ATTENDED,
            MDL_F2F_STATUS_FULLY_ATTENDED,
        ], true);
    }

    /**
     * Convert the error array into a set of row numbers to skip.
     *
     * @param list<array{0:int|string, 1:string|\lang_string}> $errors Validation errors from validate().
     * @return array<int, bool> Map of CSV row numbers to skip.
     * @throws moodle_exception When the error format is invalid.
     */
    private static function extract_rows_to_skip(array $errors): array {
        $skip = [];

        foreach ($errors as $error) {
            if (!is_array($error)) {
                throw new moodle_exception(
                    'error:errormustbeanarray',
                    'mod_facetoface',
                    '',
                    $error
                );
            }

            $rows = is_string($error[0]) ? explode(',', $error[0]) : [$error[0]];
            foreach ($rows as $row) {
                $row = trim((string)$row);
                if (!is_numeric($row)) {
                    throw new moodle_exception(
                        'error:invalidrownumber',
                        'mod_facetoface',
                        '',
                        (object)['value' => $error[0], 'type' => gettype($error[0])]
                    );
                }

                $skip[(int)$row] = true;
            }
        }

        return $skip;
    }
}
// GCHLOL ends.
