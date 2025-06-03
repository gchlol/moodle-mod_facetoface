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

/**
 * @package   mod_facetoface
 * @copyright 2025, Gold Coast Health
 * @author    Jonas Sajonas
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_facetoface;

use context_user;
use file_storage;
use lang_string;
use moodle_exception;
use Generator;
use stdClass;
use Exception;

/**
 * Extended booking manager for Face-to-face activities.
 *
 * Class responsible for loading, validating, and processing bookings
 * from a CSV. It enforces checks like course/facetoface
 * existence, session matching, overbooking, and so on.
 */
class booking_manager_ext {

    /** @var stored_file the file to process as a stored_file object */
    private $file;

    /** @var array collection of records (if loaded from memory), in an array. */
    private $records;

    /** @var bool Whether or not the bookings are loaded from a file. */
    private $usefile = true;

    /** @var bool When true, confirmation emails are not sent. */
    private $suppressemail = false;

    /** @var bool Will ignore case when matching users */
    private $caseinsensitive = false;

    /**
     * Constructor.
     * @param array $records The records to process.
     */
    public function __construct( $records = []) {
        $this->records = $records;
    }

    /**
     * Loads a single file from user's draft area.
     *
     * @param int $fileitemid Draft file ID.
     * @throws moodle_exception If file not found or multiple files exist.
     */
    public function load_from_file(int $fileitemid) {
        global $USER;

        $this->usefile = true;

        $fs = new file_storage();
        $files = $fs->get_area_files(context_user::instance($USER->id)->id, 'user', 'draft', $fileitemid, 'itemid', false);

        if (count($files) != 1) {
            throw new moodle_exception('error:cannotloadfile', 'mod_facetoface');
        }

        $this->file = current($files);
    }

    /**
     * Load in the records to process from an array
     *
     * @param array $records
     * @return $this
     */
    public function load_from_array(array $records) {
        $this->usefile = false;
        $this->records = $records;

        return $this;
    }

    /**
     * Get the headers for the records.
     *
     * @return array
     */
    public static function get_headers(): array {
        return [
            'Course Shortname ',
            'Face-to-Face Activity Name',
            'Username',
            'Session',
            'Status',
            'Discount Code',
            'Notification Type',
        ];
    }

    /**
     * Get iterator for the records.
     *
     * @return Generator
     * @throws moodle_exception For invalid CSV structure.
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
        $headers = self::get_headers();
        $numheaders = count($headers);

        fgets($handle);

        try {
            while (($data = fgetcsv($handle, $maxlinelength, $delimiter)) !== false) {

                $numfields = count($data);
                if ($numfields !== $numheaders) {
                    throw new moodle_exception('error:bookingsuploadfileheaderfieldmismatch', 'mod_facetoface');
                }

                $record = array_combine($headers, $data);

                foreach ($record as $key => $value) {
                    $record[$key] = trim($value);
                }

                yield (object) $record;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Validates all bookings for issues (course/session/user, overcapacity, etc.).
     *
     * @param int|null $timenow Optional current time.
     * @return array List of errors; empty if none.
     */
    public function validate($timenow = null): array {
        $errors = [];
        $sessioncapacitycache = [];

        if ($timenow === null) {
            $timenow = time();
        }

        foreach ($this->get_iterator() as $index => $entry) {
            $row = $index + 1;
            $rowerrors = [];

            // Normalize empty fields.
            $entry->status = $entry->status ?? '';
            $entry->notificationtype = $entry->notificationtype ?? '';
            $entry->discountcode = $entry->discountcode ?? '';

            // Course & Face-to-face checks.
            $coursef2f = $this->check_course_and_f2f($entry, $row);
            $rowerrors = array_merge($rowerrors, $coursef2f['errors']);
            $course = $coursef2f['course'];
            $f2f = $coursef2f['f2f'];

            // Session checks.
            $sessionset = $this->check_session($entry, $row, $f2f);
            $rowerrors = array_merge($rowerrors, $sessionset['errors']);
            $session = $sessionset['session'];

            // User checks.
            $usercheck = $this->check_user($entry, $row);
            $rowerrors = array_merge($rowerrors, $usercheck['errors']);
            $userid = $usercheck['userid'];

            // Confirm user is enrolled in course.
            if (
                $userid &&
                $course
            ) {
                // Course context for enrollment checks.
                $coursecontext = \context_course::instance($course->id);

                if (!is_enrolled($coursecontext, $userid)) {
                    $rowerrors[] = [
                        $row,
                        new lang_string('error:userisnotenrolledintocourse', 'mod_facetoface', $entry->username)
                    ];
                }
            }

            // If the session is valid, do overbooking checks.
            if ($session) {
                $rowerrors = array_merge(
                    $rowerrors,
                    $this->check_overbooking($entry, $session, $row, $timenow, $sessioncapacitycache)
                );
            }

            // Merge any row-level errors into main errors array.
            $errors = array_merge($errors, $rowerrors);
        }

        // Check if any sessions ended in negative capacity.
        $errors = array_merge($errors, $this->check_final_overcapacity($sessioncapacitycache));

        return $errors;
    }

    /**
     * Match users for a given username.
     *
     * @param string $fields to return
     * @return array of users with specified fields
     */
    private function match_users(string $username, string $fields): array {
        global $DB;
        $equals = $DB->sql_equal('username', 'username', !$this->caseinsensitive);

        return $DB->get_records_select('user', $equals, ['username' => $username], 'id', $fields);
    }

    /**
     * Maps notification type string to Face-to-face constants.
     */
    private function transform_notification_type($type) {
        $mapping = [
            'email' => MDL_F2F_TEXT,
            'ical' => MDL_F2F_ICAL,
            'icalendar' => MDL_F2F_ICAL,
            'both' => MDL_F2F_BOTH,
            '' => MDL_F2F_BOTH,
        ];

        return $mapping[strtolower($type)] ?? null;
    }

    /**
     * Validates and then processes bookings (signup/cancel/attendance).
     *
     * @return bool
     * @throws moodle_exception If validation fails.
     */
    public function process() {
        if (!empty($this->validate())) {
            throw new moodle_exception('error:cannotprocessbookingsvalidationerrorsexist', 'mod_facetoface');
        }

        foreach ($this->get_iterator() as $entry) {
            $this->process_row($entry);
        }

        return true;
    }


    /**
     * Stops confirmation emails from being sent
     */
    public function suppress_email() {
        $this->suppressemail = true;
    }

    /**
     * Sets case insensitive match value
     * @param bool $value
     */
    public function set_case_insensitive(bool $value) {
        $this->caseinsensitive = $value;
    }

    /**
     * Check for valid course and Face-to-face activity.
     */
    private function check_course_and_f2f(\stdClass $entry, int $row): array {
        global $DB;
        $errors = [];

        $shortnamecondition = $DB->sql_equal(
            'shortname',
            ':shortname',
            !$this->caseinsensitive
        );

        $course = $DB->get_record_select(
            'course',
            $shortnamecondition,
            ['shortname' => $entry->course]
        );

        if (!$course) {
            $errors[] = [
                $row,
                get_string(
                    'error:coursenotfound',
                    'mod_facetoface',
                    (object)[
                        'course'          => $entry->course,
                        'caseinsensitive' => $this->caseinsensitive ? 'true' : 'false'
                    ]
                )
            ];
        }

        $f2f = null;
        if ($course) {
            $facenamecondition = $DB->sql_equal(
                'name',
                ':facetofacename',
                !$this->caseinsensitive
            );
            $where = $facenamecondition . ' AND course = :courseid';
            $params = [
                'facetofacename' => $entry->facetofacename,
                'courseid' => $course->id
            ];

            $f2f = $DB->get_record_select('facetoface', $where, $params);

            if (!$f2f) {
                $errors[] = [
                    $row,
                    get_string(
                        'error:f2fnotfoundincourse',
                        'mod_facetoface',
                        (object)[
                            'facetofacename' => $entry->facetofacename,
                            'course'         => $entry->course
                        ]
                    )
                ];
            }
        }

        return [
            'errors' => $errors,
            'course' => $course,
            'f2f'    => $f2f,
        ];
    }

    /**
     * Check if session exists and belongs to the correct Face-to-face.
     */
    private function check_session(\stdClass $entry, int $row, ?object $f2f): array {
        $errors  = [];
        $session = null;

        $session = facetoface_get_session($entry->session);
        if (!$session) {
            $errors[] = [$row, new lang_string('error:sessiondoesnotexist', 'mod_facetoface', $entry->session)];
        }

        if (
            $f2f &&
            $session &&
            $session->facetoface != $f2f->id
        ) {
            $errors[] = [
                $row,
                get_string(
                    'error:sessionwrongf2f',
                    'mod_facetoface',
                    (object)[
                        'sessionid'      => $entry->session,
                        'facetofacename' => $entry->facetofacename,
                    ]
                )
            ];

        }

        return [
            'errors'  => $errors,
            'session' => $session,
        ];
    }

    /**
     * Check that exactly one user matches the given username
     * and return the user’s ID if found.
     */
    private function check_user(\stdClass $entry, int $row): array {
        $errors = [];
        $userid = null;

        // Attempt to find user(s) by username.
        $userids = $this->match_users($entry->username, 'id');
        if (count($userids) > 1) {
            $errors[] = [$row, new lang_string('error:multipleusersmatched', 'mod_facetoface', $entry->username)];
        } else if (empty($userids)) {
            $errors[] = [$row, new lang_string('error:userdoesnotexist', 'mod_facetoface', $entry->username)];
        } else {
            // Exactly one user matched; store the user id.
            $userid = current($userids)->id;
        }

        return [
        'errors' => $errors,
        'userid' => $userid
        ];
    }

    /**
     * Checks for session overcapacity or invalid cancellations.
     */
    private function check_overbooking(
        \stdClass $entry,
        object $session,
        int $row,
        int $timenow,
        array &$sessioncapacitycache
    ): array {
        $errors = [];

        if ($entry->status === 'cancelled' && facetoface_has_session_started($session, $timenow)) {
            $errors[] = [$row, new lang_string('error:sessionalreadystarted', 'mod_facetoface', $entry->session)];
        }

        if (!isset($sessioncapacitycache[$session->id]) && !$session->allowoverbook) {
            $sessioncapacitycache[$session->id]['capacity'] =
                $session->capacity - facetoface_get_num_attendees($session->id, MDL_F2F_STATUS_APPROVED);
        }
        if (!$session->allowoverbook && $entry->status !== 'cancelled') {
            $sessioncapacitycache[$session->id]['capacity']--;
            $sessioncapacitycache[$session->id]['rows'][] = $row;
        }

        return $errors;
    }

    /**
     * Flags any sessions that ended with negative capacity.
     */
    private function check_final_overcapacity(array $sessioncapacitycache): array {
        $errors = [];
        $overcapacitysessions = array_filter($sessioncapacitycache, function ($s) {
            return ($s['capacity'] ?? 0) < 0;
        });

        if (!empty($overcapacitysessions)) {
            foreach ($overcapacitysessions as $sessionid => $details) {
                $errors[] = [
                    implode(', ', $details['rows']),
                    new lang_string('error:sessionoverbooked', 'mod_facetoface', (object) [
                        'session' => $sessionid,
                        'amount'  => -$details['capacity']
                    ])
                ];
            }
        }

        return $errors;
    }

    /**
     * Process a single booking row (cancellation, signup, attendance).
     */
    private function process_row(\stdClass $entry): void {
        global $DB;

        // Re-fetch user/session for safety.
        $userrecord = current($this->match_users($entry->username, '*'));
        $session    = facetoface_get_session($entry->session);

        if (!$userrecord) {
            throw new \moodle_exception(
                'error:usernotfoundbyusername',
                'mod_facetoface',
                '',
                (object)['username' => $entry->username]
            );
        }
        if (!$session) {
            throw new \moodle_exception(
                'error:sessionnotfoundbyid',
                'mod_facetoface',
                '',
                (object)['sessionid' => $entry->session]
            );
        }

        // Ensure course and F2F exist.
        $shortnamecondition = $DB->sql_equal('shortname', ':shortname',
            !$this->caseinsensitive
        );

        $course = $DB->get_record_select(
            'course',
            $shortnamecondition,
            ['shortname' => $entry->course],
            '*',
            MUST_EXIST
        );

        $f2f = $DB->get_record(
            'facetoface', ['course' => $course->id, 'name' => $entry->facetofacename], '*', MUST_EXIST);

        if ($entry->status === 'cancelled') {
            $this->process_cancellation($session, $userrecord, $entry);
        } else {
            $statuscode = array_search($entry->status, facetoface_statuses()) ?: MDL_F2F_STATUS_BOOKED;

            if (in_array($statuscode, [MDL_F2F_STATUS_BOOKED, MDL_F2F_STATUS_WAITLISTED])) {
                $this->process_signup($session, $f2f, $course, $userrecord, $entry, $statuscode);
            } else if (in_array($statuscode,
             [MDL_F2F_STATUS_NO_SHOW, MDL_F2F_STATUS_PARTIALLY_ATTENDED, MDL_F2F_STATUS_FULLY_ATTENDED])) {
                    $this->process_attendance($session, $userrecord, $entry, $statuscode);
            }
        }
    }

    /**
     * Cancels a user's booking. Throws exception if cancellation fails.
     */
    private function process_cancellation(object $session, object $user, stdClass $entry): void {
        if (!facetoface_user_cancel($session, $user->id, true, $cancelerr)) {
            throw new Exception($cancelerr);
        }
    }

    /**
     * Signs up or waitlists a user.
     */
    private function process_signup(
        object $session,
        object $f2f,
        object $course,
        object $user,
        stdClass $entry,
        int $statuscode
    ): void {
        // If session date is unknown, booked => waitlisted.
        if ($statuscode === MDL_F2F_STATUS_BOOKED && !$session->datetimeknown) {
            $statuscode = MDL_F2F_STATUS_WAITLISTED;
        }

        facetoface_user_signup(
            $session,
            $f2f,
            $course,
            $entry->discountcode,
            $this->transform_notification_type($entry->notificationtype),
            $statuscode,
            $user->id,
            !$this->suppressemail
        );
    }

    /**
     * Marks a user's attendance for a given session.
     */
    private function process_attendance(object $session, object $user, stdClass $entry, int $statuscode): void {
        $attendees = facetoface_get_attendees($session->id);
        $found = null;

        // Find the attendee record by username.
        foreach ($attendees as $attendee) {
            if ($attendee->username === $entry->username) {
                $found = $attendee;
                break;
            }
        }

        // If user never signed up, skip.
        if (!$found) {
            return;
        }

        $data = (object) [
            's' => $session->id,
            'submissionid_' . $found->submissionid => $statuscode,
        ];
        facetoface_take_attendance($data);
    }

    /**
     * Returns all records.
     */
    public function get_records(): array {
        if ($this->usefile) {
            $this->records = iterator_to_array($this->get_iterator());
        }

        return $this->records;
    }
}
