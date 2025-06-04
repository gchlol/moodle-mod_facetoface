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

namespace mod_facetoface\event;
use moodle_url;
use coding_exception;
use core\event\base;

/**
 * The mod_facetoface CSV bulk bookings processed event.
 *
 * @package     mod_facetoface
 * @copyright   2025 Gold Coast Health
 * @author      Jonas Sajonas
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class csv_processed_bulkattendance extends base {

    /**
     * Ini method.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'facetoface';
    }

    /**
     * Returns name of the event.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventcsvprocessedbulkattendance', 'mod_facetoface');
    }

    /**
     * Returns description of what happened.
     *
     * @returns string
     */
    public function get_description(): string {
        return get_string(
            'eventcsvprocessedbulkattendancedesc',
            'mod_facetoface',
            (object)['userid' => $this->userid]
        );
    }


    /**
     * Get URL related to the action
     *
     * @return moodle_url
     */
    public function get_url(): moodle_url {
        return new moodle_url('/mod/facetoface/uploadbulkattendance.php');
    }

    /**
     * Custom validation
     *
     * @throws coding_exception
     * @return void
     */
    protected function validate_data(): void {
        parent::validate_data();

        // Ensure we always have an objectid (even if 0).
        if (!isset($this->data['objectid'])) {
            throw new coding_exception('The \'objectid\' must be set for csv_processed_bulkattendance event.');
        }
    }
}
