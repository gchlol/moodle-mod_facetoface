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

use completion_completion;
use completion_info;
use context_course;
use context_user;
use file_storage;
use moodle_exception;
use Generator;
use Exception;

/**
 * Bulk version of booking_manager
 * Located in site administration
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_manager_bulk_attendance {

    /** @var stored_file the file to process as a stored_file object */
    private $file;

    /** @var array collection of records (if loaded from memory), in an array. */
    private $records = [];

    /** @var bool Whether or not the bookings are loaded from a file. */
    private $usefile = true;

    /** @var bool When true, confirmation emails are not sent. */
    private $suppressemail = false;

    /** @var bool Will ignore case when matching users */
    private $caseinsensitive = false;

    /** @var string[] Statuses that record attendance rather than create a plain booking. */
    private const ATTENDANCE_STATUSES = ['no_show', 'partially_attended', 'fully_attended'];

    /** @var string[] Statuses that add a user to a session. */
    private const BOOKING_STATUSES = ['', 'booked', 'waitlisted'];

    /**
     * Constructor for the booking manager.
     *
     * @param array $records Records to process.
     */
    public function __construct($records = []) {
        $this->records = $records;
    }

    /**
     * Loads CSV data from a draft file area.
     * Returns file from file system. File must exist.
     *
     * @param int $fileitemid Item id
     * @throws moodle_exception
     * @return void
     */
    public function load_from_file(int $fileitemid): void {
        global $USER;

        $this->usefile = true;

        $fs = new file_storage();
        $files = $fs->get_area_files(
            context_user::instance($USER->id)->id,
            'user',
            'draft',
            $fileitemid,
            'itemid',
            false
        );

        if (count($files) != 1) {
            throw new moodle_exception('error:cannotloadfile', 'mod_facetoface');
        }

        $this->file = current($files);
    }

    /**
     * Load in the records to process from an array
     *
     * @param array $records Array of record objects or arrays.
     * @return self
     */
    public function load_from_array(array $records): self {
        $this->usefile = false;
        $this->records = $records;

        return $this;
    }

    /**
     * Get the headers for the records.
     *
     * @return array Indexed array of headers.
     */
    public static function get_headers(): array {
        return [
            'Username',
            'Session',
            'Status',
            'Discount Code',
            'Notification Type',
        ];
    }

    /**
     * Provides a record iterator for CSV rows, either from file.
     *
     * @return Generator Yields each CSV record as an object.
     * @throws moodle_exception If CSV header count does not match expectations.
     */
    private function get_iterator(): Generator {
        if (!$this->usefile) {
            foreach ($this->records as $record) {

                yield $record;
            }

            return;
        }

        $handle = $this->file->get_content_file_handle();
        $maxlinelength = 1000;
        $delimiter = ',';
        $rownumber = 1;
        $headers = self::get_headers();
        $numheaders = count($headers);

        // Read the CSV header and detect whether a "Discount Code" column exists (case-insensitive).
        $fileheaders = fgetcsv($handle, $maxlinelength, $delimiter);
        $hasdiscount = false;
        if ($fileheaders !== false) {
            $norm = array_map(
                function($h) {
                    return strtolower(trim($h));
                },
                $fileheaders
            );
            $hasdiscount = in_array('discount code', $norm, true);
        }

        // Where "Discount Code" is expected in our canonical header list.
        $discountpos = array_search('Discount Code', $headers, true);

        try {
            while (($data = fgetcsv($handle, $maxlinelength, $delimiter)) !== false) {
                $rownumber++;

                // If the uploaded CSV omitted "Discount Code", insert an empty string so counts still match.
                if ($hasdiscount === false && $discountpos !== false) {
                    array_splice($data, $discountpos, 0, '');
                }

                $numfields = count($data);

                if ($numfields !== $numheaders) {
                    throw new moodle_exception(
                        'error:bookingsuploadfileheaderfieldmismatch',
                        'mod_facetoface'
                    );
                }
                $record = array_combine($headers, $data);

                yield (object) $record;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Validate the records provided to ensure they can be processed without errors.
     *
     * As there are multiple dependent data points (users, sessions, capacity),
     * we check them in this order for each row:
     *   1) Session exists (by id)
     *   2) Face-to-Face activity & Course derived from session
     *   3) User exists
     *   4) Existing booking-style upload check
     *   5) Session timing checks
     *   6) Enrollment check
     *   7) Notification type check
     *   8) Status check
     *   9) Duplicate-row checks
     *  10) Capacity check for otherwise valid, unique rows
     *
     * @param int|null $timenow Current time to use for validation.
     * @return array An array of errors.
     */
    public function validate(?int $timenow = null): array {
        global $DB;

        $errors = [];
        $validationrows = [];
        $activesignupcache = [];
        $signupexistencecache = [];

        if ($timenow === null) {
            $timenow = time();
        }

        // Break into rows and validate the multiple interdependent fields together.
        foreach ($this->get_iterator() as $index => $entry) {
            $row = $index + 1;
            $errorcountbefore = count($errors);

            // Trim whitespace from the CSV fields (Course/Activity removed).
            $username      = trim($entry->Username);
            $sessionref    = trim($entry->Session);
            $status        = trim($entry->Status ?? '');
            $discount      = trim($entry->{'Discount Code'} ?? '');
            $notifytype    = trim($entry->{'Notification Type'} ?? '');

            // 1) Check session exists (CSV provides session id).
            $session = facetoface_get_session($sessionref);
            if (!$session) {
                $errors[] = [
                    $row,
                    get_string('error:sessiondoesnotexist', 'mod_facetoface', $sessionref)
                ];

                continue;
            }

            // 2) Derive Face-to-Face activity & Course from the session.
            $facetoface = $DB->get_record('facetoface', ['id' => $session->facetoface]);
            if (!$facetoface) {
                $errors[] = [
                    $row,
                    get_string('error:activitydoesnotexist', 'facetoface', $session->facetoface)
                ];

                continue;
            }

            $course = $DB->get_record('course', ['id' => $facetoface->course]);
            if (!$course) {
                $errors[] = [
                    $row,
                    get_string('error:coursemisconfigured', 'facetoface', $facetoface->course)
                ];

                continue;
            }

            // 3) Match user.
            $userids = $this->match_users($username, 'id');
            if (count($userids) > 1) {
                $errors[] = [
                    $row,
                    get_string('error:multipleusersmatched', 'mod_facetoface', $username)
                ];

                continue;
            }
            if (empty($userids)) {
                $errors[] = [
                    $row,
                    get_string('error:userdoesnotexist', 'mod_facetoface', $username)
                ];

                continue;
            }
            $userid = current($userids)->id;

            // 4) Detect a re-booking before validation can auto-enrol the user. A skipped row
            // must not change enrolment, signup history, attendance or grades.
            if (!$this->validate_existing_booking_upload(
                $row,
                $username,
                $session,
                $status,
                (int) $userid,
                $errors,
                $signupexistencecache,
                $activesignupcache
            )) {
                continue;
            }

            if (!$this->validate_session_status_rules($row, $sessionref, $status, $session, $timenow, $errors)) {
                continue;
            }

            // 6) Enrollment check. Auto-enrol staff who are not yet enrolled in the course.
            $coursecontext = context_course::instance($course->id);
            if (!is_enrolled($coursecontext, $userid)) {
                $isenrolled = facetoface_enrol_user($coursecontext, $course->id, $userid);
                if (!$isenrolled) {
                    $errors[] = [
                        $row,
                        get_string('error:enrolmentfailed', 'mod_facetoface', $username)
                    ];
                }
            }

            // 7) Check valid notification type.
            $mapped = $this->transform_notification_type($notifytype);
            if ($mapped === null) {
                $errors[] = [
                    $row,
                    get_string('error:invalidnotificationtypespecified', 'mod_facetoface', $notifytype)
                ];
            }

            // 8) Check valid, processable status. Internal workflow statuses are rejected instead
            // of being accepted and then reported as successful no-ops.
            $validstatuses = array_merge(self::BOOKING_STATUSES, ['cancelled'], self::ATTENDANCE_STATUSES);
            if (!in_array($status, $validstatuses, true)) {
                $errors[] = [
                    $row,
                    get_string('error:invalidstatusspecified', 'mod_facetoface', $status)
                ];
            }

            if (count($errors) === $errorcountbefore && $this->is_processable_status($status)) {
                $this->cache_validation_row(
                    $row,
                    $username,
                    $status,
                    $session,
                    (int) $userid,
                    $validationrows,
                    $activesignupcache
                );
            }
        }

        // Reject later duplicate user/session rows, then apply capacity only to rows
        // which have survived all other validation and will actually be processed.
        $this->validate_unique_rows_and_capacity($validationrows, $errors);

        return $errors;
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

            $key = $details['session']->id . ':' . $details['userid'];
            if (isset($seen[$key])) {
                $errors[] = [
                    $row,
                    get_string('error:duplicateuserinsessionupload', 'mod_facetoface', (object) [
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

            $available = (int) $session->capacity
                - facetoface_get_num_attendees($sessionid, MDL_F2F_STATUS_APPROVED);

            // A valid cancellation releases its existing seat for another valid row in this file,
            // regardless of whether the cancellation appears before or after that row.
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
                    get_string('error:sessionoverbooked', 'mod_facetoface', (object) [
                        'session' => $sessionid,
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
                get_string('error:useralreadyinsession', 'mod_facetoface', (object) [
                    'user' => $username,
                    'session' => $session->id,
                ]),
            ];

            return false;
        }

        // A booking row with no signup record cannot already occupy capacity.
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
     * @return bool True when the row can continue validation.
     */
    private function validate_session_status_rules(
        int $row,
        string $sessionref,
        string $status,
        \stdClass $session,
        int $timenow,
        array &$errors
    ): bool {
        if ($status === 'cancelled' && facetoface_has_session_started($session, $timenow)) {
            $errors[] = [
                $row,
                get_string('error:sessionalreadystarted', 'mod_facetoface', $sessionref)
            ];

            return false;
        }

        if ($status === 'waitlisted' && facetoface_has_session_started($session, $timenow)) {
            $errors[] = [
                $row,
                get_string('error:cannotwaitliststartedsession', 'mod_facetoface', $sessionref)
            ];

            return false;
        }

        if ($session->datetimeknown
            && $this->is_attendance_status($status)
            && !facetoface_has_session_started($session, $timenow)) {
            $errors[] = [
                $row,
                get_string('error:attendancesessionnotstarted', 'mod_facetoface', $sessionref)
            ];

            return false;
        }

        return true;
    }

    /**
     * Cache a processable row for the cross-row validation pass.
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
        return $sessionid . ':' . $userid;
    }

    /**
     * Match users for a given username.
     *
     * @param string $username Username to search.
     * @param string $fields Fields to return (e.g. 'id' or '*').
     * @return array Array of matching user records.
     */
    private function match_users(string $username, string $fields): array {
        global $DB;

        $equals = $DB->sql_equal('username', ':username', !$this->caseinsensitive);

        return $DB->get_records_select(
            'user',
            $equals,
            ['username' => $username],
            'id',
            $fields
        );
    }

    /**
     * Get the user's active signup ID for a session, if any.
     *
     * A signup is active when its current status is approved or higher (approved, waitlisted,
     * booked or an attendance status). Cancelled and declined signups are not active.
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

        return $signupid ? (int) $signupid : null;
    }

    /**
     * Transform notification type to internal representation.
     *
     * @param string $type Notification type string.
     * @return int|null   Mapped MDL_F2F_* constant or null if invalid.
     */
    private function transform_notification_type(string $type): ?int {
        $mapping = [
            'email' => MDL_F2F_TEXT,
            'ical' => MDL_F2F_ICAL,
            'icalendar' => MDL_F2F_ICAL,
            'both' => MDL_F2F_BOTH,
            '' => MDL_F2F_ICAL, // Defaults to iCalendar only if nothing is specified.
        ];

        return $mapping[strtolower($type)] ?? null;
    }

    /**
     * Process the bookings in the file.
     *
     * Attendance rows are authoritative historical updates and deliberately differ from
     * booking-style rows. When no active signup exists, they create or reactivate the signup as
     * booked before applying attendance; this includes cancelled, declined and requested signups.
     *
     * @return bool
     * @throws moodle_exception When an attendance row cannot be applied.
     * @throws Exception If cancellation fails.
     */
    public function process(array $errors): bool {
        // Build a set of rows to skip from the error list.
        $skip = booking_manager_bulk_attendance::extract_rows_to_skip($errors);

        foreach ($this->get_iterator() as $index => $entry) {
            $row = $index + 1;

            // Skip rows that had blocking validation errors.
            if (isset($skip[$row])) {
                continue;
            }

            $this->process_row($entry, $row);
        }

        return true;
    }

    /**
     * Process a single validated booking row.
     *
     * @param \stdClass $entry CSV row data.
     * @param int $row CSV row number.
     * @return void
     * @throws Exception When cancellation fails.
     * @throws moodle_exception When attendance cannot be applied.
     */
    private function process_row(\stdClass $entry, int $row): void {
        list($username, $sessionref, $status, $discount, $notifytype) = $this->extract_row_fields($entry);
        $user = current($this->match_users($username, '*'));
        $session = facetoface_get_session($sessionref);
        list($facetoface, $course) = $this->get_session_activity_context($session);

        if ($status === 'cancelled') {
            $this->process_cancellation($session, $facetoface, (int) $user->id);
            return;
        }

        $mappednotify = $this->transform_notification_type($notifytype);
        $statuscode = $this->get_status_code($status);
        if ($this->is_booking_status_code($statuscode)) {
            $this->process_signup_row($session, $facetoface, $course, $user, $discount, $mappednotify, $statuscode);
            return;
        }

        if ($this->is_attendance_status_code($statuscode)) {
            $this->process_attendance_row(
                $session,
                $facetoface,
                $course,
                $user,
                $username,
                $discount,
                $mappednotify,
                $row,
                $statuscode
            );
        }
    }

    /**
     * Extract the normalised field values used during processing.
     *
     * @param \stdClass $entry CSV row data.
     * @return string[] Normalised username, session reference, status, discount code, and notification type.
     */
    private function extract_row_fields(\stdClass $entry): array {
        return [
            trim($entry->Username),
            trim($entry->Session),
            trim($entry->Status),
            trim($entry->{'Discount Code'} ?? ''),
            trim($entry->{'Notification Type'} ?? ''),
        ];
    }

    /**
     * Load the face-to-face activity and course for a session.
     *
     * @param \stdClass $session Session record.
     * @return array{0:\stdClass, 1:\stdClass} Face-to-face activity and course records for the session.
     */
    private function get_session_activity_context(\stdClass $session): array {
        global $DB;

        $facetoface = $DB->get_record('facetoface', ['id' => $session->facetoface], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $facetoface->course], '*', MUST_EXIST);

        return [$facetoface, $course];
    }

    /**
     * Cancel a booking for a validated row.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $facetoface Face-to-face activity record.
     * @param int $userid User ID.
     * @return void
     * @throws Exception When cancellation fails.
     */
    private function process_cancellation(\stdClass $session, \stdClass $facetoface, int $userid): void {
        if (!facetoface_user_cancel($session, $userid, true, $cancelerr)) {
            throw new Exception($cancelerr);
        }

        $timenow = time();
        if (!facetoface_has_session_started($session, $timenow) && !$this->suppressemail) {
            facetoface_send_cancellation_notice($facetoface->id, $session, $userid);
        }
    }

    /**
     * Create or update a booking-style signup for a validated row.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $facetoface Face-to-face activity record.
     * @param \stdClass $course Course record.
     * @param \stdClass $user User record.
     * @param string $discount Discount code from the CSV row.
     * @param int|null $mappednotify Notification type code.
     * @param int $statuscode Signup status code.
     * @return void
     */
    private function process_signup_row(
        \stdClass $session,
        \stdClass $facetoface,
        \stdClass $course,
        \stdClass $user,
        string $discount,
        ?int $mappednotify,
        int $statuscode
    ): void {
        $statuscode = $this->normalise_booking_status_code($session, $statuscode);

        $this->create_import_signup(
            $session,
            $facetoface,
            $course,
            $user,
            $discount,
            $mappednotify ?? -1,
            $statuscode
        );

        $this->trigger_bulk_booking_created_event($facetoface, $session, (int) $user->id);
    }

    /**
     * Create the signup required by a validated import row.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $facetoface Face-to-face activity record.
     * @param \stdClass $course Course record.
     * @param \stdClass $user User record.
     * @param string $discount Discount code from the CSV row.
     * @param int $notificationtype Notification type code.
     * @param int $statuscode Signup status code.
     * @return int Signup ID.
     */
    private function create_import_signup(
        \stdClass $session,
        \stdClass $facetoface,
        \stdClass $course,
        \stdClass $user,
        string $discount,
        int $notificationtype,
        int $statuscode
    ): int {
        facetoface_enrol_user(context_course::instance($course->id), $course->id, $user->id);

        if ($this->should_bypass_approval_for_past_booking($session, $facetoface, $statuscode)) {
            return $this->upsert_historical_booking_signup(
                $session,
                $facetoface,
                $course,
                (int) $user->id,
                $discount,
                $notificationtype,
                $statuscode
            );
        }

        facetoface_user_signup(
            $session,
            $facetoface,
            $course,
            $discount,
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
     * @param \stdClass $facetoface Face-to-face activity record.
     * @param int $statuscode Signup status code.
     * @return bool True when the historical booking should bypass the approval workflow.
     */
    private function should_bypass_approval_for_past_booking(
        \stdClass $session,
        \stdClass $facetoface,
        int $statuscode
    ): bool {
        return $statuscode === MDL_F2F_STATUS_BOOKED
            && \mod_facetoface\helper::is_approval_required((object) $facetoface)
            && facetoface_has_session_started($session, time());
    }

    /**
     * Create or reactivate a historical booking without the legacy approval workflow.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $facetoface Face-to-face activity record.
     * @param \stdClass $course Course record.
     * @param int $userid User ID.
     * @param string $discount Discount code from the CSV row.
     * @param int $notificationtype Notification type code.
     * @param int $statuscode Signup status code.
     * @return int Signup ID.
     * @throws moodle_exception When the signup or status cannot be stored.
     */
    private function upsert_historical_booking_signup(
        \stdClass $session,
        \stdClass $facetoface,
        \stdClass $course,
        int $userid,
        string $discount,
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
        $usersignup->discountcode = trim(strtoupper($discount));
        if (empty($usersignup->discountcode)) {
            $usersignup->discountcode = null;
        }

        $usersignup = $this->persist_signup_record($usersignup);

        if (!facetoface_update_signup_status($usersignup->id, $statuscode, $userid)) {
            throw new moodle_exception('error:f2ffailedupdatestatus', 'facetoface');
        }

        if ($facetoface->usercalentry
            && $this->is_booking_status_code($statuscode)) {
            facetoface_add_session_to_calendar($session, $facetoface, 'user', $userid, 'booking');
        }

        $this->mark_booking_completion_in_progress_if_enabled($course, $statuscode, $userid, $timenow);

        return (int) $usersignup->id;
    }

    /**
     * Mark course completion as in progress when a historical booking becomes active.
     *
     * @param \stdClass $course Course record.
     * @param int $statuscode Signup status code.
     * @param int $userid User ID.
     * @param int $timenow Timestamp used for the completion record.
     * @return void
     */
    private function mark_booking_completion_in_progress_if_enabled(
        \stdClass $course,
        int $statuscode,
        int $userid,
        int $timenow
    ): void {
        if (!$this->is_booking_status_code($statuscode)) {
            return;
        }

        $completion = new completion_info($course);
        if (!$completion->is_enabled()) {
            return;
        }

        $ccdetails = [
            'course' => $course->id,
            'userid' => $userid,
        ];

        $completionrecord = new completion_completion($ccdetails);
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
            return (int) $signupid;
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
     * @param \stdClass $facetoface Face-to-face activity record.
     * @param \stdClass $course Course record.
     * @param \stdClass $user User record.
     * @param string $username Username from the CSV row.
     * @param string $discount Discount code from the CSV row.
     * @param int|null $mappednotify Notification type code.
     * @param int $row CSV row number.
     * @param int $statuscode Attendance status code.
     * @return void
     * @throws moodle_exception When attendance cannot be applied.
     */
    private function process_attendance_row(
        \stdClass $session,
        \stdClass $facetoface,
        \stdClass $course,
        \stdClass $user,
        string $username,
        string $discount,
        ?int $mappednotify,
        int $row,
        int $statuscode
    ): void {
        $signupid = $this->ensure_signup_for_attendance(
            $session,
            $facetoface,
            $course,
            $user,
            $discount,
            $mappednotify
        );
        if (!$this->apply_attendance_signup_status($signupid, $statuscode)) {
            throw new moodle_exception(
                'error:attendanceuploadfailed',
                'mod_facetoface',
                '',
                (object) [
                    'user' => $username,
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
     * @param \stdClass $facetoface Face-to-face activity record.
     * @param \stdClass $course Course record.
     * @param \stdClass $user User record.
     * @param string $discount Discount code from the CSV row.
     * @param int|null $mappednotify Notification type code.
     * @return int Active signup ID.
     */
    private function ensure_signup_for_attendance(
        \stdClass $session,
        \stdClass $facetoface,
        \stdClass $course,
        \stdClass $user,
        string $discount,
        ?int $mappednotify
    ): int {
        $signupid = $this->get_active_signup_id($session->id, $user->id);
        if ($signupid !== null) {
            return $signupid;
        }

        $signupid = $this->create_import_signup(
            $session,
            $facetoface,
            $course,
            $user,
            $discount,
            $mappednotify ?? -1,
            MDL_F2F_STATUS_BOOKED
        );

        $this->trigger_bulk_booking_created_event($facetoface, $session, (int) $user->id);

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

        return facetoface_update_signup_status($signupid, $statuscode, $USER->id, '', $grade)
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

        throw new moodle_exception('error:invalidstatusspecified', 'mod_facetoface', '', $statuscode);
    }

    /**
     * Trigger the branch-introduced bulk booking event when processing a file upload.
     *
     * @param \stdClass $facetoface Face-to-face activity record.
     * @param \stdClass $session Session record.
     * @param int $userid User ID.
     * @return void
     */
    private function trigger_bulk_booking_created_event(
        \stdClass $facetoface,
        \stdClass $session,
        int $userid
    ): void {
        \mod_facetoface\event\bulk_booking_created::trigger_from_bulk_upload_if_needed(
            (bool) $this->usefile,
            $facetoface,
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
            MDL_F2F_STATUS_FULLY_ATTENDED
        ], true);
    }

    /**
     * Convert the error array into a set of row numbers to skip.
     *
     * @param array<int,string> $errors Validation errors from validate()
     * @return array<int,bool> Keys are row numbers to skip
     * @throws moodle_exception Throws moodle_exception if an entry is not an array
     *                          or the first element
     */
    private static function extract_rows_to_skip(array $errors): array {
        $skip = [];

        foreach ($errors as $error) {
            if (!is_array($error)) {
                throw new moodle_exception('error:errormustbeanarray', 'mod_facetoface', '', $error);
            }

            // First element must be an integer row number, or a comma-separated list of row
            // numbers for aggregated errors such as overbookings.
            $rows = is_string($error[0]) ? explode(',', $error[0]) : [$error[0]];

            foreach ($rows as $row) {
                $row = trim((string) $row);

                if (!is_numeric($row)) {
                    throw new moodle_exception(
                        'error:invalidrownumber',
                        'mod_facetoface',
                        '',
                        (object)['value' => $error[0], 'type' => gettype($error[0])]
                    );
                }

                $skip[(int) $row] = true;
            }
        }

        return $skip;
    }

    /**
     * Stops confirmation emails from being sent
     *
     * @return void
     */
    public function suppress_email(): void {
        $this->suppressemail = true;
    }

    /**
     * Sets case insensitive match value
     *
     * @param bool $value True to match usernames case-insensitively.
     * @return void
     */
    public function set_case_insensitive(bool $value): void {
        $this->caseinsensitive = $value;
    }

    /**
     * Return all CSV rows as an array of stdClass.
     *
     * @return array
     */
    public function get_records(): array {
        return iterator_to_array($this->get_iterator());
    }
}
