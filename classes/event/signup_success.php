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
 * Copyright (C) 2007-2011 Catalyst IT (http://www.catalyst.net.nz)
 * Copyright (C) 2011-2013 Totara LMS (http://www.totaralms.com)
 * Copyright (C) 2014 onwards Catalyst IT (http://www.catalyst-eu.net)
 *
 * @package    mod
 * @subpackage facetoface
 * @copyright  2014 onwards Catalyst IT <http://www.catalyst-eu.net>
 * @author     Stacey Walker <stacey@catalyst-eu.net>
 */

namespace mod_facetoface\event;

/**
 * The mod_facetoface signup event class.
 *
 * @package    mod_facetoface
 * @since      Moodle 2.7
 * @copyright  2014 onwards Catalyst IT <http://www.catalyst-eu.net>
 * @author     Stacey Walker <stacey@catalyst-eu.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signup_success extends \core\event\base {

    /**
     * Create a signup success event for a session booking.
     *
     * @param \context_module $contextmodule The module context.
     * @param \stdClass $session The session record.
     * @param \stdClass $facetoface The facetoface activity record.
     * @param int $relateduserid The user who was booked, when different from the actor.
     * @return self
     */
    public static function create_from_signup(
        \context_module $contextmodule,
        \stdClass $session,
        \stdClass $facetoface,
        int $relateduserid = 0
    ) {
        $params = [
            'context' => $contextmodule,
            'objectid' => $session->id,
        ];

        if (!empty($relateduserid)) {
            $params['relateduserid'] = $relateduserid;
        }

        $event = self::create($params);
        $event->add_record_snapshot('facetoface_sessions', $session);
        $event->add_record_snapshot('facetoface', $facetoface);

        return $event;
    }

    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'facetoface_sessions';
    }

    /**
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description() {
        if (!empty($this->relateduserid) && ((int) $this->relateduserid !== (int) $this->userid)) {
            return "The user with id '$this->userid' has booked user with id '$this->relateduserid' into session " .
                "with id '$this->objectid' in the facetoface instance with the course module id " .
                "'$this->contextinstanceid'.";
        }

        return "The user with id '$this->userid' has signed up for session with id '$this->objectid' in the " .
            "facetoface instance with the course module id '$this->contextinstanceid'.";
    }

    /**
     * Return localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventsignup', 'mod_facetoface');
    }

    /**
     * Get URL related to the action
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/facetoface/signup.php', ['s' => $this->objectid]);
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();

        if ($this->contextlevel != CONTEXT_MODULE) {
            throw new \coding_exception('Context level must be CONTEXT_MODULE.');
        }
    }
}
