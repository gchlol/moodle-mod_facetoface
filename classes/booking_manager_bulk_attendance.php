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

    /** @var string[] GCHLOL: Statuses that record attendance rather than create a plain booking. */
    private const ATTENDANCE_STATUSES = ['no_show', 'partially_attended', 'fully_attended'];

    /** @var string[] GCHLOL: Statuses that add a user to a session. */
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
     * @param int $timenow Current time to use for validation.
     * @return array An array of errors.
     */
    public function validate($timenow = null): array {
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

            // 4) GCHLOL: Detect a re-booking before validation can auto-enrol the user. A skipped row
            // must not change enrolment, signup history, attendance or grades.
            if (in_array($status, self::BOOKING_STATUSES, true)) {
                $signupkey = $session->id . ':' . $userid;
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
                    continue;
                }

                // A booking row with no signup record cannot already occupy capacity.
                $activesignupcache[$signupkey] = false;
            }

            // 5) Don't allow user to cancel a session that has already occurred.
            if ($status === 'cancelled' && facetoface_has_session_started($session, $timenow)) {
                $errors[] = [
                    $row,
                    get_string('error:sessionalreadystarted', 'mod_facetoface', $sessionref)
                ];

                continue;
            }

            // GCHLOL: Bookings ('', 'booked') into sessions that have already started or finished
            // are allowed, so past sessions can be uploaded. Waitlisting only makes sense before
            // the session starts, and attendance can only be recorded once it has started.
            if ($status === 'waitlisted' && facetoface_has_session_started($session, $timenow)) {
                $errors[] = [
                    $row,
                    get_string('error:cannotwaitliststartedsession', 'mod_facetoface', $sessionref)
                ];

                continue;
            }

            if (
                $session->datetimeknown &&
                in_array($status, self::ATTENDANCE_STATUSES, true) &&
                !facetoface_has_session_started($session, $timenow)
            ) {
                $errors[] = [
                    $row,
                    get_string('error:attendancesessionnotstarted', 'mod_facetoface', $sessionref)
                ];

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
                $signupkey = $session->id . ':' . $userid;
                if (!array_key_exists($signupkey, $activesignupcache)) {
                    // Booking rows have already proved that no signup exists. Only cancellation
                    // and attendance rows which affect enforced capacity need the status lookup.
                    $needsactivelookup = !$session->allowoverbook
                        && ($status === 'cancelled'
                            || in_array($status, self::ATTENDANCE_STATUSES, true));
                    $activesignupcache[$signupkey] = $needsactivelookup
                        && $this->get_active_signup_id($session->id, $userid) !== null;
                }

                $validationrows[$row] = [
                    'session' => $session,
                    'userid' => (int) $userid,
                    'username' => $username,
                    'status' => $status,
                    'hasactivesignup' => $activesignupcache[$signupkey],
                ];
            }
        }

        // GCHLOL: Reject later duplicate user/session rows, then apply capacity only to rows
        // which have survived all other validation and will actually be processed.
        $this->validate_unique_rows_and_capacity($validationrows, $errors);

        return $errors;
    }

    /**
     * GCHLOL: Validate duplicate CSV rows and projected session capacity.
     *
     * @param array $validationrows Resolved, processable CSV rows keyed by row number.
     * @param array $errors Validation errors, updated in place.
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
     * GCHLOL: Return whether a CSV status has a corresponding processing path.
     *
     * @param string $status CSV status.
     * @return bool
     */
    private function is_processable_status(string $status): bool {
        return $status === 'cancelled'
            || in_array($status, self::BOOKING_STATUSES, true)
            || in_array($status, self::ATTENDANCE_STATUSES, true);
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
     * GCHLOL: Get the user's active signup ID for a session, if any.
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
     * GCHLOL: Attendance rows are authoritative historical updates and deliberately differ from
     * booking-style rows. When no active signup exists, they create or reactivate the signup as
     * booked before applying attendance; this includes cancelled, declined and requested signups.
     *
     * @return bool
     * @throws moodle_exception When an attendance row cannot be applied.
     * @throws Exception If cancellation fails.
     */
    public function process($errors): bool {
        global $DB;

        // Build a set of rows to skip from the error list.
        $skip = booking_manager_bulk_attendance::extract_rows_to_skip($errors);

        foreach ($this->get_iterator() as $index => $entry) {
            $row = $index + 1;

            // Skip rows that had blocking validation errors.
            if (isset($skip[$row])) {
                continue;
            }

            // Trim and extract each field (Course/Activity removed; Session is ID).
            $username      = trim($entry->Username);
            $sessionref    = trim($entry->Session);
            $status        = trim($entry->Status);
            $discount      = trim($entry->{'Discount Code'} ?? '');
            $notifytype    = trim($entry->{'Notification Type'} ?? '');

            // 1) Match the user record.
            $user = current($this->match_users($username, '*'));

            // 2) Fetch the session by ID and derive Face-to-Face + Course.
            $session    = facetoface_get_session($sessionref);
            $facetoface = $DB->get_record('facetoface', ['id' => $session->facetoface], '*', MUST_EXIST);
            $course     = $DB->get_record('course', ['id' => $facetoface->course], '*', MUST_EXIST);

            // 3) Map notification type to its internal code.
            $mappednotify = $this->transform_notification_type($notifytype);

            // 4) Handle cancellation.
            if ($status === 'cancelled') {
                if (!facetoface_user_cancel($session, $user->id, true, $cancelerr)) {
                    throw new Exception($cancelerr);
                }

                $timenow = time();

                if (!facetoface_has_session_started($session, $timenow) && !$this->suppressemail) {
                    facetoface_send_cancellation_notice(
                        $facetoface->id,
                        $session,
                        $user->id
                    );
                }

                continue;
            }

            // 5) Map status string to status code.
            $statuscode = array_search($status, facetoface_statuses()) ?: MDL_F2F_STATUS_BOOKED;

            // 6) Handle signups (booked or waitlisted).
            if (in_array($statuscode, [MDL_F2F_STATUS_BOOKED, MDL_F2F_STATUS_WAITLISTED], true)) {
                if (
                    $statuscode === MDL_F2F_STATUS_BOOKED &&
                    !$session->datetimeknown
                ) {
                    // If booked but no datetime known, convert to waitlist.
                    $statuscode = MDL_F2F_STATUS_WAITLISTED;
                }

                // Edge case: Re-enrol the user if they are unenrolled after validation.
                // Otherwise, it's idempotent for enrolled users.
                $coursecontext = context_course::instance($course->id);
                facetoface_enrol_user($coursecontext, $course->id, $user->id);

                // GCHLOL: Authorise the helper to bypass approval for a historical Booked row.
                // The helper remains the sole place which checks both status and session timing.
                facetoface_user_signup(
                    $session,
                    $facetoface,
                    $course,
                    $discount ?? '',
                    $mappednotify ?? -1,
                    $statuscode,
                    $user->id,
                    !$this->suppressemail,
                    true
                );

                // GCHLOL: Log a successful site admin CSV bulk booking for this session user.
                \mod_facetoface\event\bulk_booking_created::trigger_from_bulk_upload_if_needed(
                    (bool) $this->usefile,
                    $facetoface,
                    $session,
                    (int) $user->id
                );
                // GCHLOL ends.

                continue;
            }

            // 7) Handle attendance (no-show / partial / full).
            if (in_array(
                $statuscode,
                [
                    MDL_F2F_STATUS_NO_SHOW,
                    MDL_F2F_STATUS_PARTIALLY_ATTENDED,
                    MDL_F2F_STATUS_FULLY_ATTENDED
                ],
                true
            )) {
                // GCHLOL: Users without an active signup (never booked, or previously cancelled) are
                // booked in first, so attendance can be uploaded for sessions that have already run.
                // Signup emails are suppressed by facetoface_user_signup() once a session has started.
                $signupid = $this->get_active_signup_id($session->id, $user->id);
                if ($signupid === null) {
                    $coursecontext = context_course::instance($course->id);
                    facetoface_enrol_user($coursecontext, $course->id, $user->id);

                    // GCHLOL: Keep the imported history as Booked -> attendance rather than
                    // creating an approval request for a session that has already happened.
                    facetoface_user_signup(
                        $session,
                        $facetoface,
                        $course,
                        $discount ?? '',
                        $mappednotify ?? -1,
                        MDL_F2F_STATUS_BOOKED,
                        $user->id,
                        !$this->suppressemail,
                        true
                    );

                    // GCHLOL: Log a successful site admin CSV bulk booking for this session user.
                    \mod_facetoface\event\bulk_booking_created::trigger_from_bulk_upload_if_needed(
                        (bool) $this->usefile,
                        $facetoface,
                        $session,
                        (int) $user->id
                    );

                    $signupid = (int) $DB->get_field(
                        'facetoface_signups',
                        'id',
                        ['sessionid' => $session->id, 'userid' => $user->id],
                        MUST_EXIST
                    );
                }

                $data = (object) [
                    's' => $session->id,
                    'submissionid_' . $signupid => $statuscode,
                ];

                if (!facetoface_take_attendance($data)) {
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

                continue;
            }

        }

        return true;
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

            // First element must be an integer row number, or a
            // GCHLOL: comma-separated list of row numbers for aggregated errors such as overbookings.
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
