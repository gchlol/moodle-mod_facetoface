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
            'Course Shortname',
            'Face-to-Face Activity Name',
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

        fgets($handle);

        try {
            while (($data = fgetcsv($handle, $maxlinelength, $delimiter)) !== false) {
                $rownumber++;
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
     *   1) Course exists (by shortname)
     *   2) Face-to-Face activity exists in that course
     *   3) User exists
     *   4) Session exists
     *   5) Session belongs to the found Face-to-Face module
     *   6) Cancellation-after-start check
     *   7) Booking-in-progress or over check
     *   8) Overbooking (capacity) logic
     *   9) Enrollment check
     *  10) Notification type check
     *  11) Status check
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

            // Trim whitespace from the new text fields.
            $courseshort   = trim($entry->{'Course Shortname'});
            $activityname  = trim($entry->{'Face-to-Face Activity Name'});
            $username      = trim($entry->Username);
            $sessionref    = trim($entry->Session);
            $status        = trim($entry->Status ?? '');
            $discount      = trim($entry->{'Discount Code'} ?? '');
            $notifytype    = trim($entry->{'Notification Type'} ?? '');

            // 1) Check course exists by shortname.
            $course = $DB->get_record('course', ['shortname' => $courseshort]);
            if (!$course) {
                $errors[] = [
                    $row,
                    get_string('error:coursemisconfigured', 'facetoface', $courseshort)
                ];

                continue;
            }

            // 2) Check Face-to-Face activity exists (by name + course).
            $facetoface = $DB->get_record(
                'facetoface',
                ['name' => $activityname, 'course' => $course->id]
            );

            if (!$facetoface) {
                $errors[] = [
                    $row,
                    get_string('error:activitydoesnotexist', 'facetoface', $activityname)
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

            // 4) Check session exists.
            $session = facetoface_get_session($sessionref);
            if (!$session) {
                $errors[] = [
                    $row,
                    get_string('error:sessiondoesnotexist', 'mod_facetoface', $sessionref)
                ];

                continue;
            }

            // 5) Ensure session belongs to this Face-to-Face module.
            if ($session->facetoface != $facetoface->id) {
                $errors[] = [
                    $row,
                    get_string(
                        'error:tryingtoupdatesessionfromanothermodule',
                        'mod_facetoface',
                        (object)[
                            'session' => $sessionref,
                            'f2fid'   => $facetoface->id
                        ]
                    )
                ];

                continue;
            }

            // 6) Don't allow user to cancel a session that has already occurred.
            if ($status === 'cancelled' && facetoface_has_session_started($session, $timenow)) {
                $errors[] = [
                    $row,
                    get_string('error:sessionalreadystarted', 'mod_facetoface', $sessionref)
                ];

                continue;
            }

            // 7) If booking or default ('', 'booked') into a session that’s in progress or over, error.
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

            // 8) Capacity logic (only if not cancelled).
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

            // 9) Enrollment check: use per-row course context.
            $coursecontext = context_course::instance($course->id);
            if (!is_enrolled($coursecontext, $userid)) {
                $errors[] = [
                    $row,
                    get_string('error:userisnotenrolledintocourse', 'mod_facetoface', $username)
                ];
            }

            // 10) Check valid notification type.
            $mapped = $this->transform_notification_type($notifytype);
            if ($mapped === null) {
                $errors[] = [
                    $row,
                    get_string('error:invalidnotificationtypespecified', 'mod_facetoface', $notifytype)
                ];
            }

            // 11) Check valid status.
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

        // 12) Finally report any over-capacity sessions.
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
            // Trim and extract each field.
            $courseshort   = trim($entry->{'Course Shortname'});
            $activityname  = trim($entry->{'Face-to-Face Activity Name'});
            $username      = trim($entry->Username);
            $sessionref    = trim($entry->Session);
            $status        = trim($entry->Status);
            $discount      = trim($entry->{'Discount Code'} ?? '');
            $notifytype    = trim($entry->{'Notification Type'} ?? '');

            // 1) Lookup course by shortname.
            $course = $DB->get_record('course', ['shortname' => $courseshort], '*', MUST_EXIST);

            // 2) Lookup Face-to-Face module by name + course.
            $facetoface = $DB->get_record(
                'facetoface',
                ['name' => $activityname, 'course' => $course->id],
                '*',
                MUST_EXIST
            );

            // 3) Match the user record.
            $user = current($this->match_users($username, '*'));

            // 4) Fetch the session record.
            $session = facetoface_get_session($sessionref);

            // 5) Map notification type to its internal code.
            $mappednotify = $this->transform_notification_type($notifytype);

            // 6) Handle cancellation.
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

            // 7) Map status string to status code.
            $statuscode = array_search($status, facetoface_statuses()) ?: MDL_F2F_STATUS_BOOKED;

            // 8) Handle signups (booked or waitlisted).
            if (in_array($statuscode, [MDL_F2F_STATUS_BOOKED, MDL_F2F_STATUS_WAITLISTED], true)) {
                if (
                    $statuscode === MDL_F2F_STATUS_BOOKED &&
                    !$session->datetimeknown
                ) {
                    // If booked but no datetime known, convert to waitlist.
                    $statuscode = MDL_F2F_STATUS_WAITLISTED;
                }

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

            // 9) Handle attendance (no-show / partial / full).
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
