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

namespace mod_facetoface;

global $CFG;
require_once($CFG->dirroot.'/mod/facetoface/lib.php'); 

/**
 * Test calendar event related functions.
 *
 * @package    mod_facetoface
 * @author     Djarran Cotleanu <djarrancotleanu@catalyst-au.net>
 * @copyright  Catalyst IT, 2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendar_event_test extends \advanced_testcase {

    /**
     * This method runs before every test.
     */
    public function setUp(): void {
        $this->resetAfterTest();
    }

    /**
     * Data provider for calendar settings.
    *
    * @return array
    */
    public function calendar_settings_provider() {
        return [
            'No calendar events, no user events' => [F2F_CAL_NONE, 0],
            'No calendar events, with user events' => [F2F_CAL_NONE, 1],
            'Course calendar events, no user events' => [F2F_CAL_COURSE, 0],
            'Course calendar events, with user events' => [F2F_CAL_COURSE, 1],
            'Site calendar events, no user events' => [F2F_CAL_SITE, 0],
            'Site calendar events, with user events' => [F2F_CAL_SITE, 1],
        ];
    }

    /**
     * Test Facetoface operations with different calendar settings, checking
     * that events are created and deleted as expected when updating the
     * instance and sessions.
     *
     * @dataProvider calendar_settings_provider
     */
    public function test_calendar_events_on_instance_session_update($showoncalendar, $usercalentry) {
        global $DB;

        /** @var \mod_facetoface_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_facetoface');

        $course = $this->getDataGenerator()->create_course();
        $facetoface = $generator->create_instance([
            'course' => $course->id,
            'showoncalendar' => $showoncalendar,
            'usercalentry' => $usercalentry,
        ]);
        $users = [
            $this->getDataGenerator()->create_and_enrol($course, 'student'),
            $this->getDataGenerator()->create_and_enrol($course, 'student'),
            $this->getDataGenerator()->create_and_enrol($course, 'student')
        ];

        $now = time();
        $sessions = [];
        $sessions[] = $generator->create_session([
            'facetoface' => $facetoface->id,
            'sessiondates' => [
                ['timestart' => $now + 3 * DAYSECS, 'timefinish' => $now + 4 * DAYSECS],
            ],
        ]);
        $sessions[] = $generator->create_session([
            'facetoface' => $facetoface->id,
            'sessiondates' => [
                ['timestart' => $now + 4 * DAYSECS, 'timefinish' => $now + 5 * DAYSECS],
            ],
        ]);

        foreach ($users as $user) {
            foreach ($sessions as $session) {
                facetoface_user_signup(
                    $session,
                    $facetoface,
                    $course,
                    '',
                    MDL_F2F_TEXT,
                    MDL_F2F_STATUS_BOOKED,
                    $user->id);
            }
        }

        $sessiononcalendar = $showoncalendar != F2F_CAL_NONE;
        $expectedsessionevents = 0;
        if ($sessiononcalendar) {
            $expectedsessionevents = count($sessions);
        } 

        $expectedbookingevents = 0;
        if ($usercalentry) {
            $expectedbookingevents = count($users) * count($sessions);
        }

        // Check that the expected events are created.
        $this->assert_events_count('session', $expectedsessionevents);
        $this->assert_events_count('booking', $expectedbookingevents);

        // Check that event counts are unchanged when updating the instance with no changes.
        facetoface_update_instance($facetoface, false);
        $this->assert_events_count('session', $expectedsessionevents);
        $this->assert_events_count('booking', $expectedbookingevents);

        // Check that event counts are unchanged when updating a session with no changes.
        $sessiondate = $DB->get_records('facetoface_sessions_dates', ['sessionid' => $sessions[0]->id]);
        facetoface_update_session($sessions[0], $sessiondate);
        $this->assert_events_count('session', $expectedsessionevents);
        $this->assert_events_count('booking', $expectedbookingevents);

        // Remove session datetimeknown, update session.
        $sessions[0]->datetimeknown = "0";
        facetoface_update_session($sessions[0], $sessiondate);

        if ($sessiononcalendar) {
            // Check that the updated session's session events are removed.
            $this->assert_events_count('session', 0, $sessions[0]->id);

            // Check that the other session's session events are unaffected.
            $this->assert_events_count('session', 1, $sessions[1]->id);
        }

        if ($usercalentry) {
            // Check that the updated session's booking events are removed.
            $this->assert_events_count('booking', 0, $sessions[0]->id);

            // Check that the other session's events are unaffected.
            $this->assert_events_count('booking', count($users), $sessions[1]->id);
        }

        // Check that disabling usercalentry removes all events if already enabled.
        if ($usercalentry) {
            $facetoface->usercalentry = "0";
            facetoface_update_instance($facetoface, false);
            $this->assert_events_count('booking', 0);
        }
    }

    /**
     * Assert that counts for eventtype and sessionid match expected value.
     *
     * @param string $eventtype 'booking' or 'session'
     * @param int $expectedcount the expected count
     * @param string $sessionid optional, if passed will only retrieve events for this session
     */
    private function assert_events_count($eventtype, $expectedcount, $sessionid = ''): void {
        global $DB;

        $where = ['eventtype' => "facetoface{$eventtype}"];
        if ($sessionid) {
            $where['uuid'] = $sessionid;
        }
        $actualevents = $DB->get_records('event', $where);

        $this->assertCount($expectedcount, $actualevents);
    }
}
