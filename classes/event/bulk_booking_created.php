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

// GCHLOL
/**
 * The mod_facetoface bulk booking created event class.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_booking_created extends signup_success {

    /**
     * Trigger a bulk booking event when the booking came from a CSV upload.
     *
     * @param bool $usefile Whether the booking manager is processing an uploaded file.
     * @param \stdClass $facetoface The Face-to-Face activity.
     * @param \stdClass $session The session the user was booked into.
     * @param int $relateduserid The booked user.
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
     * Create a bulk booking event from a saved signup record.
     *
     * @param \stdClass $facetoface The Face-to-Face activity.
     * @param \stdClass $session The session the user was booked into.
     * @param int $relateduserid The booked user.
     * @return self
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
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description(): string {
        $statuscode = $this->other['statuscode'] ?? null;
        $status = $statuscode === null ? 'booked' : facetoface_get_status((int) $statuscode);

        return "The user with id '$this->userid' has booked the user with id '$this->relateduserid' "
            . "into session with id '$this->objectid' from a CSV upload with status '$status' "
            . "in the facetoface instance with the course module id '$this->contextinstanceid'.";
    }

    /**
     * Return localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventbulkbookingcreated', 'mod_facetoface');
    }

    /**
     * Get URL related to the action.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/facetoface/attendees.php', ['s' => $this->objectid]);
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
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
     * Get the current saved signup status code for a user in a session.
     *
     * @param int $sessionid The session ID.
     * @param int $userid The user ID.
     * @return int
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
// GCHLOL
