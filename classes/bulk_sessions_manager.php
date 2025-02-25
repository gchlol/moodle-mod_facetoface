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

/**
 * Bulk sessions manager.
 *
 * @author Jonas Sajonas
 * @copyright GCHLOL, 2025
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_sessions_manager
{
    /** @var int The facetoface instance ID */
    private $facetofaceid;

    /** @var array Parsed CSV records */
    private $records = [];

    /** @var array Validation errors */
    private $errors = [];

    /** To provide clarity and acoid undefined properties */
    private $usefile;
    private $file;

    /**
     * Constructor.
     *
     * @param int $facetofaceid The facetoface instance ID
     */
    public function __construct(int $facetofaceid)
    {
        $this->facetofaceid = $facetofaceid;
    }

    /**
     * Load CSV data from a file (given its fileid).
     *
     * @param int $fileid the file ID from the filemanager
     *
     * @return bool true if loaded successfully, false otherwise
     */
    public function load_from_file(int $fileid)
    {
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
     * Load in the records to process from an array.
     */
    public function load_from_array(array $records)
    {
        $this->usefile = false;
        $this->records = $records;

        return $this;
    }

    /**
     * Get headers for the records.
     *
     * @return array
     */
    public static function get_headers()
    {
        return [
            'Session date/time known',
            'Start date and time',
            'finish date and time',
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
     * Get an iterator for the records.
     *
     * @return Generator
     */
    private function get_iterator(): \Generator
    {
        if (!$this->usefile) {
            foreach ($this->records as $record) {
                yield $record;
            }

            return;
        }
        $handle = $this->file->get_content_file_handle();
        $maxlinelength = 1000;
        $delimiter = ',';
        // Read headers from file (if you want dynamic headers, otherwise use self::get_headers())
        $headers = self::get_headers();
        $numheaders = count($headers);
        fgets($handle); // Skip header row.
        try {
            while (($data = fgetcsv($handle, $maxlinelength, $delimiter)) !== false) {
                if (count($data) !== $numheaders) {
                    throw new \moodle_exception('error:bookingsuploadfileheaderfieldmismatch', 'mod_facetoface');
                }
                // Yield as an associative array.
                yield array_combine($headers, $data);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Validate the loaded CSV records.
     *
     * @return array an array of error messages (empty if no errors)
     */
    public function validate()
    {
        // If using file, load records from iterator.
        if ($this->usefile) {
            $this->records = iterator_to_array($this->get_iterator());
        }

        foreach ($this->records as $record) {
            if (empty($record['Start date and time'])) {
                $this->errors[] = get_string('facetoface:missingstarttime', 'facetoface');
            }
            if (empty($record['finish date and time'])) {
                $this->errors[] = get_string('facetoface:missingfinishtime', 'facetoface');
            }
        }

        return $this->errors;
    }

    /**
     * Process the valid records to create sessions.
     *
     * @return bool true on success, false if any errors occurred
     */
    public function process()
    {
        global $DB;

        foreach ($this->records as $record) {
            $session = new \stdClass();
            // Always use the facetoface instance ID from the manager.
            $session->facetofaceid = $this->facetofaceid;

            // Session date/time known: default to yes.
            $session->datetimeknown = isset($record['Session date/time known']) ?
                ($record['Session date/time known'] === 'no' ? 0 : 1) : 1;

            // Start and finish times (assumed to be in a valid date/time string format).
            $session->starttime = !empty($record['Start date and time']) ? strtotime($record['Start date and time']) : null;
            $session->finishtime = !empty($record['finish date and time']) ? strtotime($record['finish date and time']) : null;

            if ($session->datetimeknown && (empty($session->starttime) || empty($session->finishtime))) {
                $this->errors[] = get_string('facetoface:invaliddatetimedata', 'facetoface');
                continue; // Skip this record
            }

            // Allow sign up cancellations: default yes (1) unless CSV explicitly says "no".
            $session->allowcancel = isset($record['allow cancelations']) ?
                ($record['allow cancelations'] === 'no' ? 0 : 1) : 1;

            // Capacity: default to 10 if not provided.
            $session->capacity = !empty($record['Capacity']) ? (int) $record['Capacity'] : 10;

            // Allow overbooking: default yes (1).
            $session->overbook = isset($record['Allow overbookings']) ?
                ($record['Allow overbookings'] === 'no' ? 0 : 1) : 1;

            // Duration is required.
            $session->duration = isset($record['Duration']) && is_numeric($record['Duration']) ? (int) $record['Duration'] : 0;

            // Normal Cost: optional.
            $session->normalcost = isset($record['Normal Cost']) ? $record['Normal Cost'] : null;

            // Discount Cost: optional.
            $session->discountcost = isset($record['Discount Cost']) ? $record['Discount Cost'] : null;

            // Details: optional.
            $session->details = !empty($record['Details']) ? $record['Details'] : '';

            // Handle custom fields only if both shortname and value exist
            if (!empty($record['customfield_shortname']) && isset($record['customfield_value'])) {
                $session->customfields = [
                    $record['customfield_shortname'] => $record['customfield_value'],
                ];
            }

            // Insert the session record into the database.
            if (!$DB->insert_record('facetoface_sessions', $session)) {
                $this->errors[] = get_string('facetoface:failedtocreatesession', 'facetoface')
                    .' ('.implode(', ', $record).')';
            }
        }

        return empty($this->errors);
    }

    /**
     * Get validation or processing errors.
     *
     * @return array array of error messages
     */
    public function get_errors()
    {
        return $this->errors;
    }
}
