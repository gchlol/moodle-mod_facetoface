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

use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Bulk session manager for activity context (activity-level Face-to-Face).
 *
 * @package   mod_facetoface
 */
class bulk_session_manager_activitylevel extends bulk_session_manager_parent {
    /**
     * Constructor requires a Face-to-Face instance ID.
     *
     * @param int $facetofaceid Face-to-Face activity ID.
     */
    public function __construct(int $facetofaceid) {
        parent::__construct($facetofaceid);
    }

    /**
     * Validates a record within the activity context by checking common fields.
     *
     * @param array $record The record data to validate.
     * @param int $index The index of the record being validated.
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_record(array $record, int $index): void {
        // No extra fields to check in activity context; validate common fields directly.
        $this->validate_common_fields($record, $index);
    }

    /**
     * Processes a single record and applies it to a session object.
     *
     * @param array $record The record data to be processed.
     * @param int $index The index of the record in the dataset.
     * @param array $customfieldsbyshortname Custom fields mapped by their short names.
     *
     * @return void
     */
    protected function process_record(array $record, int $index, array $customfieldsbyshortname): void {
        $session = new stdClass();
        $session->facetoface = $this->facetofaceid;

        // Process the session with common logic.
        $this->process_session_record($session, $record, $index, $customfieldsbyshortname);
    }
}
