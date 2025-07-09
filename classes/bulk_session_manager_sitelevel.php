<?php
/**
 * Manages bulk session creation for Face-to-Face module sitewide.
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
 * Bulk session manager for site-wide context (admin-level bulk upload).
 * @package   mod_facetoface
 */
class bulk_session_manager_sitelevel extends bulk_session_manager_parent {
    public function __construct() {
        parent::__construct(0);
    }

    public static function get_headers(): array {
        // Prepend course and activity name columns to the base headers.
        $base = parent::get_headers();
        return array_merge(['Course Shortname', 'Face-to-Face Activity Name'], $base);
    }

    protected function get_base_url(): moodle_url {
        // Error redirect URL for site context (no specific parameters).
        return new moodle_url('/mod/facetoface/sitebulkupload.php');
    }

    protected function validate_record(array $record, int $index): void {
        // Check required course and activity identifiers.
        $shortname = $record['Course Shortname'] ?? '';
        $activity  = $record['Face-to-Face Activity Name'] ?? '';
        if (empty($shortname)) {
            $this->errors[] = [$index, get_string('error:missingcourseshortname', 'facetoface')];
            return;
        }
        if (empty($activity)) {
            $this->errors[] = [$index, get_string('error:missingf2fname', 'facetoface')];
            return;
        }
        // Verify that the specified course and activity exist.
        $match = $this->match_records($shortname, $activity);
        if (!$match['course']) {
            $this->errors[] = [$index, get_string('error:coursenotfound', 'facetoface', $shortname)];
            return;
        }
        if (!$match['facetoface']) {
            $params = (object)['shortname' => $shortname, 'f2fname' => $activity];
            $this->errors[] = [$index, get_string('error:f2fnotfound', 'facetoface', $params)];
            return;
        }
        // Perform common field validation after ensuring course/activity are valid.
        $this->validate_common_fields($record, $index);
    }

    protected function process_record(array $record, int $index, array $customfieldsbyshortname): void {
        // Find the course and facetoface for this record.
        $shortname = trim($record['Course Shortname'] ?? '');
        $activity  = trim($record['Face-to-Face Activity Name'] ?? '');
        $match = $this->match_records($shortname, $activity);
        if (!$match['course']) {
            $this->errors[] = [$index, get_string('error:coursenotfound', 'facetoface', $shortname)];
            return;
        }
        if (!$match['facetoface']) {
            $params = (object)['shortname' => $shortname, 'f2fname' => $activity];
            $this->errors[] = [$index, get_string('error:f2fnotfound', 'facetoface', $params)];
            return;
        }
        // Prepare session with matched Face-to-Face instance.
        $session = new stdClass();
        $session->facetoface = $match['facetoface']->id;
        // Use common session creation logic.
        $this->process_session_record($session, $record, $index, $customfieldsbyshortname);
    }

    /**
     * Helper to find a course and Face-to-Face activity by short names.
     * @param string $courseshort The course shortname.
     * @param string $activityname The Face-to-Face activity name.
     * @return array ['course' => course record or null, 'facetoface' => facetoface record or null]
     */
    private function match_records(string $courseshort, string $activityname): array {
        global $DB;
        $course = $DB->get_record('course', ['shortname' => $courseshort], '*', IGNORE_MULTIPLE);
        if (!$course) {
            return ['course' => null, 'facetoface' => null];
        }
        $facetoface = $DB->get_record('facetoface', ['course' => $course->id, 'name' => $activityname], '*', IGNORE_MULTIPLE);
        return ['course' => $course, 'facetoface' => $facetoface];
    }
}
