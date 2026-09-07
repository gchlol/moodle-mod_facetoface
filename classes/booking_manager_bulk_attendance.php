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
use Exception;
use file_storage;
use Generator;
use lang_string;
use mod_facetoface\local\booking_upload_service;
use moodle_exception;

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
     *   4) Existing signup history for booking-style statuses
     *   5) Session timing rules for cancellations, waitlists, and attendance
     *   6) Enrollment check
     *   7) Notification type check
     *   8) Processable status check
     *
     * Once all rows have been checked, we validate them in this order:
     *   9) Duplicate user/session rows in the uploaded file
     *   10) Projected session capacity
     *
     * @param int|null $timenow Current time to use for validation.
     * @return list<array{0:int|string, 1:string|lang_string}> Validation errors keyed by CSV row.
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

        $uploadservice = $this->get_upload_service();

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

            // 4) Reject booking-style rows when any signup history already exists.
            if (!$uploadservice->validate_existing_booking_upload(
                $row,
                $username,
                $session,
                $status,
                (int)$userid,
                $errors,
                $signupexistencecache,
                $activesignupcache
            )) {
                continue;
            }

            // 5) Check timing rules for cancellation, waitlist, and attendance statuses.
            if (!$uploadservice->validate_session_status_rules(
                $row,
                $sessionref,
                $status,
                $session,
                (int)$timenow,
                $errors
            )) {
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

            // 8) Check valid processable status.
            if (!$uploadservice->is_processable_status($status)) {
                $errors[] = [
                    $row,
                    get_string('error:invalidstatusspecified', 'mod_facetoface', $status)
                ];
            }

            // Cache error-free rows for the cross-row duplicate and capacity checks.
            if (
                count($errors) === $errorcountbefore &&
                $uploadservice->is_processable_status($status)
            ) {
                $uploadservice->cache_validation_row(
                    $row,
                    $username,
                    $status,
                    $session,
                    (int)$userid,
                    $validationrows,
                    $activesignupcache
                );
            }
        }

        // 9) Report duplicate user/session rows in the uploaded file.
        // 10) Finally report projected over-capacity rows.
        $uploadservice->validate_unique_rows_and_capacity($validationrows, $errors);

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
            '' => MDL_F2F_ICAL, // Defaults to iCalendar only if nothing is specified.
        ];

        return $mapping[strtolower($type)] ?? null;
    }

    /**
     * Process the bookings in the file.
     *
     * Rows with blocking validation errors are skipped. Attendance rows are authoritative:
     * for historical sessions, processing creates or reactivates a booked signup when no active
     * signup exists, then records attendance, including for cancelled, declined, or requested history.
     *
     * @param list<array{0:int|string, 1:string|lang_string}> $errors Validation errors from validate().
     * @return bool True after all processable rows have been handled.
     * @throws moodle_exception When attendance cannot be applied.
     * @throws Exception When cancellation fails.
     */
    public function process($errors): bool {
        global $DB;

        // Build a set of rows to skip from the error list.
        $skip = booking_upload_service::extract_rows_to_skip($errors);
        $uploadservice = $this->get_upload_service();

        foreach ($this->get_iterator() as $index => $entry) {
            $row = $index + 1;

            // Skip rows that had blocking validation errors.
            if (isset($skip[$row])) {
                continue;
            }

            // Trim and extract each field (Course/Activity removed; Session is ID).
            $username      = trim($entry->Username);
            $sessionref    = trim($entry->Session);
            $status        = trim($entry->Status ?? '');
            $discount      = trim($entry->{'Discount Code'} ?? '');
            $notifytype    = trim($entry->{'Notification Type'} ?? '');

            // 1) Match the user record.
            $user = current($this->match_users($username, '*'));

            // 2) Fetch the session by ID and derive Face-to-Face + Course.
            $session = facetoface_get_session($sessionref);
            $facetoface = $DB->get_record('facetoface', ['id' => $session->facetoface], '*', MUST_EXIST);
            $course = $DB->get_record('course', ['id' => $facetoface->course], '*', MUST_EXIST);

            // Build the canonical row used by the shared upload workflow.
            $normalisedentry = (object)[
                'username' => $username,
                'status' => $status,
                'discountcode' => $discount,
            ];

            // 3) Map the notification type to its internal code.
            // 4-7) Delegate cancellation, status mapping, signup, or attendance processing.
            $uploadservice->process_row(
                $normalisedentry,
                $session,
                $facetoface,
                $course,
                context_course::instance($course->id),
                $user,
                $row,
                $this->transform_notification_type($notifytype)
            );
        }

        return true;
    }

    /**
     * Return the shared workflow configured with the manager's current options.
     *
     * @return booking_upload_service Shared upload workflow.
     */
    private function get_upload_service(): booking_upload_service {
        return new booking_upload_service($this->usefile, $this->suppressemail, false);
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
