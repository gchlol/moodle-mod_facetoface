<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_facetoface;

defined('MOODLE_INTERNAL') || die();
global $USER;

/**
 * Test regular and bulk cancellations
 *
 * @package    mod_facetoface
 * @author     Owen Herbert <owenherbert@catalyst-au.net>
 * @copyright  Catalyst IT, 2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_facetoface\cancelsignup
 */
class cancel_signup_test extends \advanced_testcase {
    /**
     * This method runs before every test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Helper method to check if a user is signed up.
     *
     * @param stdClass $session record from the session table.
     * @param stdClass $user record from the user table.
     * @return bool Returns if the user is signed up.
     */
    protected function is_user_signed_up($session, $user) {
        global $DB;

        return $DB->record_exists_sql(
            "SELECT 1
            FROM {facetoface_signups} su
            JOIN {facetoface_signups_status} ss ON su.id = ss.signupid
            WHERE su.sessionid = :sessionid
            AND su.userid = :userid
            AND ss.superceded != 1
            AND ss.statuscode IN (:booked, :approved)",
            [
                'sessionid' => $session->id,
                'userid'    => $user->id,
                'booked'    => MDL_F2F_STATUS_BOOKED,
                'approved'  => MDL_F2F_STATUS_APPROVED,
            ]
        );
    }

    /**
     * Helper method to create a course, a Face-to-Face activity, and several sessions.
     *
     * @param int $futurecount Number of future sessions to create.
     * @param int $pastcount   Number of past sessions to create.
     * @return array Returns an array: [$course, $facetoface, $sessions].
     */
    protected function create_facetoface_with_sessions($futurecount = 2, $pastcount = 1) {
        global $CFG;
        require_once("$CFG->dirroot/mod/facetoface/lib.php");

        // Get plugin generator
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_facetoface');

        // Create a new course.
        $course = $this->getDataGenerator()->create_course();
        $facetoface = $this->getDataGenerator()->create_module('facetoface', ['course' => $course->id]);

        $now = time();
        $sessions = [];

        // Create future sessions (starting tomorrow and onwards).
        for ($i = 0; $i < $futurecount; $i++) {
            $sessions[] = $plugingenerator->create_session([
                'facetoface'  => $facetoface->id,
                'sessiondates' => [
                    [
                        'timestart'   => $now + 86400 * ($i + 1), // Each day ahead
                        'timefinish'  => $now + 86400 * ($i + 1) + 3600, // +1 hour duration
                    ],
                ],
            ]);
        }

        // Create past sessions (starting yesterday and backwards).
        for ($i = 0; $i < $pastcount; $i++) {
            $sessions[] = $plugingenerator->create_session([
                'facetoface'  => $facetoface->id,
                'sessiondates' => [
                    [
                        'timestart'   => $now - 86400 * ($i + 1), // Each day behind
                        'timefinish'  => $now - 86400 * ($i + 1) + 3600, // +1 hour duration
                    ],
                ],
            ]);
        }

        return [$course, $facetoface, $sessions];
    }

    /**
     * Test that bulk cancellation skips sessions that are in the past.
     *
     * Steps:
     * 1. Create future and past sessions.
     * 2. Sign a user up for all sessions.
     * 3. Assert future sessions are cancelled while past ones remain.
     */
    public function test_bulk_cancel_skips_past_sessions() {
        global $CFG;
        require_once("$CFG->dirroot/mod/facetoface/lib.php");

        // Create a course, facetoface activity, and sessions.
        [$course, $facetoface, $sessions] = $this->create_facetoface_with_sessions();

        // Create and log in as a test user.
        $user = $this->getDataGenerator('mod_facetoface')->create_user();
        $this->setUser($user);

        // Sign the user up for every session.
        foreach ($sessions as $session) {
            facetoface_user_signup($session, $facetoface, $course, '', MDL_F2F_TEXT, MDL_F2F_STATUS_BOOKED);
            $this->assertTrue(
                $this->is_user_signed_up($session, $user),
                'User should be successfully signed up to session'
            );
        }

        // Perform cancellation
        facetoface_user_cancel_bulk($facetoface->id, $user->id);

        // Assert: Future sessions are cancelled, past sessions remain.
        $now = time();
        foreach ($sessions as $session) {
            if ($session->sessiondates[0]->timestart > $now) {
                $this->assertFalse(
                    $this->is_user_signed_up($session, $user),
                    'Future session should be cancelled'
                );
            } else {
                $this->assertTrue(
                    $this->is_user_signed_up($session, $user),
                    'Past session should remain signed up'
                );
            }
        }
    }

    /**
     * Test that all future sessions for a user can be cancelled,
     * while past sessions remain unaffected.
     *
     * Steps:
     * 1. Create a Face-to-Face activity with both future and past sessions.
     * 2. Sign a user up for all sessions.
     * 3. Verify that only future sessions are cancelled.
     */
    public function test_cancel_all_future_sessions() {
        global $CFG;
        require_once("$CFG->dirroot/mod/facetoface/lib.php");

        // Create course, Face-to-Face instance, and sessions.
        [$course, $facetoface, $sessions] = $this->create_facetoface_with_sessions();

        // Create and log in as a test user.
        $user = $this->getDataGenerator('mod_facetoface')->create_user();
        $this->setUser($user);

        // Sign the user up for all sessions (both past and future).
        foreach ($sessions as $session) {
            facetoface_user_signup($session, $facetoface, $course, '', MDL_F2F_TEXT, MDL_F2F_STATUS_BOOKED);
            $this->assertTrue(
                $this->is_user_signed_up($session, $user),
                'User should initially be signed up for all sessions.'
            );
        }

        // Perform cancellation
        facetoface_user_cancel_bulk($facetoface->id, $user->id);

        // Assert: Future sessions should be cancelled; past sessions remain active.
        $now = time();
        foreach ($sessions as $session) {
            if ($session->sessiondates[0]->timestart > $now) {
                $this->assertFalse(
                    $this->is_user_signed_up($session, $user),
                    'Future session should be cancelled.'
                );
            } else {
                $this->assertTrue(
                    $this->is_user_signed_up($session, $user),
                    'Past session should remain signed up.'
                );
            }
        }
    }

    /**
     * Test that a user can successfully cancel a single session signup.
     *
     * Steps:
     * 1. Create a Face-to-Face activity with multiple sessions.
     * 2. Sign a user up for one session.
     * 3. Cancel that session.
     * 4. Verify that the user is no longer signed up.
     */
    public function test_cancel_single_session() {
        global $CFG;
        require_once("$CFG->dirroot/mod/facetoface/lib.php");

        // Create a course, Face-to-Face instance, and a set of sessions.
        [$course, $facetoface, $sessions] = $this->create_facetoface_with_sessions();

        // Create and log in as a test user.
        $user = $this->getDataGenerator('mod_facetoface')->create_user();
        $this->setUser($user);

        // Select a single session to work with (the first in the array).
        $session = reset($sessions);

        // Sign the user up for the session.
        facetoface_user_signup($session, $facetoface, $course, '', MDL_F2F_TEXT, MDL_F2F_STATUS_BOOKED);

        // Assert: User should now be signed up.
        $this->assertTrue(
            $this->is_user_signed_up($session, $user),
            'User should be signed up for the selected session.'
        );

        // Perform cancellation
        facetoface_user_cancel_bulk($facetoface->id, $user->id);

        // Assert: User should no longer be signed up after cancellation.
        $this->assertFalse(
            $this->is_user_signed_up($session, $user),
            'User should be cancelled from the selected session.'
        );
    }
}
