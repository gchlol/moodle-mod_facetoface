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

use moodle_exception;
use Generator;
use DateTime;
use context_user;
use moodle_url;
use stored_file;
use stdClass;

/**
 * Manages bulk session creation for Face-to-Face module sitewide.
 * Handles CSV parsing, validation, and session creation.
 * Supports file uploads.
 *
 * @package   mod_facetoface
 * @copyright 2025, Gold Coast Health
 * @author      Jonas Sajonas
 * @license      http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class site_bulk_manager {
    /** @var array Parsed CSV records */
    private array $records = [];

    /** @var array Validation errors */
    private array $errors = [];

    /** @var bool Indicates whether a file is used */
    private bool $usefile = false;

    /** @var stored_file|null Reference to uploaded file */
    private ?stored_file $file = null;

     /** @var bool Will ignore case when matching users */
     private $caseinsensitive = false;

    /**
     * Constructor.
     */
    public function __construct() {
    }

    /**
     * Loads CSV data from a draft file area.
     * Throws an exception if the file cannot be loaded or doesn't exist.
     *
     * @param int $fileid The draft file ID.
     * @return bool True on success.
     * @throws moodle_exception If the file cannot be loaded.
     */
    public function load_from_file(int $fileid):bool {
        global $USER;

        $this->usefile = true;

        $fs = get_file_storage();
        $usercontext = context_user::instance($USER->id);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $fileid, 'id', false);

        if (count($files) != 1) {
            throw new moodle_exception('error:cannotloadfile', 'mod_facetoface');
        }
        $this->file = current($files);

        return true;
    }


    /**
     * Returns the column headers expected for CSV input.
     *
     * @return array An indexed array of column headers.
     */
    public static function get_headers():array {
        return [
            'Course Shortname',
            'Face-to-Face Activity Name',
            'Session Date/Time Known',
            'Start Date',
            'Start Time',
            'Finish Date',
            'Finish Time',
            'Allow Cancellations',
            'Capacity',
            'Allow Overbookings',
            'Duration',
            'Normal Cost',
            'Discount Cost',
            'Details',
        ];
    }

    /**
     * Provides a record iterator for CSV rows, either from file.
     *
     * @return Generator Yields each CSV record as an associative array.
     */
    private function get_iterator(): Generator {
        if (!$this->usefile) {
            foreach ($this->records as $record) {
                yield $record;
            }

            return;
        }

        // Open the file handle and read line by line.
        $handle = $this->file->get_content_file_handle();
        $maxlinelength = 1000;
        $delimiter = ',';

        $headerline = fgetcsv($handle, $maxlinelength, $delimiter);

        if (empty($headerline)) {
            fclose($handle);
            throw new moodle_exception(
                'error:bookingsuploadfileheaderfieldmismatch',
                'mod_facetoface',
                get_string('error:noheaderrow', 'facetoface')
            );
        }

        $requiredheaders = self::get_headers();
        foreach ($requiredheaders as $required) {
            if (in_array($required, $headerline, true)) {

                continue;
            }

            // Handle error case.
            fclose($handle);
            throw new moodle_exception(
                'error:missingrequiredcolumn',
                'mod_facetoface',
                new moodle_url('/mod/facetoface/sitebulkupload.php'),
                $required
            );
        }

        try {
            while (($data = fgetcsv($handle, $maxlinelength, $delimiter)) !== false) {
                if (count($data) !== count($headerline)) {
                    throw new moodle_exception(
                        'error:bookingsuploadfileheaderfieldmismatch',
                        'facetoface'
                    );
                }
                yield array_combine($headerline, $data);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Validates the loaded CSV records for required fields, types, etc.
     * Use DB to match records.
     *
     * @return array A list of validation errors.
     */
    public function validate(): array {
        global $DB;

        // If using a file, parse its contents into $this->records before validation.
        if ($this->usefile) {
            $this->records = iterator_to_array($this->get_iterator());
        }

        foreach ($this->records as $index => $record) {
            foreach ($record as $key => $value) {
                $record[$key] = trim($value);
            }

            $shortname = $record['Course Shortname'] ?? '';
            $f2fname   = $record['Face-to-Face Activity Name'] ?? '';

            if (empty($shortname)) {
                $this->errors[] = [
                    $index,
                    get_string('error:missingcourseshortname', 'facetoface')
                ];

                continue;
            }

            if (empty($f2fname)) {
                $this->errors[] = [
                    $index,
                    get_string('error:missingf2fname', 'facetoface')
                ];

                continue;
            }

            $shortnamecondition = $DB->sql_equal('shortname', ':shortname', !$this->caseinsensitive);
            $course = $DB->get_record_select('course', $shortnamecondition, ['shortname' => $shortname]);

            if (!$course) {
                $this->errors[] = [$index, get_string('error:course_not_found', 'facetoface', $shortname)];

                continue;
            }

            // Ensures $course->id might be undefined if $course is null, so fallback to 0 in that scenario.
            $courseid = $course ? $course->id : 0;

            $f2frecord = $DB->get_record('facetoface', ['course' => $courseid, 'name' => $f2fname]);

            if (!$f2frecord) {
                $this->errors[] = [$index, get_string(
                        'error:f2f_not_found',
                        'facetoface',
                        (object)[
                            'shortname' => $shortname,
                            'f2fname'   => $f2fname
                        ]
                    )
                ];

                continue;
            }

            // Start Date + Time.
            if (
                empty($record['Start Date']) ||
                empty($record['Start Time'])
            ) {
                $this->errors[] = [$index, get_string('error:missingstarttime', 'facetoface')];

                continue;
            }

            $startdt = DateTime::createFromFormat('d/m/Y H:i', $record['Start Date'] . ' ' . $record['Start Time']);
            if (!$startdt) {
                $params = (object)[
                    'date' => $record['Start Date'],
                    'time' => $record['Start Time'],
                ];

                $this->errors[] = [
                    $index,
                    get_string('error:invalidstarttime', 'facetoface', $params),
                ];

                continue;
            }

            // If valid, store the combined date/time back into $record.
            $record['Start Date and Time'] = $startdt->format('Y-m-d H:i');

            // Finish Date + Time.
            if (
                empty($record['Finish Date']) ||
                empty($record['Finish Time'])
            ) {
                $this->errors[] = [$index, get_string('error:missingfinishtime', 'facetoface')];

                continue;
            }

            $finishdt = DateTime::createFromFormat('d/m/Y H:i', $record['Finish Date'].' '.$record['Finish Time']);
            if (!$finishdt) {
                $params = (object)[
                    'date' => $record['Finish Date'],
                    'time' => $record['Finish Time'],
                ];

                $this->errors[] = [
                    $index,
                    get_string('error:invalidfinishtime', 'facetoface', $params),
                ];

                continue;
            }

            $record['Finish Date and Time'] = $finishdt->format('Y-m-d H:i');

            // Ensure Start < Finish.
            $starttime = strtotime($record['Start Date and Time']);
            $finishtime = strtotime($record['Finish Date and Time']);

            if (
                $starttime &&
                $finishtime &&
                $starttime >= $finishtime
            ) {
                $this->errors[] = [$index, get_string('error:starttimeafterfinish', 'facetoface')];

                continue;
            }

            // Capacity (required).
            if (
                !isset($record['Capacity']) ||
                !is_numeric($record['Capacity']) ||
                (int)$record['Capacity'] <= 0
            ) {
                $this->errors[] = [$index, get_string('error:invalidcapacity', 'facetoface')];

                continue;
            }

            // Duration (required).
            if (
                !isset($record['Duration']) ||
                !is_numeric($record['Duration']) ||
                (int)$record['Duration'] <= 0
            ) {
                $this->errors[] = [$index, get_string('error:invalidduration', 'facetoface')];

                continue;
            }

            // Normal Cost (optional).
            if (
                !empty($record['Normal Cost']) &&
                !is_numeric($record['Normal Cost'])
            ) {
                $this->errors[] = [$index, get_string('error:invalidnormalcost', 'facetoface')];

                continue;
            }

            // Discount Cost (optional).
            if (
                !empty($record['Discount Cost']) &&
                !is_numeric($record['Discount Cost'])
            ) {
                $this->errors[] = [$index, get_string('error:invaliddiscountcost', 'facetoface')];

                continue;
            }

            // Allow Cancellations (required, "yes" or "no").
            $allowcancel = strtolower($record['Allow Cancellations'] ?? '');
            if (!in_array($allowcancel, ['yes', 'no'], true)) {
                $this->errors[] = [$index, get_string('error:invalidallowcancel', 'facetoface')];

                continue;
            }

            // Allow Overbookings (required, "yes" or "no").
            $allowover = strtolower($record['Allow Overbookings'] ?? '');
            if (!in_array($allowover, ['yes', 'no'], true)) {
                $this->errors[] = [$index, get_string('error:invalidallowoverbook', 'facetoface')];

                continue;
            }
        }
        return $this->errors;
    }

    /**
     * Process valid records to create sessions.
     * Assumes validate() has already been called to check correctness.
     *
     * @return bool true on success, false if any errors occurred
     */
    public function process():bool {
        global $DB;

        $allcustomfields = facetoface_get_session_customfields();
        $customfieldsbyshortname = [];

        foreach ($allcustomfields as $field) {
            $customfieldsbyshortname[$field->shortname] = $field;
        }

        foreach ($this->records as $record) {
            $session = new stdClass();

            $shortname = trim($record['Course Shortname']);
            $shortnamecondition = $DB->sql_equal('shortname', ':shortname', !$this->caseinsensitive);

            $course = $DB->get_record_select(
                'course',
                $shortnamecondition,
                ['shortname' => $shortname]
            );

            if (!$course) {
                $this->errors[] = get_string('error:course_not_found', 'facetoface', $shortname);

                continue;
            }

            // Look up the specific Face-to-face activity record.
            $courseid = $course->id;
            $f2fname = trim($record['Face-to-Face Activity Name']);
            $namecondition = $DB->sql_equal('name', ':f2fname', !$this->caseinsensitive);
            $where = $namecondition . ' AND course = :courseid';
            $params = [
                'f2fname' => $f2fname,
                'courseid' => $courseid
            ];

            $f2frecord = $DB->get_record_select('facetoface', $where, $params);

            if (!$f2frecord) {
                $this->errors[] = get_string('error:f2f_not_found', 'facetoface', (object)[
                    'shortname' => $shortname,
                    'f2fname'   => $f2fname
                ]);

                continue;
            }

            $session->facetoface = $f2frecord->id;

            $session->datetimeknown = 1;
            if (
                !empty($record['Session Date/Time Known']) &&
                $record['Session Date/Time Known'] === 'no'
            ) {
                $session->datetimeknown = 0;
            }

            $session->starttime = null;
            if (
                !empty($record['Start Date']) &&
                !empty($record['Start Time'])
            ) {
                $session->starttime = strtotime(str_replace('/', '-', $record['Start Date'].' '.$record['Start Time']));
            }

            $session->finishtime = null;
            if (
                !empty($record['Finish Date']) &&
                !empty($record['Finish Time'])
            ) {
                $session->finishtime = strtotime(str_replace('/', '-', $record['Finish Date'].' '.$record['Finish Time']));
            }

            if (
                $session->datetimeknown &&
                (empty($session->starttime) ||
                empty($session->finishtime))
            ) {
                $this->errors[] = get_string('error:invaliddatetimedata', 'facetoface');
                continue;
            }

            $session->allowcancel = 1;
            if (
                !empty($record['Allow Cancellations']) &&
                $record['Allow Cancellations'] === 'no'
            ) {
                $session->allowcancel = 0;
            }

            $session->capacity = 10;
            if (
                !empty($record['Capacity']) &&
                is_numeric($record['Capacity'])
            ) {
                $session->capacity = (int)$record['Capacity'];
            }

            $session->overbook = 1;
            if (
                !empty($record['Allow Overbookings']) &&
                $record['Allow Overbookings'] === 'no'
            ) {
                $session->overbook = 0;
            }

            $session->duration = 0;
            if (
                !empty($record['Duration']) &&
                is_numeric($record['Duration'])
            ) {
                $session->duration = (int)$record['Duration'];
            }

            $session->normalcost = null;
            if (
                !empty($record['Normal Cost']) &&
                is_numeric($record['Normal Cost'])
            ) {
                $session->normalcost = $record['Normal Cost'];
            }

            $session->discountcost = null;
            if (
                !empty($record['Discount Cost']) &&
                is_numeric($record['Discount Cost'])
            ) {
                $session->discountcost = $record['Discount Cost'];
            }

            $session->details = '';
            if (
                !empty($record['Details']) &&
                is_string($record['Details'])
            ) {
                $session->details = $record['Details'];
            }

            $session->timecreated = time();
            $session->timemodified = time();

            $sessionid = $DB->insert_record('facetoface_sessions', $session);
            if (!$sessionid) {
                $this->errors[] = get_string('error:failedtocreatesession', 'facetoface');

                continue;
            }

            // Insert session dates.
            $sessionsdate = new stdClass();
            $sessionsdate->sessionid = $sessionid;
            $sessionsdate->timestart = $session->starttime;
            $sessionsdate->timefinish = $session->finishtime;
            $sessionsdateid = $DB->insert_record('facetoface_sessions_dates', $sessionsdate);

            if (!$sessionsdateid) {
                $this->errors[] = get_string('error:failedtocreatedates', 'facetoface', $sessionid);

                continue;
            }

            foreach ($record as $column => $value) {
                // If the column does not start with "Customfield_", skip it.
                if (strpos($column, 'Customfield_') !== 0) {
                    continue;

                }

                $shortname = substr($column, strlen('Customfield_'));

                // If we don’t have a matching custom field for $shortname, skip it.
                if (!isset($customfieldsbyshortname[$shortname])) {

                    continue;
                }

                // Otherwise, save the custom field.
                $field = $customfieldsbyshortname[$shortname];
                if (!facetoface_save_customfield_value($field->id, $value, $sessionid, 'session')) {
                    $this->errors[] = get_string('error:couldnotsavecustomfieldshort', 'facetoface', $shortname);
                }
            }
        }

        return empty($this->errors);
    }


    /**
     * Get validation or processing errors.
     *
     * @return array array of error messages
     */
    public function get_errors():array {
        return $this->errors;
    }

    /**
     * Retrieves the CSV records after they've been loaded.
     * If a file is used, it will parse and return the data.
     *
     * @return array
     */
    public function get_records():array {
        if ($this->usefile) {
            $this->records = iterator_to_array($this->get_iterator());
        }

        return $this->records;
    }

    /**
     * Sets case-insensitive match value.
     *
     * @param bool $value
     */
    public function set_case_insensitive(bool $value):void {
        $this->caseinsensitive = $value;
    }
}
