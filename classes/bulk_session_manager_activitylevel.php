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

use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Bulk session manager for activity context (course-level Face-to-Face).
 * @package   mod_facetoface
 */
class bulk_session_manager_activitylevel extends bulk_session_manager_parent {
    /**
     * Constructor requires a Face-to-Face instance ID.
     * @param int $facetofaceid Face-to-Face activity ID.
     */
    public function __construct(int $facetofaceid) {
        parent::__construct($facetofaceid);
    }

    public static function get_headers(): array {
        // Use base headers defined in parent (no course/activity columns needed).
        return parent::get_headers();
    }

    protected function get_base_url(): moodle_url {
        // Error redirect URL for activity context includes facetoface id.
        return new moodle_url('/mod/facetoface/uploadbulksessions.php', ['f2fid' => $this->facetofaceid]);
    }

    protected function validate_record(array $record, int $index): void {
        // No extra fields to check in activity context; validate common fields directly.
        $this->validate_common_fields($record, $index);
    }

    protected function process_record(array $record, int $index, array $customfieldsbyshortname): void {
        // Prepare session object with known Face-to-Face ID for this course.
        $session = new stdClass();
        $session->facetoface = $this->facetofaceid;
        // Process the session with common logic.
        $this->process_session_record($session, $record, $index, $customfieldsbyshortname);
    }
}
