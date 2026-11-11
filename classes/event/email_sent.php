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
 * Shared metadata and validation for the logs of emails sent to, or suppressed for, session attendees.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class email_sent extends \core\event\base {
    /**
     * Initialise the event metadata.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'facetoface_sessions';
    }

    /**
     * Get the attendees page for the session.
     *
     * @return \moodle_url The session attendees URL.
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/facetoface/attendees.php', [ 's' => $this->objectid ]);
    }

    /**
     * Describe the logged attendees, including logs restored without user mappings.
     *
     * @return string The attendee IDs or an explanation when they are unavailable.
     */
    protected function get_userids_description(): string {
        return is_array($this->other['userids']) ? implode(', ', $this->other['userids']) : 'unavailable after restore';
    }

    /**
     * Validate the logged attendees and module context.
     *
     * @return void
     * @throws \coding_exception If the module context or attendee IDs are invalid.
     */
    protected function validate_data(): void {
        parent::validate_data();

        if ($this->contextlevel != CONTEXT_MODULE) {
            throw new \coding_exception('Context level must be CONTEXT_MODULE.');
        }

        if (
            empty($this->other['userids']) ||
            !is_array($this->other['userids'])
        ) {
            throw new \coding_exception('A nonempty array of attendee user IDs must be set in other.');
        }

        foreach ($this->other['userids'] as $userid) {
            if (
                !is_int($userid) ||
                $userid <= 0
            ) {
                throw new \coding_exception('Attendee user IDs must be positive integers.');
            }
        }
    }

    /**
     * Map the session ID when course logs are restored.
     *
     * @return array{db: string, restore: string} The session restore mapping.
     */
    public static function get_objectid_mapping(): array {
        return [ 'db' => 'facetoface_sessions', 'restore' => 'facetoface_session' ];
    }

    /**
     * Declare the attendee ID list as unsupported by the scalar restore mappings.
     *
     * @return array{userids: int} The other data restore mappings.
     */
    public static function get_other_mapping(): array {
        return [ 'userids' => self::NOT_MAPPED ];
    }
}
