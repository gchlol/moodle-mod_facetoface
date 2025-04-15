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
use stdClass;
use moodle_url;
use stored_file;

/**
 * Manages bulk session creation for Face-to-Face module.
 * Handles CSV parsing, validation, and session creation.
 * Supports file uploads.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

 */
class bulk_session_manager {
    /** @var int Facetoface instance ID */
    private int $facetofaceid;

    /** @var array Parsed CSV records */
    private array $records = [];

    /** @var array Validation errors */
    private array $errors = [];

    /** @var bool Indicates whether a file is used */
    private bool $usefile = false;

    /** @var stored_file|null Reference to uploaded file */
    private ?stored_file $file = null;

    /**
     * Constructor.
     *
     * @param int $facetofaceid ID of the Face-to-Face activity.
     */
    public function __construct(int $facetofaceid) {
        $this->facetofaceid = $facetofaceid;
    }

    /**
     * Loads CSV data from a draft file area.
     * Throws an exception if the file cannot be loaded or doesn't exist.
     *
     * @param int $fileid The draft file ID.
     * @return bool True on success.
     * @throws moodle_exception If the file cannot be loaded.
     */
    public function load_from_file(int $fileid): bool {
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
    public static function get_headers(): array {
        return [
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
     * Provides a record iterator for CSV rows, either from memory or a file.
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

        $handle = $this->file->get_content_file_handle();
        $maxlinelength = 1000;
        $delimiter = ',';

        // Read the first row as the header.
        $headerline = fgetcsv($handle, $maxlinelength, $delimiter);

        if (empty($headerline)) {
            fclose($handle);
            throw new moodle_exception(
                'error:noheaderrow',
                'mod_facetoface'
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
                new moodle_url('/mod/facetoface/uploadbulksessions.php',
                ['f2fid' => $this->facetofaceid]),
                $required
            );
        }

        try {
            $rownum = 2;
            while (($data = fgetcsv($handle, $maxlinelength, $delimiter)) !== false) {
                if (count($data) !== count($headerline)) {
                    throw new moodle_exception(
                        'error:bookingsuploadfileheaderfieldmismatch',
                        'facetoface',
                        '',
                        $rownum
                    );
                }
                yield array_combine($headerline, $data);
                $rownum++;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Validates the loaded CSV records for required fields, types, etc.
     *
     * @return array A list of validation errors.
     */
    public function validate(): array {
        // If using file, load records from iterator.
        if ($this->usefile) {
            $this->records = iterator_to_array($this->get_iterator());
        }

        foreach ($this->records as $index => $record) {
            // Trim all fields first.
            foreach ($record as $key => $value) {
                $record[$key] = trim($value);
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
     * Processes valid records to create new Face-to-Face sessions.
     * Inserts the session and its schedule into the database.
     * If any errors occur, they are stored in $this->errors.
     *
     * @return bool True if all sessions were created successfully, false otherwise.
     */
    public function process(): bool {
        global $DB;

        $allcustomfields = facetoface_get_session_customfields();
        $customfieldsbyshortname = [];

        foreach ($allcustomfields as $field) {
            $customfieldsbyshortname[$field->shortname] = $field;
        }

        foreach ($this->records as $index => $record) {
            $session = new stdClass();
            $session->facetoface = $this->facetofaceid;

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
                $this->errors[] = [$index, get_string('error:invaliddatetimedata', 'facetoface')];
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
                $this->errors[] = [$index, get_string('error:failedtocreatesession', 'facetoface')];

                continue;
            }

            // Insert date record.
            $sessionsdate = new stdClass();
            $sessionsdate->sessionid  = $sessionid;
            $sessionsdate->timestart  = $session->starttime;
            $sessionsdate->timefinish = $session->finishtime;
            $sessionsdateid = $DB->insert_record('facetoface_sessions_dates', $sessionsdate);

            if (!$sessionsdateid) {
                $this->errors[] = [$index, get_string('error:failedtocreatedates', 'facetoface', $sessionid)];
            }

            // Save any custom fields via the same approach as single-session.
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
                    $this->errors[] = [$index, get_string('error:couldnotsavecustomfieldshort', 'facetoface', $shortname)];
                }
            }
        }

        return empty($this->errors);
    }


    /**
     * Retrieves any validation or processing errors encountered.
     *
     * @return array A list of error entries.
     */
    public function get_errors(): array {
        return $this->errors;
    }

    /**
     * Retrieves the CSV records after they've been loaded.
     * If a file is used, it will parse and return the data.
     *
     * @return array List of CSV records.
     */
    public function get_records():array {
        if ($this->usefile) {
            $this->records = iterator_to_array($this->get_iterator());
        }

        return $this->records;
    }
}
