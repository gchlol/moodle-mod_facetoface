<?php
// classes/bulk_session_manager_activitylevel.php
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
