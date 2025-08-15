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

use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Bulk session manager for site-wide context (site-level bulk upload).
 *
 * @package   mod_facetoface
 */
class bulk_session_manager_sitelevel extends bulk_session_manager_parent {
    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(0);
    }

    /**
     * Rules to validate the loaded CSV records for required fields, types, etc.
     *
     * @return array A list of validation errors.
     */
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
        $shortname = trim($record['Course Shortname']);
        $f2fname = trim($record['Face-to-Face Activity Name']);
        $matched = $this->match_records($shortname, $f2fname);
        $course = $matched['course'];
        $f2frecord = $matched['facetoface'];

        if (!$course) {
            $this->errors[] = [
                $index,
                get_string('error:coursenotfound', 'facetoface', $shortname)];

            return;
        }

        if (!$f2frecord) {
            $this->errors[] = [
                $index,
                get_string(
                    'error:f2fnotfound',
                    'facetoface',
                    (object)[
                        'shortname' => $shortname,
                        'f2fname'   => $f2fname
                    ]
                )
            ];

            return;
        }

        $session = new stdClass();
        $session->facetoface = $f2frecord->id;

        // Use common session creation logic.
        $this->process_session_record($session, $record, $index, $customfieldsbyshortname);
    }

    /**
     * Finds a course and face-to-face activity by shortname and activity name.
     *
     * @param string $courseshortname The shortname of the course.
     * @param string $activityname The name of the Face-to-Face activity.
     * @return array An array with keys 'course' and 'facetoface' (or nulls if not found).
     * @throws \dml_exception
     */
    private function match_records(string $courseshortname, string $activityname): array {
        global $DB;

        $shortnamecondition = $DB->sql_equal(
            'shortname',
            ':shortname',
            false
        );

        $course = $DB->get_record_select(
            'course',
            $shortnamecondition,
            ['shortname' => $courseshortname]
        );

        if (!$course) {
            return [
                'course' => null,
                'facetoface' => null];
        }

        $namecondition = $DB->sql_equal(
            'name',
            ':f2fname',
            false
        );

        $where = $namecondition . ' AND course = :courseid';
        $params = [
            'f2fname' => $activityname,
            'courseid' => $course->id
        ];

        $facetoface = $DB->get_record_select(
            'facetoface',
            $where,
            $params
        );

        return [
            'course' => $course,
            'facetoface' => $facetoface
        ];
    }
}
