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
 * The mod_facetoface CSV bulk session processed event.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_facetoface\event;
use moodle_url;
use coding_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Event triggered when bulk sessions CSV is processed at activity level.
 *
 * @package   mod_facetoface
 */
class csv_processed_bulksession_activitylevel extends csv_processed_bulksession_parent {

    /**
     * Init method.
     *
     * @return void
     */
    protected function init(): void {
        parent::init();
        $this->data['objecttable'] = 'facetoface';
    }

    /**
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description(): string {
        return get_string('eventcsvprocessedbulksessiondesc', 'mod_facetoface', [
            'userid'            => $this->userid,
            'contextinstanceid' => $this->contextinstanceid
        ]);
    }

    /**
     * Return localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventcsvprocessedbulksession', 'mod_facetoface');
    }

    /**
     * Get URL related to the action
     *
     * @return moodle_url
     */
    public function get_url(): moodle_url {
        return new moodle_url('/mod/facetoface/uploadbulksessions_activitylevel.php', ['f2fid' => $this->objectid]);
    }

    /**
     * Custom validation.
     *
     * @return void
     */
    protected function validate_data(): void {
        parent::validate_data();
        if ($this->contextlevel != CONTEXT_MODULE) {
            throw new coding_exception('Context level must be CONTEXT_MODULE.');
        }
    }
}
