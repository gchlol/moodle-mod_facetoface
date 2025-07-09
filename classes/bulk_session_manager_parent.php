<?php
/**
 * Handles bulk session uploads for the Face-to-Face module.
 * Manages CSV validation, preview, and session creation.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_facetoface;

use moodle_exception;
use Generator;
use DateTime;
use context_user;
use moodle_url;
use stored_file;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Base class for bulk session management (common CSV handling for activity and site contexts).
 * @package   mod_facetoface
 */
abstract class bulk_session_manager_parent {
    /** @var int Facetoface instance ID (for activity context, 0 for site context) */
    protected int $facetofaceid = 0;
    /** @var array Parsed CSV records */
    protected array $records = [];
    /** @var array Accumulated validation or processing errors */
    protected array $errors = [];
    /** @var bool Whether CSV data is loaded from a file */
    protected bool $usefile = false;
    /** @var stored_file|null Reference to the uploaded CSV file */
    protected ?stored_file $file = null;

    /**
     * Constructor.
     * @param int $facetofaceid Face-to-Face activity ID (0 if not applicable).
     */
    public function __construct(int $facetofaceid = 0) {
        $this->facetofaceid = $facetofaceid;
    }

    /**
     * Loads CSV data from a draft file area.
     * @param int $fileid The draft file ID.
     * @return bool True on success.
     * @throws moodle_exception If the file cannot be loaded or found.
     */
    public function load_from_file(int $fileid): bool {
        global $USER;
        $this->usefile = true;
        $fs = get_file_storage();
        $userctx = context_user::instance($USER->id);
        $files = $fs->get_area_files($userctx->id, 'user', 'draft', $fileid, 'id', false);
        if (count($files) !== 1) {
            throw new moodle_exception('error:cannotloadfile', 'mod_facetoface');
        }
        $this->file = reset($files);
        return true;
    }

    /**
     * Returns the required CSV column headers for bulk session uploads (to be overridden by children).
     * @return array List of expected column header strings.
     */
    public static function get_headers(): array {
        // Base headers for session scheduling (activity context by default).
        return [
            'Session Date/Time Known', 'Start Date', 'Start Time', 'Finish Date', 'Finish Time',
            'Allow Cancellations', 'Capacity', 'Allow Overbookings', 'Duration',
            'Normal Cost', 'Discount Cost', 'Details'
            // (Custom fields are handled dynamically and not listed here)
        ];
    }

    /**
     * Internal generator to iterate through CSV rows.
     * @return Generator Each yielded element is an associative array representing one CSV row.
     * @throws moodle_exception If CSV header is missing or required columns are missing.
     */
    private function get_iterator(): Generator {
        if (!$this->usefile) {
            // If records were set programmatically (not via file), yield them directly.
            foreach ($this->records as $rec) {
                yield $rec;
            }
            return;
        }
        $handle = $this->file->get_content_file_handle();
        $delimiter = ',';
        $header = fgetcsv($handle, 1000, $delimiter);
        if (empty($header)) {
            fclose($handle);
            throw new moodle_exception('error:noheaderrow', 'mod_facetoface');
        }
        // Verify required headers are present.
        $required = static::get_headers();
        foreach ($required as $col) {
            if (!in_array($col, $header, true)) {
                fclose($handle);
                // Redirect URL depends on context (implemented in child via get_base_url).
                throw new moodle_exception('error:missingrequiredcolumn', 'mod_facetoface', $this->get_base_url(), $col);
            }
        }
        try {
            $rownum = 2;
            while (($data = fgetcsv($handle, 1000, $delimiter)) !== false) {
                if (count($data) !== count($header)) {
                    // Column count mismatch on a row.
                    throw new moodle_exception('error:bookingsuploadfileheaderfieldmismatch', 'facetoface', '', $rownum);
                }
                yield array_combine($header, $data);
                $rownum++;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Validates all loaded CSV records for correctness.
     * @return array List of errors (empty if validation passed).
     */
    public function validate(): array {
        // If reading from file, parse the CSV contents.
        if ($this->usefile) {
            $this->records = iterator_to_array($this->get_iterator());
        }
        // Validate each record.
        foreach ($this->records as $index => $record) {
            // Trim all field values.
            foreach ($record as $key => $value) {
                $record[$key] = trim($value);
            }
            // Delegate to context-specific validation (implemented in child classes).
            $this->validate_record($record, $index);
        }
        return $this->errors;
    }

    /**
     * Performs common validation checks on a single CSV record (dates, times, numeric fields).
     * Child classes call this after their context-specific checks.
     * @param array $record The CSV record (already trimmed).
     * @param int $index Record index (for error line reference).
     */
    protected function validate_common_fields(array $record, int $index): void {
        // Start Date and Start Time must be provided.
        if (empty($record['Start Date']) || empty($record['Start Time'])) {
            $this->errors[] = [$index, get_string('error:missingstarttime', 'facetoface')];
            return;
        }
        $startdt = DateTime::createFromFormat('d/m/Y H:i', $record['Start Date'] . ' ' . $record['Start Time']);
        if (!$startdt) {
            $params = (object)['date' => $record['Start Date'], 'time' => $record['Start Time']];
            $this->errors[] = [$index, get_string('error:invalidstarttime', 'facetoface', $params)];
            return;
        }
        // Finish Date and Time must be provided.
        if (empty($record['Finish Date']) || empty($record['Finish Time'])) {
            $this->errors[] = [$index, get_string('error:missingfinishtime', 'facetoface')];
            return;
        }
        $finishdt = DateTime::createFromFormat('d/m/Y H:i', $record['Finish Date'] . ' ' . $record['Finish Time']);
        if (!$finishdt) {
            $params = (object)['date' => $record['Finish Date'], 'time' => $record['Finish Time']];
            $this->errors[] = [$index, get_string('error:invalidfinishtime', 'facetoface', $params)];
            return;
        }
        // Ensure Start is before Finish.
        $starttime  = strtotime($startdt->format('Y-m-d H:i'));
        $finishtime = strtotime($finishdt->format('Y-m-d H:i'));
        if ($starttime && $finishtime && $starttime >= $finishtime) {
            $this->errors[] = [$index, get_string('error:starttimeafterfinish', 'facetoface')];
            return;
        }
        // Capacity must be a positive integer.
        if (!isset($record['Capacity']) || !is_numeric($record['Capacity']) || (int)$record['Capacity'] <= 0) {
            $this->errors[] = [$index, get_string('error:invalidcapacity', 'facetoface')];
            return;
        }
        // Duration must be a positive integer.
        if (!isset($record['Duration']) || !is_numeric($record['Duration']) || (int)$record['Duration'] <= 0) {
            $this->errors[] = [$index, get_string('error:invalidduration', 'facetoface')];
            return;
        }
        // Normal Cost, if provided, must be numeric.
        if (!empty($record['Normal Cost']) && !is_numeric($record['Normal Cost'])) {
            $this->errors[] = [$index, get_string('error:invalidnormalcost', 'facetoface')];
            return;
        }
        // Discount Cost, if provided, must be numeric.
        if (!empty($record['Discount Cost']) && !is_numeric($record['Discount Cost'])) {
            $this->errors[] = [$index, get_string('error:invaliddiscountcost', 'facetoface')];
            return;
        }
        // Allow Cancellations must be "yes" or "no".
        $allowcancel = strtolower($record['Allow Cancellations'] ?? '');
        if (!in_array($allowcancel, ['yes', 'no'], true)) {
            $this->errors[] = [$index, get_string('error:invalidallowcancel', 'facetoface')];
            return;
        }
        // Allow Overbookings must be "yes" or "no".
        $allowover = strtolower($record['Allow Overbookings'] ?? '');
        if (!in_array($allowover, ['yes', 'no'], true)) {
            $this->errors[] = [$index, get_string('error:invalidallowoverbook', 'facetoface')];
            return;
        }
    }

    /**
     * Processes all valid records to create new sessions and related data.
     * @return bool True if all sessions were created successfully (or no records), false if any errors occurred.
     */
    public function process(): bool {
        global $DB;
        $this->errors = [];  // reset errors
        // Pre-fetch custom session fields for validation/saving.
        $allfields = facetoface_get_session_customfields();
        $customfieldsbyshortname = [];
        foreach ($allfields as $field) {
            $customfieldsbyshortname[strtolower($field->shortname)] = $field;
        }
        // Process each record.
        foreach ($this->records as $index => $record) {
            // (Context-specific handling done in child override before calling common processing.)
            $this->process_record($record, $index, $customfieldsbyshortname);
        }
        return empty($this->errors);
    }

    /**
     * Performs common session creation for a single record. Assumes context-specific preconditions (like facetofaceid) are handled.
     * @param array $record The CSV record data.
     * @param int $index The record index for error reference.
     * @param array $customfieldsbyshortname Map of custom field shortnames to field objects.
     */
    protected function process_session_record(stdClass $session, array $record, int $index, array $customfieldsbyshortname): void {
        global $DB;
        // Determine if date/time known.
        $session->datetimeknown = 1;
        if (($record['Session Date/Time Known'] ?? '') === 'no') {
            $session->datetimeknown = 0;
        }
        // Parse start time and finish time if provided.
        $session->starttime = (!empty($record['Start Date']) && !empty($record['Start Time']))
            ? strtotime(str_replace('/', '-', $record['Start Date'] . ' ' . $record['Start Time']))
            : null;
        $session->finishtime = (!empty($record['Finish Date']) && !empty($record['Finish Time']))
            ? strtotime(str_replace('/', '-', $record['Finish Date'] . ' ' . $record['Finish Time']))
            : null;
        // If date/time is known but start or finish is missing, record error.
        if ($session->datetimeknown && (empty($session->starttime) || empty($session->finishtime))) {
            $this->errors[] = [$index, get_string('error:invaliddatetimedata', 'facetoface')];
            return;
        }
        $session->allowcancellations = (($record['Allow Cancellations'] ?? '') === 'no') ? 0 : 1;
        $session->capacity       = (isset($record['Capacity']) && is_numeric($record['Capacity'])) ? (int)$record['Capacity'] : 10;
        $session->allowoverbook  = (($record['Allow Overbookings'] ?? '') === 'no') ? 0 : 1;
        $session->duration       = (isset($record['Duration']) && is_numeric($record['Duration'])) ? (int)$record['Duration'] : 0;
        $session->normalcost     = (isset($record['Normal Cost']) && is_numeric($record['Normal Cost'])) ? $record['Normal Cost'] : 0;
        $session->discountcost   = (isset($record['Discount Cost']) && is_numeric($record['Discount Cost'])) ? $record['Discount Cost'] : 0;
        $session->details        = (isset($record['Details']) && is_string($record['Details'])) ? $record['Details'] : '';
        $session->timecreated    = time();
        $session->timemodified   = time();
        // Insert the session.
        $sessionid = $DB->insert_record('facetoface_sessions', $session);
        if (!$sessionid) {
            $this->errors[] = [$index, get_string('error:failedtocreatesession', 'facetoface')];
            return;
        }
        // Insert session dates record.
        $sd = new stdClass();
        $sd->sessionid  = $sessionid;
        $sd->timestart  = $session->starttime;
        $sd->timefinish = $session->finishtime;
        if (!$DB->insert_record('facetoface_sessions_dates', $sd)) {
            $this->errors[] = [$index, get_string('error:failedtocreatedates', 'facetoface', $sessionid)];
        }
        // Handle any custom fields in the record.
        foreach ($record as $column => $value) {
            if (strpos($column, 'Customfield_') !== 0) {
                continue;
            }
            $shortname = strtolower(substr($column, strlen('Customfield_')));
            if (!isset($customfieldsbyshortname[$shortname])) {
                $this->errors[] = [$index, get_string('error:unknowncustomfieldshort', 'facetoface', $shortname)];
                continue;
            }
            $field = $customfieldsbyshortname[$shortname];
            if (!facetoface_save_customfield_value($field->id, $value, $sessionid, 'session')) {
                $this->errors[] = [$index, get_string('error:couldnotsavecustomfieldshort', 'facetoface', $shortname)];
            }
        }
    }

    // Abstract methods that child classes must implement:

    /**
     * Returns the base URL for errors (differs in activity vs site context).
     * @return moodle_url The URL of the bulk upload page for this context.
     */
    abstract protected function get_base_url(): moodle_url;

    /**
     * Context-specific validation for a single record. Should call validate_common_fields() after special checks.
     * @param array $record The CSV record (trimmed).
     * @param int $index Record index.
     */
    abstract protected function validate_record(array $record, int $index): void;

    /**
     * Context-specific processing for a single record. Should prepare session object and call process_session_record().
     * @param array $record The CSV record.
     * @param int $index Record index.
     * @param array $customfields Map of custom fields.
     */
    abstract protected function process_record(array $record, int $index, array $customfieldsbyshortname): void;
}
