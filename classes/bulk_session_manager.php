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

/**
 * Manages bulk session creation for Face-to-Face module.
 * Handles CSV parsing, validation, and session creation.
 * Supports file uploads and array-based records.
 *
 * @package     mod_facetoface
 * @copyright   2025 Gold Coast Health
 * @author      Jonas Sajonas
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_session_manager {
    /** @var int Facetoface instance ID */
    private $facetofaceid;

    /** @var array Parsed CSV records */
    private $records = [];

    /** @var array Validation errors */
    private $errors = [];

    /** @var bool Indicates whether a file is used */
    private $usefile = false;

    /** @var stored_file|null Reference to uploaded file */
    private $file = null;

    /** @var bool Will ignore case when matching users */
    private $caseinsensitive = false;

    /** @var bool When true, confirmation emails are not sent. */
    private $suppressemail = false;

    /**
     * Constructor.
     *
     * @param int $facetofaceid
     */
    public function __construct(int $facetofaceid) {
        $this->facetofaceid = $facetofaceid;
    }

    /**
     * Load CSV data from a file (given its fileid).
     *
     * @param int $fileid
     *
     * @return bool true if loaded successfully, false otherwise
     */
    public function load_from_file(int $fileid) {
        global $USER;
        $this->usefile = true;

        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $fileid, 'id', false);

        if (count($files) != 1) {
            throw new moodle_exception('error:cannotloadfile', 'mod_facetoface');
        }
        $this->file = current($files);

        return true;
    }

    /**
     * Load in records to process from an array.
     */
    public function load_from_array(array $records) {
        $this->usefile = false;
        $this->records = $records;

        return $this;
    }

    /**
     * Get headers for records.
     *
     * @return array
     */
    public static function get_headers() {
        return [
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
     *
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
        $headers = self::get_headers();
        $numheaders = count($headers);
        fgets($handle);

        try {
            while (($data = fgetcsv($handle, $maxlinelength, $delimiter)) !== false) {
                if (count($data) !== $numheaders) {
                    throw new moodle_exception('error:bookingsuploadfileheaderfieldmismatch', 'mod_facetoface');
                }
                foreach ($data as &$field) {
                    $field = trim($field, "'");
                }
                yield array_combine($headers, $data);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Validate loaded CSV records.
     * @return array an array of error messages (empty if no errors)
     */
    public function validate() {
        // If using file, load records from iterator.
        if ($this->usefile) {
            $this->records = iterator_to_array($this->get_iterator());
        }

        foreach ($this->records as $index => $record) {
            foreach ($record as $key => $value) {
                $record[$key] = trim($value);
            }

            // Required: Start Date and Start Time.
            if (empty($record['Start date']) || empty($record['Start time'])) {
                $this->errors[] = [$index, get_string('error:missingstarttime', 'facetoface')];
            } else {
                $date = DateTime::createFromFormat('d/m/Y H:i', $record['Start date'] . ' ' . $record['Start time']);
                if ($date) {
                    $record['Start date and time'] = $date->format('Y-m-d H:i');
                } else {
                    $this->errors[] = [$index, get_string('error:invalidstarttime', 'facetoface')
                    . ": {$record['Start date']} {$record['Start time']}"];
                }
            }

            // Required: Finish Date and Finish Time.
            if (empty($record['Finish date']) || empty($record['Finish time'])) {
                $this->errors[] = [$index, get_string('error:missingfinishtime', 'facetoface')];
            } else {
                $date = DateTime::createFromFormat('d/m/Y H:i', $record['Finish date'] . ' ' . $record['Finish time']);
                if ($date) {
                    $record['finish date and time'] = $date->format('Y-m-d H:i');
                } else {
                    $this->errors[] = [$index, get_string('error:invalidfinishtime', 'facetoface')
                    . ": {$record['Finish date']} {$record['Finish time']}"];
                }
            }

            // Ensure Start time is before Finish time.
            if (!empty($record['Start date and time']) && !empty($record['finish date and time'])) {
                $starttime = strtotime($record['Start date and time']);
                $finishtime = strtotime($record['finish date and time']);
                if ($starttime && $finishtime && $starttime >= $finishtime) {
                    $this->errors[] = [$index, get_string('error:starttimeafterfinish', 'facetoface')];
                }
            }

            // Required: Capacity (must be a number).
            if (!isset($record['Capacity']) || !is_numeric($record['Capacity']) || (int)$record['Capacity'] <= 0) {
                $this->errors[] = [$index, get_string('error:invalidcapacity', 'facetoface')];
            }

            // Required: Duration (must be a number).
            if (!isset($record['Duration']) || !is_numeric($record['Duration']) || (int)$record['Duration'] <= 0) {
                $this->errors[] = [$index, get_string('error:invalidduration', 'facetoface')];
            }

            // Optional: Normal Cost (must be a valid number if provided).
            if (!empty($record['Normal Cost']) && !is_numeric($record['Normal Cost'])) {
                $this->errors[] = [$index, get_string('error:invalidnormalcost', 'facetoface')];
            }

            // Optional: Discount Cost (must be a valid number if provided).
            if (!empty($record['Discount Cost']) && !is_numeric($record['Discount Cost'])) {
                $this->errors[] = [$index, get_string('error:invaliddiscountcost', 'facetoface')];
            }

            // Required: Allow Cancelations (must be "yes" or "no").
            if (!in_array(strtolower($record['allow cancelations'] ?? ''), ['yes', 'no'], true)) {
                $this->errors[] = [$index, get_string('error:invalidallowcancel', 'facetoface')];
            }

            // Required: Allow Overbookings (must be "yes" or "no").
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

            // Always use facetoface instance ID.
            $session->facetoface = $this->facetofaceid;

            // Session date/time known: default yes.
            $session->datetimeknown = isset($record['Session date/time known'])
                ? ($record['Session date/time known'] === 'no' ? 0 : 1)
                : 1;

            // Combine Start Date and Time.
            $session->starttime = (!empty($record['Start date']) && !empty($record['Start time']))
            ? strtotime(str_replace('/', '-', $record['Start date'] . ' ' . $record['Start time']))
            : null;

            // Combine Finish Date and Time.
            $session->finishtime = (!empty($record['Finish date']) && !empty($record['Finish time']))
            ? strtotime(str_replace('/', '-', $record['Finish date'] . ' ' . $record['Finish time']))
            : null;

            if ($session->datetimeknown && (empty($session->starttime) || empty($session->finishtime))) {
                $this->errors[] = get_string('error:invaliddatetimedata', 'facetoface');
                continue; // Skip if invalid.
            }

            // Allow sign up cancellations: default yes.
            $session->allowcancel = isset($record['allow cancelations'])
                ? ($record['allow cancelations'] === 'no' ? 0 : 1)
                : 1;

            // Capacity: default to 10 if not provided.
            $session->capacity = !empty($record['Capacity'])
                ? (int) $record['Capacity']
                : 10;

            // Allow overbooking: default yes.
            $session->overbook = isset($record['Allow overbookings'])
                ? ($record['Allow overbookings'] === 'no' ? 0 : 1)
                : 1;

            // Duration is required.
            $session->duration = isset($record['Duration']) && is_numeric($record['Duration'])
                ? (int) $record['Duration']
                : 0;

            // Normal Cost: optional.
            $session->normalcost = isset($record['Normal Cost'])
                ? $record['Normal Cost']
                : null;

            // Discount Cost: optional.
            $session->discountcost = isset($record['Discount Cost'])
                ? $record['Discount Cost']
                : null;

            // Details: optional.
            $session->details = !empty($record['Details'])
                ? $record['Details']
                : '';

            // Handle custom fields if both shortname and value exist.
            if (!empty($record['customfield_shortname']) && isset($record['customfield_value'])) {
                $session->customfields = [
                    $record['customfield_shortname'] => $record['customfield_value'],
                ];
            }

            $session->timecreated = time();

            // Insert session record.
            $sessionid = $DB->insert_record('facetoface_sessions', $session);

            if (!$sessionid) {
                $this->errors[] = get_string('error:failedtocreatesession', 'facetoface') . ' (' . implode(', ', $record) . ')';
                continue;
            }

            // Insert session date into mdl_facetoface_sessions_dates.
            $sessionsdate = new \stdClass();
            $sessionsdate->sessionid = $sessionid;
            $sessionsdate->timestart = $session->starttime;
            $sessionsdate->timefinish = $session->finishtime;

            if (!$DB->insert_record('facetoface_sessions_dates', $sessionsdate)) {
                $this->errors[] = get_string('error:failedtoinsertdates', 'facetoface') . " for session ID {$sessionid}";
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
     * @return array List of valid records.
     */
    public function get_records() {
        if ($this->usefile) {
            $this->records = iterator_to_array($this->get_iterator());
        }
        return $this->records;
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
}
