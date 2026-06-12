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
 * GCHLOL: Bulk booking created event.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_booking_created extends signup_success {

    /**
     * Trigger the bulk booking event for a booking created from a CSV upload.
     *
     * @param bool $usefile Whether the booking manager is processing an uploaded CSV file.
     * @param \stdClass $facetoface The Face-to-Face activity record.
     * @param \stdClass $session The session record the user was booked into.
     * @param int $relateduserid The ID of the booked user.
     * @return void
     */
    public static function trigger_from_bulk_upload_if_needed(
        bool $usefile,
        \stdClass $facetoface,
        \stdClass $session,
        int $relateduserid
    ): void {
        if (!$usefile) {
            return;
        }

        self::create_from_bulk_upload($facetoface, $session, $relateduserid)->trigger();
    }

    /**
     * Create a bulk booking event from the saved session signup data.
     *
     * @param \stdClass $facetoface The Face-to-Face activity record.
     * @param \stdClass $session The session record the user was booked into.
     * @param int $relateduserid The ID of the booked user.
     * @return self The configured bulk booking event instance.
     */
    public static function create_from_bulk_upload(
        \stdClass $facetoface,
        \stdClass $session,
        int $relateduserid
    ): self {
        $cm = get_coursemodule_from_instance('facetoface', $facetoface->id, $facetoface->course, false, MUST_EXIST);

        $event = self::create([
            'context' => \context_module::instance($cm->id),
            'objectid' => $session->id,
            'relateduserid' => $relateduserid,
            'other' => [
                'statuscode' => self::get_current_signup_statuscode($session->id, $relateduserid),
            ],
        ]);
        $event->add_record_snapshot('facetoface_sessions', $session);
        $event->add_record_snapshot('facetoface', $facetoface);

        return $event;
    }

    /**
     * Get the description of the bulk booking event.
     *
     * @return string The event description.
     */
    public function get_description(): string {
        $statuscode = $this->other['statuscode'] ?? null;
        $status = $statuscode === null ? 'booked' : facetoface_get_status((int) $statuscode);

        return "The user with id '$this->userid' has booked the user with id '$this->relateduserid' "
            . "into session with id '$this->objectid' from a CSV upload with status '$status' "
            . "in the facetoface instance with the course module id '$this->contextinstanceid'.";
    }

    /**
     * Get the localised bulk booking event name.
     *
     * @return string The localised event name.
     */
    public static function get_name(): string {
        return get_string('eventbulkbookingcreated', 'mod_facetoface');
    }

    /**
     * Get the attendees page URL for the booked session.
     *
     * @return \moodle_url The attendees page URL.
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/facetoface/attendees.php', ['s' => $this->objectid]);
    }

    /**
     * Validate the event data for a bulk booking event.
     *
     * @return void
     * @throws \coding_exception If the related user ID or signup status code is missing.
     */
    protected function validate_data(): void {
        parent::validate_data();

        if (!isset($this->data['relateduserid'])) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }

        if (!isset($this->data['other']['statuscode'])) {
            throw new \coding_exception('The \'statuscode\' value must be set in other.');
        }
    }

    /**
     * Get the current saved signup status code for a user's session booking.
     *
     * @param int $sessionid The ID of the session.
     * @param int $userid The ID of the booked user.
     * @return int The current signup status code.
     */
    private static function get_current_signup_statuscode(int $sessionid, int $userid): int {
        global $DB;

        $sql = "SELECT ss.statuscode
                  FROM {facetoface_signups} su
                  JOIN {facetoface_signups_status} ss ON ss.signupid = su.id
                 WHERE su.sessionid = :sessionid
                   AND su.userid = :userid
                   AND ss.superceded = 0";

        return (int) $DB->get_field_sql($sql, ['sessionid' => $sessionid, 'userid' => $userid], MUST_EXIST);
    }
}
