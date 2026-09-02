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

use mod_facetoface\local\course_booking_upload_helper;
use context_course;
use context_user;
use file_storage;
use moodle_exception;

/**
 * Booking manager
 *
 * @package    mod_facetoface
 * @author     Kevin Pham <kevinpham@catalyst-au.net>
 * @copyright  Catalyst IT, 2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_manager {

    /** @var stored_file the file to process as a stored_file object */
    private $file;

    /** @var int The facetoface module ID. */
    private $f;

    /** @var int The course id. */
    private $course;

    /** @var context_course The course context. */
    private $coursecontext;

    /** @var int The course id. */
    private $facetoface;

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
     * @param int $f The facetoface module ID.
     * @param array $records The records to process.
     */
    public function __construct($f, $records = []) {
        global $DB;

        if (!$facetoface = $DB->get_record('facetoface', ['id' => $f])) {
            throw new moodle_exception('error:incorrectfacetofaceid', 'facetoface');
        }

        if (!$course = $DB->get_record('course', ['id' => $facetoface->course])) {
            throw new moodle_exception('error:coursemisconfigured', 'facetoface');
        }

        $this->f = $f;
        $this->facetoface = $facetoface;
        $this->course = $course;
        $this->coursecontext = context_course::instance($course->id);
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
            'username',
            'session',
            'status',
            'discountcode',
            'notificationtype',
        ];
    }

    /**
     * Get an iterator for the records.
     * @return Generator
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
        //fgets($handle); // Move pointer past first line (headers).
        // Read the CSV header and detect whether a "Discount Code" column exists (case-insensitive).
        $fileheaders = fgetcsv($handle, $maxlinelength, $delimiter);
        $hasdiscount = false;
        if ($fileheaders !== false) {
            $norm = array_map(function($h) { return strtolower(trim($h)); }, $fileheaders);
            $hasdiscount = in_array('discountcode', $norm, true);
        }

        // Where "Discount Code" is expected in our canonical header list.
        $discountpos = array_search('discountcode', $headers, true);

        try {
            while (($data = fgetcsv($handle, $maxlinelength, $delimiter)) !== false) {
                $rownumber++;

                // If the uploaded CSV omitted "Discount Code", insert an empty string so counts still match.
                if ($hasdiscount === false && $discountpos !== false) {
                    array_splice($data, $discountpos, 0, '');
                }

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

    // GCHLOL: Delegate the branch-specific CSV upload workflow to a local helper so this
    // third-party file keeps a small, low-conflict surface.
    /**
     * Build the helper that owns the branch-specific CSV upload behavior.
     *
     * @return course_booking_upload_helper
     */
    private function get_upload_helper(): course_booking_upload_helper {
        return new course_booking_upload_helper(
            $this->facetoface,
            $this->course,
            $this->coursecontext,
            $this->f,
            (bool) $this->usefile,
            (bool) $this->suppressemail,
            function(): \Generator {
                return $this->get_iterator();
            },
            function(string $username, string $fields): array {
                return $this->match_users_username($username, $fields);
            },
            function($type) {
                return $this->transform_notification_type($type);
            }
        );
    }

    /**
     * Validate the records provided to ensure they can be processed without errors.
     *
     * @param int $timenow The current time to use for validation.
     * @return array An array of errors.
     */
    public function validate($timenow = null): array {
        return $this->get_upload_helper()->validate($timenow);
    }
    // GCHLOL ends.

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
     * Match users for a given username, taking into account case sensitivity.
     * @param string $username
     * @param string $fields fields to return
     * @return array of users, with specified fields
     */
    private function match_users_username(string $username, string $fields): array {
        global $DB;
        $equals = $DB->sql_equal('username', ':username', !$this->caseinsensitive);
        return $DB->get_records_select('user', $equals, ['username' => $username], 'id', $fields);
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
            '' => MDL_F2F_ICAL, // Defaults to iCalendar only if nothing is specified.
        ];

        return $mapping[strtolower($type)] ?? null;
    }

    // GCHLOL: Delegate the branch-specific CSV processing workflow to the local helper to
    // avoid keeping large custom logic blocks inside the third-party manager.
    /**
     * Process the bookings in the file.
     *
     * @param array $errors Validation errors from validate().
     * @return bool
     * @throws moodle_exception
     */
    public function process(array $errors) {
        return $this->get_upload_helper()->process($errors);
    }
    // GCHLOL ends.

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
     * Convert the error array into a set of row numbers to skip.
     *
     * @param array<int, mixed> $errors Validation errors from validate()
     * @return array<int, bool> Keys are row numbers to skip (1-based)
     * @throws moodle_exception When the error format is invalid
     */
    private static function extract_rows_to_skip(array $errors): array {
        $skip = [];

        foreach ($errors as $error) {
            if (!is_array($error)) {
                throw new moodle_exception('error:errormustbeanarray', 'mod_facetoface', '', $error);
            }

            // First element must be an integer row number (1-based from validate()).
            $row = $error[0];
            if (!is_numeric($row)) {
                throw new moodle_exception(
                    'error:invalidrownumber',
                    'mod_facetoface',
                    '',
                    (object)['value' => $row, 'type' => gettype($row)]
                );
            }

            $skip[(int)$row] = true;
        }

        return $skip;
    }
}
