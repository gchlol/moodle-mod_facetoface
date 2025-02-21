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
 * Bulk sessions manager
 * 
 * @package mod_facetoface
 * @author Jonas Sajonas
 * @copyright GCHLOL, 2025
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_sessions_manager {

        /** @var int The facetoface instance ID */
        private $facetofaceid;

        /** @var array Parsed CSV records */
        private $records = [];
    
        /** @var array Validation errors */
        private $errors = [];


        /**
         * Constructor
         * 
         * @param int $facetofaceid The facetoface instance ID
         */
        public function __construct(int $facetofaceid) {
            $this->facetofaceid = $facetofaceid;
        }

        /**
         * Load CSV data from a file (given its fileid).
         * 
         * @param int $fileid The file ID from the filemanager.
         * @return bool True if loaded successfully, false otherwise.
         */
        public function load_from_file(int $fileid) {
            global $USER;
            $this->userfile = true;

            $fs = get_file_storage();
            $usercontext = \context_user::intance(instance->id);
            $files = $fs->get_area_files($usercontext->id, 'user','draft', $fileid, 'id', false);
        
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
     * Get headers for the records.
     * @return array
     */

     public static function get_headers(){
        return [];
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
     * Validate the loaded CSV records.
     * @return array An array of error messages (empty if no errors).
     */
    public function validate() {
        foreach ($this->records as $record) {
            // Validate required fields.
            if (empty($record['Facetoface id'])) {
                $this->errors[] = get_string('error:missingfacetofaceid', 'facetoface');
            }
            if (empty($record['Start date and time'])) {
                $this->errors[] = get_string('error:missingstarttime', 'facetoface');
            }
            if (empty($record['finish date and time'])) {
                $this->errors[] = get_string('error:missingfinishtime', 'facetoface');
            }
            // You can add additional validations for date formats, numeric capacity, etc.
        }
        return $this->errors;
    }

      /**
     * Process the valid records to create sessions.
     *
     * @return bool True on success, false if any errors occurred.
     */
    public function process() {
        global $DB;

        foreach ($this->records as $record) {
            // Prepare session data.
            $session = new \stdClass();
            // Use the provided Facetoface id from the CSV (if needed) or the one set for the manager.
            $session->facetofaceid = $record['Facetoface id'] ?: $this->facetofaceid;
            $session->starttime   = strtotime($record['Start date and time']);
            $session->finishtime  = strtotime($record['finish date and time']);
            $session->allowcancel = isset($record['allow cancelations']) ? ($record['allow cancelations'] === 'no' ? 0 : 1) : 1;
            $session->capacity    = !empty($record['Capacity']) ? (int)$record['Capacity'] : 10;
            $session->overbook    = isset($record['Allow overbookings']) ? ($record['Allow overbookings'] === 'no' ? 0 : 1) : 1;
            $session->details     = !empty($record['Details']) ? $record['Details'] : '';

            // Handle custom field(s) if provided.
            if (!empty($record['customfield_shortname'])) {
                // For example, you might store this in a serialized field or call a dedicated function.
                $session->customfields = [$record['customfield_shortname'] => $record['customfield_value'] ?? ''];
            }

            // Insert the session into the database.
            // You might need to call a dedicated function instead.
            if (!$DB->insert_record('facetoface_sessions', $session)) {
                $this->errors[] = get_string('error:failedtocreatesession', 'facetoface') . ' (' . implode(', ', $record) . ')';
            }
        }
        return empty($this->errors);
    }

    /**
     * Get validation or processing errors.
     *
     * @return array Array of error messages.
     */
    public function get_errors() {
        return $this->errors;
    }
}

