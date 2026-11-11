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

/**
 * A reminder email was sent to session attendees.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reminder_sent extends email_sent {
    /**
     * Describe the session and all attendees who received the email.
     *
     * @return string The event description.
     */
    public function get_description(): string {
        $userids = $this->get_userids_description();

        $description = "A reminder email was sent to attendees with user ids '$userids' for session with id ";
        $description .= "'$this->objectid' in the facetoface instance with the course module id '$this->contextinstanceid'.";

        return $description;
    }

    /**
     * Get the localised event name.
     *
     * @return string The event name.
     */
    public static function get_name(): string {
        return get_string('eventremindersent', 'mod_facetoface');
    }
}
