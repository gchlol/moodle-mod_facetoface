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
     *   4) Cancellation-after-start check
     *   5) Booking-in-progress or over check
     *   6) Overbooking (capacity) logic
     *   7) Enrollment check
     *   8) Notification type check
     *   9) Status check
     *
     * @param int $timenow Current time to use for validation.
     * @return array An array of errors.
     */
    public function validate($timenow = null): array {
        global $DB;

        $errors = [];
        $sessioncapacitycache = [];

        if ($timenow === null) {
            $timenow = time();
        }

        // Break into rows and validate the multiple interdependent fields together.
        foreach ($this->get_iterator() as $index => $entry) {
            $row = $index + 1;

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

            // 4) Don't allow user to cancel a session that has already occurred.
            if ($status === 'cancelled' && facetoface_has_session_started($session, $timenow)) {
                $errors[] = [
                    $row,
                    get_string('error:sessionalreadystarted', 'mod_facetoface', $sessionref)
                ];

                continue;
            }

            // 5) If booking or default ('', 'booked') into a session that’s in progress or over, error.
            if (
                $session->datetimeknown &&
                in_array($status, ['', 'booked'], true) &&
                facetoface_has_session_started($session, $timenow)
            ) {
                $inprog = get_string('cannotsignupsessioninprogress', 'facetoface');
                $over = get_string('cannotsignupsessionover', 'facetoface');
                $reason = facetoface_is_session_in_progress($session, $timenow) ? $inprog : $over;
                $errors[] = [$row, $reason];

                continue;
            }

            // 6) Capacity logic (only if not cancelled).
            if ($session->allowoverbook == 0) {
                if (!isset($sessioncapacitycache[$session->id])) {
                    $remaining = $session->capacity
                        - facetoface_get_num_attendees($session->id, MDL_F2F_STATUS_APPROVED);
                    $sessioncapacitycache[$session->id] = [
                        'capacity' => $remaining,
                        'rows'     => []
                    ];
                }
                if ($status !== 'cancelled') {
                    $sessioncapacitycache[$session->id]['capacity']--;
                    $sessioncapacitycache[$session->id]['rows'][] = $row;
                }
            }

            // 7) Enrollment check. Auto-enrol staff who are not yet enrolled in the course.
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

            // 8) Check valid notification type.
            $mapped = $this->transform_notification_type($notifytype);
            if ($mapped === null) {
                $errors[] = [
                    $row,
                    get_string('error:invalidnotificationtypespecified', 'mod_facetoface', $notifytype)
                ];
            }

            // 9) Check valid status.
            $validstatuses = array_merge(
                facetoface_statuses(),
                ['', 'cancelled']
            );
            if (!in_array($status, $validstatuses, true)) {
                $errors[] = [
                    $row,
                    get_string('error:invalidstatusspecified', 'mod_facetoface', $status)
                ];
            }
        }

        // 10) Finally report any over-capacity sessions.
        $overcapacitysessions = array_filter(
            $sessioncapacitycache,
            fn($s) => $s['capacity'] < 0
        );
        if (!empty($overcapacitysessions)) {
            foreach ($overcapacitysessions as $sessionid => $details) {
                $errors[] = [
                    implode(', ', $details['rows']),
                    get_string(
                        'error:sessionoverbooked',
                        'mod_facetoface',
                        (object)[
                            'session' => $sessionid,
                            'amount'  => -$details['capacity']
                        ]
                    ),
                ];
            }
        }

        return $errors;
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
            '' => MDL_F2F_BOTH, // Defaults to sending both if nothing is specified.
        ];

        return $mapping[strtolower($type)] ?? null;
    }

    /**
     * Process the bookings in the file.
     *
     * @return bool
     * @throws moodle_exception If validation errors exist.
     * @throws Exception If cancellation fails.
     */
    public function process(): bool {
        global $DB;

        if (!empty($this->validate())) {
            throw new moodle_exception(
                'error:cannotprocessbookingsvalidationerrorsexist',
                'facetoface'
            );
        }

        foreach ($this->get_iterator() as $entry) {
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

                facetoface_user_signup(
                    $session,
                    $facetoface,
                    $course,
                    $discount,
                    $mappednotify,
                    $statuscode,
                    $user->id,
                    !$this->suppressemail
                );

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
                $attendees = facetoface_get_attendees($session->id);
                foreach ($attendees as $attendee) {
                    if ($attendee->username === $username) {

                        break;
                    }
                }

                $data = (object) [
                    's' => $session->id,
                    'submissionid_' . $attendee->submissionid => $statuscode,
                ];

                facetoface_take_attendance($data);

                continue;
            }

        }

        return true;
    }

    /**
     * Process only rows that passed validation.
     * Enrolment-related errors (Error #7) are treated as ignorable for skipping (they won't exclude a row).
     *
     * @param array $errors Validation errors from validate()
     * @return array [$processedCount, $skippedCount]
     */
    public function process_skipping(array $errors): array {
        global $DB;

        // Build a set of rows to skip from the error list, excluding enrolment-only errors.
        $skip = $this->extract_rows_to_skip($errors);

        $processed = 0;
        $skipped = 0;

        foreach ($this->get_iterator() as $index => $entry) {
            $row = $index + 1;

            // Skip rows that had blocking validation errors.
            if (isset($skip[$row])) {
                $skipped++;
                continue;
            }

            try {
                // Extract fields using the exact CSV header keys.
                $username   = trim($entry->Username ?? '');
                $sessionref = trim($entry->Session ?? '');
                $status     = trim($entry->Status ?? '');
                $discount   = trim($entry->{'Discount Code'} ?? '');
                $notifytype = trim($entry->{'Notification Type'} ?? '');

                // Look up user and session (should be valid if we weren't asked to skip this row).
                $user = current($this->match_users($username, '*'));
                if (!$user) { $skipped++; continue; }

                $session = facetoface_get_session($sessionref);
                if (!$session) { $skipped++; continue; }

                // Derive activity & course (mirror process()).
                $facetoface = $DB->get_record('facetoface', ['id' => $session->facetoface], '*', MUST_EXIST);
                $course     = $DB->get_record('course', ['id' => $facetoface->course], '*', MUST_EXIST);

                // Notification mapping (should be valid if not in skip).
                $mappednotify = $this->transform_notification_type($notifytype);
                if ($mappednotify === null) { $skipped++; continue; }

                // Cancellations first.
                if ($status === 'cancelled') {
                    if (!facetoface_user_cancel($session, $user->id, true, $cancelerr)) {
                        $skipped++;
                        continue;
                    }

                    $timenow = time();
                    if (!facetoface_has_session_started($session, $timenow) && !$this->suppressemail) {
                        // Match signature used in process().
                        facetoface_send_cancellation_notice($facetoface->id, $session, $user->id);
                    }

                    $processed++;
                    continue;
                }

                // Map status string to status code (same behaviour as process()).
                $statuscode = array_search($status, facetoface_statuses());
                if ($statuscode === false) {
                    $statuscode = MDL_F2F_STATUS_BOOKED;
                }

                // Handle signups.
                if (in_array($statuscode, [MDL_F2F_STATUS_BOOKED, MDL_F2F_STATUS_WAITLISTED], true)) {
                    if ($statuscode === MDL_F2F_STATUS_BOOKED && !$session->datetimeknown) {
                        $statuscode = MDL_F2F_STATUS_WAITLISTED;
                    }

                    // Be idempotent about enrolment just like process().
                    $coursecontext = \context_course::instance($course->id);
                    facetoface_enrol_user($coursecontext, $course->id, $user->id);

                    facetoface_user_signup(
                        $session,
                        $facetoface,
                        $course,
                        $discount,
                        $mappednotify,
                        $statuscode,
                        $user->id,
                        !$this->suppressemail
                    );

                    $processed++;
                    continue;
                }

                // Handle attendance updates.
                if (in_array($statuscode, [
                    MDL_F2F_STATUS_NO_SHOW,
                    MDL_F2F_STATUS_PARTIALLY_ATTENDED,
                    MDL_F2F_STATUS_FULLY_ATTENDED,
                ], true)) {
                    $attendees = facetoface_get_attendees($session->id);
                    $target = null;
                    foreach ($attendees as $a) {
                        if ($a->username === $username) { $target = $a; break; }
                    }
                    if (!$target) { $skipped++; continue; }

                    $data = (object)[ 's' => $session->id ];
                    $key = 'submissionid_' . $target->submissionid;
                    $data->$key = $statuscode;

                    facetoface_take_attendance($data);

                    $processed++;
                    continue;
                }

                // Unknown / unsupported status in this context – skip it.
                $skipped++;
            } catch (\Throwable $e) {
                // Any row-level failure counts as "skipped", not fatal to the batch.
                $skipped++;
                continue;
            }
        }

        return [$processed, $skipped];
    }

    /**
     * Convert the error array into a set of row numbers to skip,
     * while IGNORING enrolment-related errors (Error #7).
     */
    private function extract_rows_to_skip(array $errors): array {
        $skip = [];

        foreach ($errors as $error) {
            if (!is_array($error) || count($error) < 2) {
                continue;
            }

            // If any of the messages in this error blob are enrolment-related, ignore this error for skip purposes.
            $messages = array_slice($error, 1);
            $hasenrolmentissue = false;
            foreach ($messages as $msg) {
                if (is_string($msg) && preg_match('/enrol/i', $msg)) { // covers both "enrolment failed" & "not enrolled".
                    $hasenrolmentissue = true;
                    break;
                }
            }
            if ($hasenrolmentissue) {
                continue;
            }

            // First element must be an integer.
            $first = $error[0];

            if (!is_numeric($first)) {
                throw new moodle_exception('invalidrownumber', 'mod_facetoface', '', $first);
            }

            $rows = [];
            $rows[] = $first;

            foreach ($rows as $r) {
                $skip[$r] = true;
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
