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
 * A confirmation email was sent to session attendees.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class confirmation_sent extends email_sent {
    /** @var string The attendee signed up using the GUI. */
    public const SOURCE_SELF_SIGN_UP = 'self_sign_up';

    /** @var string An attendee was booked by another user using the GUI. */
    public const SOURCE_BOOK_OTHERS = 'book_others';

    /** @var string An attendee was booked using a CSV upload. */
    public const SOURCE_BOOK_VIA_UPLOAD = 'book_via_upload';

    /**
     * Describe the session, booking source and all emailed attendees.
     *
     * @return string The event description.
     */
    public function get_description(): string {
        $userids = $this->get_userids_description();
        $sources = [
            self::SOURCE_SELF_SIGN_UP => 'self sign-up via the GUI',
            self::SOURCE_BOOK_OTHERS => 'booking others via the GUI',
            self::SOURCE_BOOK_VIA_UPLOAD => 'CSV upload',
        ];
        $source = $sources[$this->other['source']];

        $description = "A confirmation email was sent to attendees with user ids '$userids' following $source for session ";
        $description .= "with id '$this->objectid' in the facetoface instance ";
        $description .= "with the course module id '$this->contextinstanceid'.";

        return $description;
    }

    /**
     * Get the localised event name.
     *
     * @return string The event name.
     */
    public static function get_name(): string {
        return get_string('eventconfirmationsent', 'mod_facetoface');
    }

    /**
     * Validate the confirmation booking source.
     *
     * @return void
     * @throws \coding_exception If the booking source is missing or unsupported.
     */
    protected function validate_data(): void {
        parent::validate_data();

        $sources = [ self::SOURCE_SELF_SIGN_UP, self::SOURCE_BOOK_OTHERS, self::SOURCE_BOOK_VIA_UPLOAD ];
        if (!in_array($this->other['source'] ?? null, $sources, true)) {
            throw new \coding_exception('A supported confirmation booking source must be set in other.');
        }
    }
}
