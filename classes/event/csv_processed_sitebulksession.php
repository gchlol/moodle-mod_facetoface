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
 * The mod_facetoface CSV bulk session processed event.
 *
 * @package     mod_facetoface
 * @copyright   2025 Gold Coast Health
 * @author        Jonas Sajonas
 * @license        https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class csv_processed_sitebulksession extends base {


    /**
     * Ini method.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objectatable'] = '';
    }

    /**
     * Returns name of the event.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventcsvprocessedsitebulksession, mod_facetoface');
    }

    /**
     * Returns description of what happened.
     *
     * @returns string
     */
    public function get_description(): string {
        return get_string(
            'eventcsvprocessedsitebulksessiondesc',
            'mod_facetoface',
            $this->userid
        );
    }


    /**
     * Get URL related to the action
     *
     * @return moodle_url
     */
    public function get_url(): moodle_url {
        return new moodle_url('/mod/facetoface/sitebulkupload.php');
    }

    /**
     * Custom validation
     *
     * @throws coding_exception
     * @return void
     */
    protected function validate_date(): void {
        parent::validate_data();
    }
}
