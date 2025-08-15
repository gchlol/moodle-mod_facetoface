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
     * Validates a record within the activity context by checking common fields.
     *
     * @param array $record The record data to validate.
     * @param int $index The index of the record being validated.
     *
     * @return void
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
