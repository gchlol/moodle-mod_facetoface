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
     * Constructor for the booking manager.
     * @param array $records The records to process.
     */
    public function __construct( $records = []) {
        $this->records = $records;
    }

    /**
     * Returns file from file system. File must exist.
     * @param int $fileitemid Item id of file stored in the current $USER's draft file area
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
     * @param array $records
     */
    public function load_from_array(array $records) {
        $this->usefile = false;
        $this->records = $records;

        return $this;
    }

    /**
     * Get the headers for the records.
     * @return array
     */
    public static function get_headers(): array {
        return [
            'course',
            'facetofacename',
            'email',
            'session',
            'status',
            'discountcode',
            'notificationtype',
        ];
    }

    /**
     * Get an iterator for the records.
     * @return \Generator
     */
    private function get_iterator(): \Generator {
        if (!$this->usefile) {
            foreach ($this->records as $record) {
                yield $record;
            }
            return;
        }

        $handle = $this->file->get_content_file_handle();
        $maxlinelength = 1000;
        $delimiter = ',';
        $rownumber = 1; // First row is headers.
        $headers = self::get_headers();
        $numheaders = count($headers);
        fgets($handle); // Move pointer past first line (headers).
        try {
            while (($data = fgetcsv($handle, $maxlinelength, $delimiter)) !== false) {
                $rownumber++;
                $numfields = count($data);
                if ($numfields !== $numheaders) {
                    throw new moodle_exception('error:bookingsuploadfileheaderfieldmismatch', 'mod_facetoface');
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
     * As there are multiple dependant data points (users, sessions, capacity)
     * that are checked. They are all in this method.
     *
     * @param int $timenow The current time to use for validation.
     * @return array An array of errors.
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

            // 1) Basic defaults.
            $entry->status           = $entry->status ?? '';
            $entry->notificationtype = $entry->notificationtype ?? '';
            $entry->discountcode     = $entry->discountcode ?? '';

            // 2) Course and F2F checks
            $coursef2f = $this->check_course_and_f2f($entry, $row);
            $rowerrors = array_merge($rowerrors, $coursef2f['errors']);
            $course    = $coursef2f['course'];
            $f2f       = $coursef2f['f2f'];

            // 3) Session checks
            $sessionset = $this->check_session($entry, $row, $f2f);
            $rowerrors  = array_merge($rowerrors, $sessionset['errors']);
            $session    = $sessionset['session'];

            // 4) If no session, no point continuing with session-based checks.
            // but we may want to do user check anyway. It's up to your needs.

            // 5) Check user
            $usercheck = $this->check_user($entry, $row);
            $rowerrors = array_merge($rowerrors, $usercheck['errors']);
            $userid    = $usercheck['userid'] ?? null;

            // 6) If session is valid, do checks for overbooking, etc.
            if ($session) {
                $rowerrors = array_merge($rowerrors,
                $this->check_overbooking($entry, $session, $row, $timenow, $sessioncapacitycache));
            }

            // 7) Possibly do notificationtype or status validation in smaller helpers too.
            // ...
            // e.g. $rowerrors = array_merge($rowerrors, $this->check_status($entry->status, $row));

            $errors = array_merge($errors, $rowerrors);
        } // end foreach

        // Overcapacity check after the loop.
        $errors = array_merge($errors, $this->check_final_overcapacity($sessioncapacitycache));

        return $errors;
    }

    /**
     * Match users for a given email, taking into account case sensitivity.
     * @param string $email
     * @param string $fields fields to return
     * @return array of users, with specified fields
     */
    private function match_users(string $email, string $fields): array {
        global $DB;
        $equals = $DB->sql_equal('email', ':email', !$this->caseinsensitive);
        return $DB->get_records_select('user', $equals, ['email' => $email], 'id', $fields);
    }

    /**
     * Transform notification type to internal representation.
     *
     * @param string $type Notification type.
     * @return int|null
     */
    private function transform_notification_type($type) {
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
     * @throws moodle_exception
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

    private function check_course_and_f2f(\stdClass $entry, int $row): array {
        global $DB;

        $errors = [];
        $course = $DB->get_record('course', ['shortname' => $entry->course]);
        if (!$course) {
            $errors[] = [$row, "Course shortname '{$entry->course}' not found"];
        }

        // Only look up f2f if $course is valid.
        $f2f = null;
        if ($course) {
            $f2f = $DB->get_record('facetoface', [
                'course' => $course->id,
                'name'   => $entry->facetofacename
            ]);
            if (!$f2f) {
                $errors[] = [$row, "F2F '{$entry->facetofacename}' not found in course '{$entry->course}'"];
            }
        }

        return [
            'errors' => $errors,
            'course' => $course,
            'f2f'    => $f2f
        ];
    }
    private function check_session(\stdClass $entry, int $row, ?object $f2f): array {
        $errors  = [];
        $session = null;

        // Look up session by ID.
        $session = facetoface_get_session($entry->session);
        if (!$session) {
            $errors[] = [$row, new lang_string('error:sessiondoesnotexist', 'mod_facetoface', $entry->session)];
        }

        // If both $f2f and $session exist, check they align.
        if ($f2f && $session && $session->facetoface != $f2f->id) {
            $errors[] = [$row, "Session {$entry->session} does not belong to F2F '{$entry->facetofacename}'"];
        }

        return [
            'errors'  => $errors,
            'session' => $session
        ];
    }
    private function check_user(\stdClass $entry, int $row): array {
        $errors = [];
        $userid = null;

        $userids = $this->match_users($entry->email, 'id');
        if (count($userids) > 1) {
            $errors[] = [$row, new lang_string('error:multipleusersmatched', 'mod_facetoface', $entry->email)];
        } else if (empty($userids)) {
            $errors[] = [$row, new lang_string('error:userdoesnotexist', 'mod_facetoface', $entry->email)];
        } else {
            $userid = current($userids)->id;
        }

        return [
            'errors' => $errors,
            'userid' => $userid
        ];
    }
    private function check_overbooking(
        \stdClass $entry,
        object $session,
        int $row,
        int $timenow,
        array &$sessioncapacitycache
    ): array {
        $errors = [];

        // Check if session is started but user tries to cancel, etc.
        if ($entry->status === 'cancelled' && facetoface_has_session_started($session, $timenow)) {
            $errors[] = [$row, new lang_string('error:sessionalreadystarted', 'mod_facetoface', $entry->session)];
        }

        // If datetime known and session started, can’t sign up for booked/waitlist.
        // ...
        // Then do capacity checks.
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

    private function process_row(\stdClass $entry): void {
        // 1) Grab user
        $userrecord = current($this->match_users($entry->email, '*'));
        // 2) Grab session
        $session    = facetoface_get_session($entry->session);

        if (!$userrecord) {
            throw new \Exception("User not found for email: {$entry->email}");
        }
        if (!$session) {
            throw new \Exception("Session not found for ID: {$entry->session}");
        }

        // 3) Based on status, pick the path
        if ($entry->status === 'cancelled') {
            $this->process_cancellation($session, $userrecord, $entry);
        } else {
            // Convert status to code.
            $statuscode = array_search($entry->status, facetoface_statuses()) ?: MDL_F2F_STATUS_BOOKED;

            if (in_array($statuscode, [MDL_F2F_STATUS_BOOKED, MDL_F2F_STATUS_WAITLISTED])) {
                $this->process_signup($session, $userrecord, $entry, $statuscode);
            } else if (in_array($statuscode,
             [MDL_F2F_STATUS_NO_SHOW, MDL_F2F_STATUS_PARTIALLY_ATTENDED, MDL_F2F_STATUS_FULLY_ATTENDED])) {
                $this->process_attendance($session, $userrecord, $entry, $statuscode);
            }
            // If status is something else, do nothing or handle error if needed.
        }
    }

    private function process_cancellation(object $session, object $user, \stdClass $entry): void {
        if (facetoface_user_cancel($session, $user->id, true, $cancelerr)) {
            $timenow = time();
            // Possibly email or logging if the session not started yet, etc.
            // ...
        } else {
            throw new \Exception($cancelerr);
        }
    }

    private function process_signup(object $session, object $user, \stdClass $entry, int $statuscode): void {
        // If the session is unknown date and status is BOOKED => switch to WAITLIST.
        if ($statuscode === MDL_F2F_STATUS_BOOKED && !$session->datetimeknown) {
            $statuscode = MDL_F2F_STATUS_WAITLISTED;
        }

        facetoface_user_signup(
            $session,
            $entry->discountcode,
            $this->transform_notification_type($entry->notificationtype),
            $statuscode,
            $user->id,
            !$this->suppressemail
        );
    }

    private function process_attendance(object $session, object $user, \stdClass $entry, int $statuscode): void {
        // Find the existing attendee record.
        $attendees = facetoface_get_attendees($session->id);
        $found = null;
        foreach ($attendees as $attendee) {
            if ($attendee->email === $entry->email) {
                $found = $attendee;
                break;
            }
        }
        if (!$found) {
            // Possibly throw an exception or skip silently if user isn't found in attendance.
            return;
        }

        // Mark attendance.
        $data = (object) [
            's' => $session->id,
            'submissionid_' . $found->submissionid => $statuscode,
        ];
        facetoface_take_attendance($data);
    }

    public function get_records(): array {
        // If we’re using a file, parse it into $this->records first.
        if ($this->usefile) {
            // Convert the iterator into an array.
            $this->records = iterator_to_array($this->get_iterator());
        }
        return $this->records;
    }
}

