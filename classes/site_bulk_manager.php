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

defined('MOODLE_INTERNAL') || die();

use moodle_exception;
use Generator;
use DateTime;


class site_bulk_manager {

    /** @var array Parsed CSV records */
    private $records = [];

    /** @var array Validation errors */
    private $errors = [];

    /** @var bool $Indicates whether records are being loaded */
    private $usefile;

    /** @var file object containing CSV data. */
    private $file;

    /** @var bool Will ignore case when matching users */
    private $caseinsensitive = false;

    /** @var bool When true, confirmation emails are not sent. */
    private $suppressemail = false;

    /**
     * Constructor.
     * @param int $facetofaceid
     */
    public function __construct() {
    }

    /**
     * Load CSV data from a file (given its fileid).
     * @param int $fileid
     * @return bool true if loaded successfully, false otherwise
     */
    public function load_from_file(int $fileid) {
        global $USER;

        $this->usefile = true;
        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);

        // Retrieve the files from the draft area; we expect exactly one CSV file.
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $fileid, 'id', false);

        if (count($files) != 1) {
            throw new moodle_exception('error:cannotloadfile', 'mod_facetoface');
        }
        $this->file = current($files);

        return true;
    }

    /**
     * Load in records to process from an array.
     * @param array $records
     * @return self
     */
    public function load_from_array(array $records) {
        $this->usefile = false;
        $this->records = $records;
        return $this;
    }

    /**
     * Returns the column headers used by the CSV.
     *
     * @return array
     */
    public static function get_headers() {
        return [
            'Course shortname',
            'Face-to-face activity name',
            'Session date/time known',
            'Start date',
            'Start time',
            'Finish date',
            'Finish time',
            'allow cancelations',
            'Capacity',
            'Allow overbookings',
            'Duration',
            'Normal Cost',
            'Discount Cost',
            'Details',
            'customfield_shortname',
            'customfield_value',
        ];
    }

    /**
     * Get an iterator for records.
     * @return Generator
     */
    private function get_iterator(): \Generator {
        // If the manager is not using a file, simply yield from $this->records.
        if (!$this->usefile) {
            foreach ($this->records as $record) {
                yield $record;
            }
            return;
        }

        // Read from the stored file.
        $handle = $this->file->get_content_file_handle();
        $maxlinelength = 1000;
        $delimiter = ',';

        // Extract the headers and skip header line.
        $headers = self::get_headers();
        $numheaders = count($headers);

        // Skip the first line.
        fgets($handle);

        try {
            // Read each line.
            while (($data = fgetcsv($handle, $maxlinelength, $delimiter)) !== false) {
                if (count($data) !== $numheaders) {
                    throw new moodle_exception('error:bookingsuploadfileheaderfieldmismatch', 'mod_facetoface');
                }
                // Trim any surrounding single quotes.
                foreach ($data as &$field) {
                    $field = trim($field, "'");
                }
                yield array_combine($headers, $data);
            }
        } finally {
            // Close the file.
            fclose($handle);
        }
    }

    /**
     * Validate loaded CSV records.
     *
     * @return array An array of error messages (empty if no errors)
     */
    public function validate() {
        global $DB;

        // If using a file, convert it into an array once.
        if ($this->usefile) {
            $this->records = iterator_to_array($this->get_iterator());
        }

        foreach ($this->records as $index => $record) {
            $record = array_map('trim', $record);

            if (empty($record['Course shortname'])) {
                $this->errors[] = [$index, get_string('error:missingcourseshortname', 'facetoface')];
            }
            if (empty($record['Face-to-face activity name'])) {
                $this->errors[] = [$index, get_string('error:missingf2fname', 'facetoface')];
            }

            // Look up the course shortname.
            $shortname = $record['Course shortname'] ?? '';
            if (!empty($shortname)) {
                $shortnamecondition = $DB->sql_equal('shortname', ':shortname', !$this->caseinsensitive);
                $course = $DB->get_record_select('course', $shortnamecondition, ['shortname' => $shortname]);
                if (!$course) {
                    $this->errors[] = [$index, get_string('error:course_not_found', 'facetoface') . " ({$shortname})"];
                }
            }

            // Look up the Face-to-face record.
            $f2fname = $record['Face-to-face activity name'] ?? '';
            $f2frecord = null;

            // If $course doesn't exist, $course->id won't exist either, so fallback to 0.
            $courseid = $course ? $course->id : 0;

            if (!empty($f2fname)) {
                $f2frecord = $DB->get_record('facetoface', ['course' => $courseid, 'name'   => $f2fname
                ]);

                if (!$f2frecord) {
                    $this->errors[] = [$index, get_string('error:f2f_not_found', 'facetoface')
                        . " (Course: {$shortname} | Name: {$f2fname})"
                    ];
                }
            }

            // Validate date/time fields
            if (empty($record['Start date']) || empty($record['Start time'])) {
                $this->errors[] = [ $index, get_string('error:missingstarttime', 'facetoface')];
            } else {
                 $datetime = DateTime::createFromFormat(
                     'd/m/Y H:i', $record['Start date'] . ' ' . $record['Start time']
                 );
                if (!$datetime) {
                    $this->errors[] = [ $index, get_string('error:invalidstarttime', 'facetoface')
                    . ": {$record['Start date']} {$record['Start time']}"];
                }
            }

            if (empty($record['Finish date']) || empty($record['Finish time'])) {
                $this->errors[] = [ $index, get_string('error:missingfinishtime', 'facetoface')];
            } else {
                $datetime = DateTime::createFromFormat(
                    'd/m/Y H:i',
                    $record['Finish date'] . ' ' . $record['Finish time']
                );
                if (!$datetime) {
                    $this->errors[] = [
                        $index,
                        get_string('error:invalidfinishtime', 'facetoface') .
                        ": {$record['Finish date']} {$record['Finish time']}"
                    ];
                }
            }

            // Validate numeric fields.
            if (!isset($record['Capacity']) || !is_numeric($record['Capacity']) || (int)$record['Capacity'] <= 0) {
                $this->errors[] = [$index, get_string('error:invalidcapacity', 'facetoface')];
            }
            if (!isset($record['Duration']) || !is_numeric($record['Duration']) || (int)$record['Duration'] <= 0) {
                $this->errors[] = [$index, get_string('error:invalidduration', 'facetoface')];
            }
            if (!empty($record['Normal Cost']) && !is_numeric($record['Normal Cost'])) {
                $this->errors[] = [$index, get_string('error:invalidnormalcost', 'facetoface')];
            }
            if (!empty($record['Discount Cost']) && !is_numeric($record['Discount Cost'])) {
                $this->errors[] = [$index, get_string('error:invaliddiscountcost', 'facetoface')];
            }

            // Validate yes/no fields for 'allow cancelations' and 'Allow overbookings'.
            if (!in_array(strtolower($record['allow cancelations'] ?? ''), ['yes', 'no'], true)) {
                $this->errors[] = [$index, get_string('error:invalidallowcancel', 'facetoface')];
            }
            if (!in_array(strtolower($record['Allow overbookings'] ?? ''), ['yes', 'no'], true)) {
                $this->errors[] = [$index, get_string('error:invalidallowoverbook', 'facetoface')];
            }
        }
        return $this->errors;
    }

    /**
     * Process valid records to create sessions.
     *
     * @return bool true on success, false if any errors occurred
     */
    public function process() {
        global $DB;

        foreach ($this->records as $record) {
            $session = new \stdClass();

            // Look up the course.
            $shortname = trim($record['Course shortname']);
            $shortnamecondition = $DB->sql_equal('shortname', ':shortname', !$this->caseinsensitive);

            $course = $DB->get_record_select(
                'course',
                $shortnamecondition,
                ['shortname' => $shortname]
            );

            if (!$course) {
                // Record an error and skip processing.
                $this->errors[] = get_string('error:course_not_found', 'facetoface') . " ({$shortname})";
                continue;
            }

            // Look up the Face-to-face record.
            $courseid = $course->id;
            $f2fname = trim($record['Face-to-face activity name']);

            $namecondition = $DB->sql_equal('name', ':f2fname', !$this->caseinsensitive);
            $where = $namecondition . ' AND course = :courseid';
            $params = [
                'f2fname' => $f2fname,
                'courseid' => $courseid
            ];

            $f2frecord = $DB->get_record_select('facetoface', $where, $params);

            if (!$f2frecord) {
                // If no matching Face-to-face record an error and skip.
                $this->errors[] = get_string('error:f2f_not_found', 'facetoface')
                    . " (Course: {$shortname} | F2F Name: {$f2fname})";
                continue;
            }


            // Build session data object.
            $session->facetoface = $f2frecord->id;
            $session->datetimeknown = isset($record['Session date/time known'])
                ? ($record['Session date/time known'] === 'no' ? 0 : 1)
                : 1;

            $session->starttime = (!empty($record['Start date']) && !empty($record['Start time']))
                ? strtotime(str_replace('/', '-', $record['Start date'].' '.$record['Start time']))
                : null;

            $session->finishtime = (!empty($record['Finish date']) && !empty($record['Finish time']))
                ? strtotime(str_replace('/', '-', $record['Finish date'].' '.$record['Finish time']))
                : null;

            // If datetimeknown is true, ensure both start and finish times are valid.
            if ($session->datetimeknown && (empty($session->starttime) || empty($session->finishtime))) {
                $this->errors[] = get_string('error:invaliddatetimedata', 'facetoface');
            }

            $session->allowcancel = isset($record['allow cancelations'])
                ? ($record['allow cancelations'] === 'no' ? 0 : 1)
                : 1;

            $session->capacity = !empty($record['Capacity'])
                ? (int) $record['Capacity']
                : 10;

            $session->overbook = isset($record['Allow overbookings'])
                ? ($record['Allow overbookings'] === 'no' ? 0 : 1)
                : 1;

            $session->duration = (isset($record['Duration']) && is_numeric($record['Duration']))
                ? (int) $record['Duration']
                : 0;

            $session->normalcost = isset($record['Normal Cost'])
                ? $record['Normal Cost']
                : null;

            $session->discountcost = isset($record['Discount Cost'])
                ? $record['Discount Cost']
                : null;

            $session->details = !empty($record['Details'])
                ? $record['Details']
                : '';

            // Custom fields.
            if (!empty($record['customfield_shortname']) && isset($record['customfield_value'])) {
                $session->customfields = [
                    $record['customfield_shortname'] => $record['customfield_value'],
                ];
            }

            $session->timecreated = time();

            // Insert the session record.
            $sessionid = $DB->insert_record('facetoface_sessions', $session);
            if (!$sessionid) {
                $this->errors[] = get_string('error:failedtocreatesession', 'facetoface')
                    . ' (' . implode(', ', $record) . ')';
                continue;
            }

            // Insert session dates.
            $sessionsdate = new \stdClass();
            $sessionsdate->sessionid = $sessionid;
            $sessionsdate->timestart = $session->starttime;
            $sessionsdate->timefinish = $session->finishtime;

            if (!$DB->insert_record('facetoface_sessions_dates', $sessionsdate)) {
                $this->errors[] = get_string('error:failedtoinsertdates', 'facetoface')
                    . " for session ID {$sessionid}";
            }
        }

        return empty($this->errors);
    }


    /**
     * Get validation or processing errors.
     *
     * @return array array of error messages
     */
    public function get_errors() {
        return $this->errors;
    }

    /**
     * Get parsed CSV records.
     *
     * @return array
     */
    public function get_records() {
        if ($this->usefile) {
            $this->records = iterator_to_array($this->get_iterator());
        }
        return $this->records;
    }

    /**
     * Suppress confirmation emails.
     */
    public function suppress_email() {
        $this->suppressemail = true;
    }

    /**
     * Sets case-insensitive match value.
     *
     * @param bool $value
     */
    public function set_case_insensitive(bool $value) {
        $this->caseinsensitive = $value;
    }
}
