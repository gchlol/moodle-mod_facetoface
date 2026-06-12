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

namespace mod_facetoface\local;

// GCHLOL

/**
 * Helper for signup success logging customisations.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signup_success_helper {
    /**
     * Trigger the signup success event for booking another user into a session.
     *
     * @param int $cmid The course module id.
     * @param \stdClass $session The session record.
     * @param \stdClass $facetoface The facetoface activity record.
     * @param int $relateduserid The booked user id.
     * @return void This method does not return a value.
     */
    public static function trigger_booking_event(
        int $cmid,
        \stdClass $session,
        \stdClass $facetoface,
        int $relateduserid
    ): void {
        $event = \mod_facetoface\event\signup_success::create([
            'context' => \context_module::instance($cmid),
            'objectid' => $session->id,
            'relateduserid' => $relateduserid,
        ]);
        $event->add_record_snapshot('facetoface_sessions', $session);
        $event->add_record_snapshot('facetoface', $facetoface);
        $event->trigger();
    }

    /**
     * Return the custom event description when one user books another.
     *
     * @param int $userid The actor user id.
     * @param int $relateduserid The booked user id.
     * @param int $objectid The session id.
     * @param int $contextinstanceid The course module id.
     * @return string|null The custom booking description, or null when the user books themselves.
     */
    public static function get_booking_description(
        int $userid,
        int $relateduserid,
        int $objectid,
        int $contextinstanceid
    ): ?string {
        if (empty($relateduserid) || $relateduserid === $userid) {
            return null;
        }

        return "The user with id '$userid' has booked user with id '$relateduserid' into session with id " .
            "'$objectid' in the facetoface instance with the course module id '$contextinstanceid'.";
    }
}

// GCHLOL
