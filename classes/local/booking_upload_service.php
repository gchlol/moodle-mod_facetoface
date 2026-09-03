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

use context_course;
use Exception;
use lang_string;
use mod_facetoface\event\bulk_booking_created;
use mod_facetoface\helper;
use moodle_exception;
use stdClass;

/**
 * Shared domain workflow for course-level and site-wide booking uploads.
 *
 * The upload facades retain responsibility for parsing their different CSV formats,
 * resolving their scope, and preserving their validation order. This service owns
 * the state-changing workflow and the validation rules which must remain identical.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_upload_service {

    /** @var string[] Statuses that record attendance rather than create a plain booking. */
    protected const ATTENDANCE_STATUSES = ['no_show', 'partially_attended', 'fully_attended'];

    /** @var string[] Statuses that add a user to a session. */
    protected const BOOKING_STATUSES = ['', 'booked', 'waitlisted'];

    /** @var bool Whether the manager is processing a real file upload. */
    protected bool $usefile;

    /** @var bool Whether confirmation emails are suppressed. */
    protected bool $suppressemail;

    /** @var bool Whether validation messages should remain lazy lang_string objects. */
    protected bool $defererrormessages;

    /**
     * Constructor.
     *
     * @param bool $usefile Whether the manager is processing a real file upload.
     * @param bool $suppressemail Whether confirmation emails are suppressed.
     * @param bool $defererrormessages Whether validation messages should be returned as lang_string objects.
     */
    public function __construct(bool $usefile, bool $suppressemail, bool $defererrormessages) {
        $this->usefile = $usefile;
        $this->suppressemail = $suppressemail;
        $this->defererrormessages = $defererrormessages;
    }

    /**
     * Validate duplicate CSV rows and projected session capacity.
     *
     * @param array<int, array{session:stdClass, userid:int, username:string, status:string, hasactivesignup:bool}>
     *     $validationrows Resolved, processable CSV rows keyed by row number.
     * @param list<array{0:int|string, 1:string|lang_string}> $errors Validation errors, updated in place.
     * @return void
     */
    public function validate_unique_rows_and_capacity(array $validationrows, array &$errors): void {
        $skip = static::extract_rows_to_skip($errors);
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
                    $this->get_error_message('error:duplicateuserinsessionupload', (object)[
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
                    $this->get_error_message('error:sessionoverbooked', (object)[
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
     * @param stdClass $session Session record.
     * @param string $status CSV status.
     * @param int $userid Matched user ID.
     * @param list<array{0:int|string, 1:string|lang_string}> $errors Validation errors, updated in place.
     * @param array<string, bool> $signupexistencecache Cached signup existence checks.
     * @param array<string, bool> $activesignupcache Cached active signup checks.
     * @return bool True when the row can continue validation.
     */
    public function validate_existing_booking_upload(
        int $row,
        string $username,
        stdClass $session,
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

        $signupkey = static::get_signup_cache_key($session->id, $userid);
        if (!array_key_exists($signupkey, $signupexistencecache)) {
            $signupexistencecache[$signupkey] = $DB->record_exists('facetoface_signups', [
                'sessionid' => $session->id,
                'userid' => $userid,
            ]);
        }

        if (!$signupexistencecache[$signupkey]) {
            $activesignupcache[$signupkey] = false;

            return true;
        }

        $errors[] = [
            $row,
            $this->get_error_message('error:useralreadyinsession', (object)[
                'user' => $username,
                'session' => $session->id,
            ]),
        ];

        return false;
    }

    /**
     * Validate timing restrictions for a CSV status.
     *
     * @param int $row CSV row number.
     * @param string $sessionref Session reference from the CSV row.
     * @param string $status CSV status.
     * @param stdClass $session Session record.
     * @param int $timenow Timestamp used for validation.
     * @param list<array{0:int|string, 1:string|lang_string}> $errors Validation errors, updated in place.
     * @return bool True when no timing error was added.
     */
    public function validate_session_status_rules(
        int $row,
        string $sessionref,
        string $status,
        stdClass $session,
        int $timenow,
        array &$errors
    ): bool {
        $isvalid = true;

        if ($status === 'cancelled' && facetoface_has_session_started($session, $timenow)) {
            $errors[] = [
                $row,
                $this->get_error_message('error:sessionalreadystarted', $sessionref),
            ];
            $isvalid = false;
        }

        if ($status === 'waitlisted' && facetoface_has_session_started($session, $timenow)) {
            $errors[] = [
                $row,
                $this->get_error_message('error:cannotwaitliststartedsession', $sessionref),
            ];
            $isvalid = false;
        }

        if (
            $session->datetimeknown &&
            $this->is_attendance_status($status) &&
            !facetoface_has_session_started($session, $timenow)
        ) {
            $errors[] = [
                $row,
                $this->get_error_message('error:attendancesessionnotstarted', $sessionref),
            ];
            $isvalid = false;
        }

        return $isvalid;
    }

    /**
     * Cache a processable row for cross-row validation.
     *
     * @param int $row CSV row number.
     * @param string $username Username from the CSV row.
     * @param string $status CSV status.
     * @param stdClass $session Session record.
     * @param int $userid Matched user ID.
     * @param array<int, array{session:stdClass, userid:int, username:string, status:string, hasactivesignup:bool}>
     *     $validationrows Cached row metadata, updated in place.
     * @param array<string, bool> $activesignupcache Cached active signup checks.
     * @return void
     */
    public function cache_validation_row(
        int $row,
        string $username,
        string $status,
        stdClass $session,
        int $userid,
        array &$validationrows,
        array &$activesignupcache
    ): void {
        $signupkey = static::get_signup_cache_key($session->id, $userid);
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
     * Return whether a CSV status has a processing path.
     *
     * @param string $status CSV status.
     * @return bool True when the status can be processed.
     */
    public function is_processable_status(string $status): bool {
        return $status === 'cancelled'
            || $this->is_booking_status($status)
            || $this->is_attendance_status($status);
    }

    /**
     * Return whether a CSV status creates a booking.
     *
     * @param string $status CSV status.
     * @return bool True when the status creates a booking.
     */
    public function is_booking_status(string $status): bool {
        return in_array($status, static::BOOKING_STATUSES, true);
    }

    /**
     * Return whether a CSV status records attendance.
     *
     * @param string $status CSV status.
     * @return bool True when the status records attendance.
     */
    public function is_attendance_status(string $status): bool {
        return in_array($status, static::ATTENDANCE_STATUSES, true);
    }

    /**
     * Process one validated, normalised upload row.
     *
     * @param stdClass $entry Canonical row containing username, status, and discountcode.
     * @param stdClass $session Session record.
     * @param stdClass $facetoface Face-to-face activity record.
     * @param stdClass $course Course record.
     * @param context_course $coursecontext Course context.
     * @param stdClass $user User record.
     * @param int $row One-based data-row number.
     * @param int|null $notificationtype Notification type code.
     * @return void
     * @throws moodle_exception When attendance cannot be applied.
     * @throws Exception When cancellation fails.
     */
    public function process_row(
        stdClass $entry,
        stdClass $session,
        stdClass $facetoface,
        stdClass $course,
        context_course $coursecontext,
        stdClass $user,
        int $row,
        ?int $notificationtype
    ): void {
        if ($entry->status === 'cancelled') {
            $this->process_cancellation($session, $facetoface, (int)$user->id);

            return;
        }

        $statuscode = static::get_status_code($entry->status);
        if (static::is_booking_status_code($statuscode)) {
            $this->process_signup_row(
                $session,
                $facetoface,
                $course,
                $coursecontext,
                $user,
                $entry->discountcode,
                $notificationtype,
                $statuscode
            );

            return;
        }

        if (static::is_attendance_status_code($statuscode)) {
            $this->process_attendance_row(
                $session,
                $facetoface,
                $course,
                $coursecontext,
                $user,
                $entry->username ?? $entry->email ?? '',
                $entry->discountcode,
                $notificationtype,
                $row,
                $statuscode
            );
        }
    }

    /**
     * Convert validation errors to a set of rows which must be skipped.
     *
     * @param list<array{0:int|string, 1:string|lang_string}> $errors Validation errors.
     * @return array<int, bool> Keys are one-based row numbers to skip.
     * @throws moodle_exception When an error does not contain valid row identifiers.
     */
    public static function extract_rows_to_skip(array $errors): array {
        $skip = [];

        foreach ($errors as $error) {
            if (!is_array($error)) {
                throw new moodle_exception('error:errormustbeanarray', 'mod_facetoface', '', $error);
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

    /**
     * Return whether the user already holds an active signup relevant to capacity validation.
     *
     * @param stdClass $session Session record.
     * @param int $userid User ID.
     * @param string $status CSV status.
     * @return bool True when an active signup exists and affects the requested operation.
     */
    private function has_active_signup_for_validation(stdClass $session, int $userid, string $status): bool {
        $needsactivelookup = !$session->allowoverbook
            && ($status === 'cancelled' || $this->is_attendance_status($status));

        return $needsactivelookup && static::get_active_signup_id($session->id, $userid) !== null;
    }

    /**
     * Build a validation cache key.
     *
     * @param int $sessionid Session ID.
     * @param int $userid User ID.
     * @return string Session and user cache key.
     */
    private static function get_signup_cache_key(int $sessionid, int $userid): string {
        return "{$sessionid}:{$userid}";
    }

    /**
     * Return the active signup ID for a user and session.
     *
     * @param int $sessionid Session ID.
     * @param int $userid User ID.
     * @return int|null Active signup ID, or null when none exists.
     */
    private static function get_active_signup_id(int $sessionid, int $userid): ?int {
        global $DB;

        $sql = "
            SELECT  facetoface_signups.id

            FROM    {facetoface_signups} facetoface_signups
                    JOIN {facetoface_signups_status} facetoface_signups_status ON
                        facetoface_signups_status.signupid = facetoface_signups.id

            WHERE   facetoface_signups.sessionid = :sessionid AND
                    facetoface_signups.userid = :userid AND
                    facetoface_signups_status.superceded = 0 AND
                    facetoface_signups_status.statuscode >= :status_approved
        ";
        $signupid = $DB->get_field_sql($sql, [
            'sessionid' => $sessionid,
            'userid' => $userid,
            'status_approved' => MDL_F2F_STATUS_APPROVED,
        ]);

        return $signupid ? (int)$signupid : null;
    }

    /**
     * Return a signup ID regardless of its current status.
     *
     * @param int $sessionid Session ID.
     * @param int $userid User ID.
     * @return int Signup ID.
     */
    private static function get_signup_id(int $sessionid, int $userid): int {
        global $DB;

        return (int)$DB->get_field(
            'facetoface_signups',
            'id',
            ['sessionid' => $sessionid, 'userid' => $userid],
            MUST_EXIST
        );
    }

    /**
     * Cancel a booking for a validated row.
     *
     * @param stdClass $session Session record.
     * @param stdClass $facetoface Face-to-face activity record.
     * @param int $userid User ID.
     * @return void
     * @throws Exception When cancellation fails.
     */
    private function process_cancellation(stdClass $session, stdClass $facetoface, int $userid): void {
        if (!facetoface_user_cancel($session, $userid, true, $cancelerr)) {
            throw new Exception($cancelerr);
        }

        if (!facetoface_has_session_started($session, time()) && !$this->suppressemail) {
            facetoface_send_cancellation_notice($facetoface, $session, $userid);
        }
    }

    /**
     * Create a booking-style signup for a validated row.
     *
     * @param stdClass $session Session record.
     * @param stdClass $facetoface Face-to-face activity record.
     * @param stdClass $course Course record.
     * @param context_course $coursecontext Course context.
     * @param stdClass $user User record.
     * @param string $discountcode Discount code from the row.
     * @param int|null $notificationtype Notification type code.
     * @param int $statuscode Signup status code.
     * @return void
     */
    private function process_signup_row(
        stdClass $session,
        stdClass $facetoface,
        stdClass $course,
        context_course $coursecontext,
        stdClass $user,
        string $discountcode,
        ?int $notificationtype,
        int $statuscode
    ): void {
        $statuscode = static::normalise_booking_status_code($session, $statuscode);
        $this->create_import_signup(
            $session,
            $facetoface,
            $course,
            $coursecontext,
            $user,
            $discountcode,
            $notificationtype ?? -1,
            $statuscode
        );
        $this->trigger_bulk_booking_created_event($facetoface, $session, (int)$user->id);
    }

    /**
     * Create or reactivate the signup required by an import row.
     *
     * Historical booking imports on approval activities deliberately use an in-memory
     * activity clone with approval disabled. Calling facetoface_user_signup() remains
     * the source of truth for signup persistence, status history, calendar entries,
     * course completion, and the rule suppressing notices for started sessions.
     *
     * @param stdClass $session Session record.
     * @param stdClass $facetoface Face-to-face activity record.
     * @param stdClass $course Course record.
     * @param context_course $coursecontext Course context.
     * @param stdClass $user User record.
     * @param string $discountcode Discount code from the row.
     * @param int $notificationtype Notification type code.
     * @param int $statuscode Signup status code.
     * @return int Signup ID.
     */
    private function create_import_signup(
        stdClass $session,
        stdClass $facetoface,
        stdClass $course,
        context_course $coursecontext,
        stdClass $user,
        string $discountcode,
        int $notificationtype,
        int $statuscode
    ): int {
        facetoface_enrol_user($coursecontext, $course->id, $user->id);

        $signupfacetoface = $facetoface;
        if (static::should_bypass_approval_for_past_booking($session, $facetoface, $statuscode)) {
            $signupfacetoface = clone $facetoface;
            $signupfacetoface->approvalreqd = 0;
        }

        facetoface_user_signup(
            $session,
            $signupfacetoface,
            $course,
            $discountcode,
            $notificationtype,
            $statuscode,
            $user->id,
            !$this->suppressemail
        );

        return static::get_signup_id($session->id, $user->id);
    }

    /**
     * Return whether an import must bypass approval to create a historical booking.
     *
     * @param stdClass $session Session record.
     * @param stdClass $facetoface Face-to-face activity record.
     * @param int $statuscode Signup status code.
     * @return bool True when approval must be bypassed for this import only.
     */
    private static function should_bypass_approval_for_past_booking(
        stdClass $session,
        stdClass $facetoface,
        int $statuscode
    ): bool {
        return $statuscode === MDL_F2F_STATUS_BOOKED
            && helper::is_approval_required($facetoface)
            && facetoface_has_session_started($session, time());
    }

    /**
     * Apply attendance for a validated row.
     *
     * @param stdClass $session Session record.
     * @param stdClass $facetoface Face-to-face activity record.
     * @param stdClass $course Course record.
     * @param context_course $coursecontext Course context.
     * @param stdClass $user User record.
     * @param string $username Username from the row.
     * @param string $discountcode Discount code from the row.
     * @param int|null $notificationtype Notification type code.
     * @param int $row One-based data-row number.
     * @param int $statuscode Attendance status code.
     * @return void
     * @throws moodle_exception When attendance cannot be applied.
     */
    private function process_attendance_row(
        stdClass $session,
        stdClass $facetoface,
        stdClass $course,
        context_course $coursecontext,
        stdClass $user,
        string $username,
        string $discountcode,
        ?int $notificationtype,
        int $row,
        int $statuscode
    ): void {
        $signupid = $this->ensure_signup_for_attendance(
            $session,
            $facetoface,
            $course,
            $coursecontext,
            $user,
            $discountcode,
            $notificationtype
        );
        $data = (object)[
            's' => $session->id,
            "submissionid_{$signupid}" => $statuscode,
        ];

        if (facetoface_take_attendance($data)) {
            return;
        }

        throw new moodle_exception(
            'error:attendanceuploadfailed',
            'mod_facetoface',
            '',
            (object)[
                'user' => $username,
                'session' => $session->id,
                'line' => $row + 1,
            ]
        );
    }

    /**
     * Ensure an attendance row has an active signup to update.
     *
     * @param stdClass $session Session record.
     * @param stdClass $facetoface Face-to-face activity record.
     * @param stdClass $course Course record.
     * @param context_course $coursecontext Course context.
     * @param stdClass $user User record.
     * @param string $discountcode Discount code from the row.
     * @param int|null $notificationtype Notification type code.
     * @return int Active signup ID.
     */
    private function ensure_signup_for_attendance(
        stdClass $session,
        stdClass $facetoface,
        stdClass $course,
        context_course $coursecontext,
        stdClass $user,
        string $discountcode,
        ?int $notificationtype
    ): int {
        $signupid = static::get_active_signup_id($session->id, $user->id);
        if ($signupid !== null) {
            return $signupid;
        }

        $signupid = $this->create_import_signup(
            $session,
            $facetoface,
            $course,
            $coursecontext,
            $user,
            $discountcode,
            $notificationtype ?? -1,
            MDL_F2F_STATUS_BOOKED
        );
        $this->trigger_bulk_booking_created_event($facetoface, $session, (int)$user->id);

        return $signupid;
    }

    /**
     * Trigger the bulk booking event for an actual file upload.
     *
     * @param stdClass $facetoface Face-to-face activity record.
     * @param stdClass $session Session record.
     * @param int $userid User ID.
     * @return void
     */
    private function trigger_bulk_booking_created_event(
        stdClass $facetoface,
        stdClass $session,
        int $userid
    ): void {
        bulk_booking_created::trigger_from_bulk_upload_if_needed(
            $this->usefile,
            $facetoface,
            $session,
            $userid
        );
    }

    /**
     * Convert a CSV status to its status code.
     *
     * @param string $status CSV status.
     * @return int Signup status code.
     */
    private static function get_status_code(string $status): int {
        return array_search($status, facetoface_statuses(), true) ?: MDL_F2F_STATUS_BOOKED;
    }

    /**
     * Convert booked to waitlisted when a session has no known date.
     *
     * @param stdClass $session Session record.
     * @param int $statuscode Signup status code.
     * @return int Normalised booking status code.
     */
    private static function normalise_booking_status_code(stdClass $session, int $statuscode): int {
        if ($statuscode === MDL_F2F_STATUS_BOOKED && !$session->datetimeknown) {
            return MDL_F2F_STATUS_WAITLISTED;
        }

        return $statuscode;
    }

    /**
     * Return whether a status code represents a booking.
     *
     * @param int $statuscode Signup status code.
     * @return bool True for booked or waitlisted.
     */
    private static function is_booking_status_code(int $statuscode): bool {
        return in_array($statuscode, [MDL_F2F_STATUS_BOOKED, MDL_F2F_STATUS_WAITLISTED], true);
    }

    /**
     * Return whether a status code represents attendance.
     *
     * @param int $statuscode Signup status code.
     * @return bool True for an attendance status.
     */
    private static function is_attendance_status_code(int $statuscode): bool {
        return in_array($statuscode, [
            MDL_F2F_STATUS_NO_SHOW,
            MDL_F2F_STATUS_PARTIALLY_ATTENDED,
            MDL_F2F_STATUS_FULLY_ATTENDED,
        ], true);
    }

    /**
     * Build a validation error using the representation expected by the facade.
     *
     * @param string $identifier Language-string identifier.
     * @param string|stdClass|null $a Language-string data.
     * @return string|lang_string Error message.
     */
    private function get_error_message(string $identifier, string|stdClass|null $a = null): string|lang_string {
        if ($this->defererrormessages) {
            return new lang_string($identifier, 'mod_facetoface', $a);
        }

        return get_string($identifier, 'mod_facetoface', $a);
    }
}
