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

namespace mod_facetoface\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/webservice/tests/helpers.php');

use externallib_advanced_testcase;
use context_system;

/**
 * Tests for Face-to-Face
 *
 * @package    mod_facetoface
 * @copyright  Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_session_test extends \externallib_advanced_testcase {
    /**
     * Setup.
     */
    protected function setUp(): void {
        $this->resetAfterTest(true);
    }

    /**
     * Test create session webservice.
     *
     * @return void
     */
    public function test_execute_creates_session() {
        global $DB;

        // Create a course and a facetoface activity.
        $course = $this->getDataGenerator()->create_course();
        $facetoface = $this->getDataGenerator()->create_module('facetoface', ['course' => $course->id]);

        // Create a custom field.
        $cf = new \stdClass();
        $cf->name = 'Custom field1';
        $cf->shortname = 'fieldymcfield';
        $cf->type = CUSTOMFIELD_TYPE_TEXT;
        $cf->defaultvalue = '';
        $cf->required = 0;
        $cf->isfilter = 1;
        $cf->showinsummary = 1;
        $customfieldid = $DB->insert_record('facetoface_session_field', $cf);

        // Assign the teacher role to the test user so they have edit capability.
        $managerroleid = $DB->get_field('role', 'id', array('shortname' => 'manager'));
        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();
        role_assign($managerroleid, $user->id, $context->id);

        $this->setUser($user);

        // Prepare parameters for session creation.
        $params = [
            'facetofaceid' => $facetoface->id,
            'details' => 'Test session details',
            'capacity' => 10,
            'allowoverbook' => 1,
            'datetimeknown' => 1,
            'duration' => 60,
            'normalcost' => 100,
            'discountcost' => 80,
            'allowcancellations' => 1,
            'sessiondates' => [
                [
                    'timestart' => time() + 3600,
                    'timeend' => time() + 7200,
                ],
            ],
            'customfields' => [
                [
                    'shortname' => 'fieldymcfield',
                    'value' => 'Boaty McBoatface',
                ],
            ],
        ];

        // Call the web service function.
        $result = create_session::execute(
            $params['facetofaceid'],
            $params['details'],
            $params['capacity'],
            $params['allowoverbook'],
            $params['datetimeknown'],
            $params['duration'],
            $params['normalcost'],
            $params['discountcost'],
            $params['allowcancellations'],
            $params['sessiondates'],
            $params['customfields']
        );

        // Check that a session was created.
        $this->assertArrayHasKey('sessionid', $result);
        $sessionid = $result['sessionid'];
        $session = $DB->get_record('facetoface_sessions', ['id' => $sessionid]);
        $this->assertNotEmpty($session);

        // Check that the session fields match input.
        $this->assertEquals($params['facetofaceid'], $session->facetoface);
        $this->assertEquals($params['capacity'], $session->capacity);
        $this->assertEquals($params['details'], $session->details);
        $this->assertEquals($params['duration'], $session->duration);
        $this->assertEquals($params['normalcost'], $session->normalcost);
        $this->assertEquals($params['discountcost'], $session->discountcost);
        $this->assertEquals($params['allowcancellations'], $session->allowcancellations);

        // Check that there is a session date record.
        $dates = $DB->get_records('facetoface_sessions_dates', ['sessionid' => $sessionid]);
        $this->assertCount(1, $dates);
        $date = reset($dates);
        $this->assertEquals($params['sessiondates'][0]['timestart'], $date->timestart);
        $this->assertEquals($params['sessiondates'][0]['timeend'], $date->timefinish);

        // Check that the custom field was saved.
        $customfield = $DB->get_record('facetoface_session_data', [
            'sessionid' => $sessionid,
            'fieldid' => $customfieldid,
        ]);
        $this->assertNotEmpty($customfield);
        $this->assertEquals('Boaty McBoatface', $customfield->data);
    }
}
