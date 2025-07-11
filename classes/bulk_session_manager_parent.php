<?php
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

namespace mod_facetoface;

use moodle_exception;
use Generator;
use context_user;
use moodle_url;
use stored_file;

defined('MOODLE_INTERNAL') || die();

/**
 * Base class for bulk session management (common CSV handling for activity and site contexts).
 *
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
     *
     * @param int $facetofaceid Face-to-Face activity ID (0 for site-level bulk upload).
     */
    public function __construct(int $facetofaceid = 0) {
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
     * Provides a record iterator for CSV rows, either from file.
     *
     * @return Generator Yields each CSV record as an associative array.
     * @throws moodle_exception
     */
    protected function get_iterator(): Generator {
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
                //new moodle_url('/mod/facetoface/uploadbulksessions.php',
                new moodle_url('/mod/facetoface/uploadbulksessions_activitylevel.php',
                    ['f2fid' => $this->facetofaceid]),
                $required
            );
        }

        try {
            $rownum = 2; // The error message matches the row number in Excel.
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
     * @throws moodle_exception
     */
    public function get_records(): array {
        if ($this->usefile) {
            $this->records = iterator_to_array($this->get_iterator());
        }

        return $this->records;
    }

    /**
     * Validates the loaded CSV records for required fields, types, etc.
     *
     * @return array A list of validation errors.
     */
    protected function validate(): array {
        return []; // FIXME
    }

    /**
     * Processes valid records to create new Face-to-Face sessions.
     * Inserts the session and its schedule into the database.
     * If any errors occur, they are stored in $this->errors.
     *
     * @return bool True if all sessions were created successfully, false otherwise.
     */
    protected function process(): bool {
        return false; // FIXME
    }
}
