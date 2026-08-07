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

use context_module;
use context_system;
use mod_facetoface\event\add_session;
use mod_facetoface\event\csv_processed_bulksession_activitylevel;
use mod_facetoface\event\csv_processed_bulksession_sitelevel;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/facetoface/uploadbulksessions_common.php');

/**
 * Tests for records snapshotted by bulk session upload events.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bulk_session_event_test extends \advanced_testcase {
    /**
     * Set up each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Activity-level uploads log and snapshot every created session and their activity.
     */
    public function test_activity_level_upload_logs_and_snapshots_created_sessions(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $facetoface = $this->getDataGenerator()->create_module('facetoface', ['course' => $course->id]);
        $facetoface = $DB->get_record('facetoface', ['id' => $facetoface->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('facetoface', $facetoface->id, $course->id, false, MUST_EXIST);

        $manager = new bulk_session_manager_activitylevel((int) $facetoface->id);
        $this->set_manager_records($manager, [
            $this->make_record('First uploaded session', '01/01/2030'),
            $this->make_record('Second uploaded session', '02/01/2030'),
        ]);

        $this->assertEmpty($manager->validate());
        $this->assertTrue($manager->process());
        $sessions = $manager->get_created_sessions();
        $this->assertCount(2, $sessions);

        $event = csv_processed_bulksession_activitylevel::create([
            'context' => context_module::instance((int) $cm->id),
            'objectid' => $facetoface->id,
        ]);
        $sink = $this->redirectEvents();
        trigger_bulk_session_created_events($manager);
        add_bulk_session_event_snapshots($event, $manager, $facetoface);
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();

        $sessionevents = array_values(array_filter($events, fn($event) => $event instanceof add_session));
        $summaryevents = array_values(array_filter(
            $events,
            fn($event) => $event instanceof csv_processed_bulksession_activitylevel
        ));
        $this->assertCount(3, $events);
        $this->assertCount(2, $sessionevents);
        $this->assertCount(1, $summaryevents);
        $this->assertSame(array_map('intval', array_keys($sessions)), array_map(
            fn($event) => (int) $event->objectid,
            $sessionevents
        ));
        $this->assertInstanceOf(csv_processed_bulksession_activitylevel::class, end($events));

        $DB->set_field('facetoface', 'name', 'Changed activity name', ['id' => $facetoface->id]);
        foreach ($sessions as $session) {
            $this->assertSame((int) $facetoface->id, (int) $session->facetoface);
            $DB->set_field('facetoface_sessions', 'details', 'Changed session details', ['id' => $session->id]);
            $snapshot = $event->get_record_snapshot('facetoface_sessions', $session->id);
            $this->assertEquals((array) $session, (array) $snapshot);
        }

        $facetofacesnapshot = $event->get_record_snapshot('facetoface', $facetoface->id);
        $this->assertSame($facetoface->name, $facetofacesnapshot->name);

        foreach ($sessionevents as $sessionevent) {
            $session = $sessions[$sessionevent->objectid];
            $this->assertSame('facetoface_sessions', $sessionevent->objecttable);
            $this->assertSame((int) $course->id, (int) $sessionevent->courseid);
            $this->assertSame((int) $cm->id, (int) $sessionevent->contextinstanceid);
            $sessionsnapshot = $sessionevent->get_record_snapshot('facetoface_sessions', $session->id);
            $this->assertEquals((array) $session, (array) $sessionsnapshot);
            $facetofacesnapshot = $sessionevent->get_record_snapshot('facetoface', $facetoface->id);
            $this->assertEquals((array) $facetoface, (array) $facetofacesnapshot);
        }

        $this->assertDebuggingNotCalled();
    }

    /**
     * Site-level uploads log and snapshot sessions in their own activity contexts.
     */
    public function test_site_level_upload_logs_and_snapshots_created_sessions(): void {
        global $DB;

        $courseone = $this->getDataGenerator()->create_course(['shortname' => 'COURSE_ONE']);
        $coursetwo = $this->getDataGenerator()->create_course(['shortname' => 'COURSE_TWO']);
        $facetofaceone = $this->getDataGenerator()->create_module('facetoface', [
            'course' => $courseone->id,
            'name' => 'First activity',
        ]);
        $facetofacetwo = $this->getDataGenerator()->create_module('facetoface', [
            'course' => $coursetwo->id,
            'name' => 'Second activity',
        ]);

        $manager = new bulk_session_manager_sitelevel();
        $recordone = $this->make_record('First site upload session', '03/01/2030');
        $recordone['Course Shortname'] = $courseone->shortname;
        $recordone['Face-to-Face Activity Name'] = $facetofaceone->name;
        $recordtwo = $this->make_record('Second site upload session', '04/01/2030');
        $recordtwo['Course Shortname'] = $coursetwo->shortname;
        $recordtwo['Face-to-Face Activity Name'] = $facetofacetwo->name;
        $recordthree = $this->make_record('Third site upload session', '05/01/2030');
        $recordthree['Course Shortname'] = $courseone->shortname;
        $recordthree['Face-to-Face Activity Name'] = $facetofaceone->name;
        $this->set_manager_records($manager, [$recordone, $recordtwo, $recordthree]);

        $this->assertEmpty($manager->validate());
        $this->assertTrue($manager->process());
        $sessions = $manager->get_created_sessions();
        $this->assertCount(3, $sessions);
        $actualfacetofaceids = array_map(fn($session) => (int) $session->facetoface, $sessions);
        $expectedfacetofaceids = [
            (int) $facetofaceone->id,
            (int) $facetofacetwo->id,
            (int) $facetofaceone->id,
        ];
        sort($actualfacetofaceids);
        sort($expectedfacetofaceids);
        $this->assertSame($expectedfacetofaceids, $actualfacetofaceids);

        $event = csv_processed_bulksession_sitelevel::create([
            'context' => context_system::instance(),
            'objectid' => 0,
        ]);
        $sink = $this->redirectEvents();
        trigger_bulk_session_created_events($manager);
        add_bulk_session_event_snapshots($event, $manager, null);
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();

        $sessionevents = array_values(array_filter($events, fn($event) => $event instanceof add_session));
        $summaryevents = array_values(array_filter(
            $events,
            fn($event) => $event instanceof csv_processed_bulksession_sitelevel
        ));
        $this->assertCount(4, $events);
        $this->assertCount(3, $sessionevents);
        $this->assertCount(1, $summaryevents);
        $this->assertSame(array_map('intval', array_keys($sessions)), array_map(
            fn($event) => (int) $event->objectid,
            $sessionevents
        ));
        $this->assertInstanceOf(csv_processed_bulksession_sitelevel::class, end($events));
        $summaryevent = reset($summaryevents);
        $this->assertSame(0, (int) $summaryevent->objectid);
        $this->assertSame(0, (int) $summaryevent->courseid);
        $this->assertSame(context_system::instance()->id, $summaryevent->contextid);

        $facetofaces = [
            (int) $facetofaceone->id => $DB->get_record(
                'facetoface',
                ['id' => $facetofaceone->id],
                '*',
                MUST_EXIST
            ),
            (int) $facetofacetwo->id => $DB->get_record(
                'facetoface',
                ['id' => $facetofacetwo->id],
                '*',
                MUST_EXIST
            ),
        ];
        $contextinstanceids = [];
        foreach ($facetofaces as $facetofaceid => $facetoface) {
            $cm = get_coursemodule_from_instance(
                'facetoface',
                $facetofaceid,
                $facetoface->course,
                false,
                MUST_EXIST
            );
            $contextinstanceids[$facetofaceid] = (int) $cm->id;
            $DB->set_field('facetoface', 'name', 'Changed activity name', ['id' => $facetofaceid]);
        }

        foreach ($sessions as $session) {
            $DB->set_field('facetoface_sessions', 'details', 'Changed session details', ['id' => $session->id]);
            $snapshot = $event->get_record_snapshot('facetoface_sessions', $session->id);
            $this->assertEquals((array) $session, (array) $snapshot);
        }

        foreach ($sessionevents as $sessionevent) {
            $session = $sessions[$sessionevent->objectid];
            $facetofaceid = (int) $session->facetoface;
            $this->assertSame('facetoface_sessions', $sessionevent->objecttable);
            $this->assertSame((int) $facetofaces[$facetofaceid]->course, (int) $sessionevent->courseid);
            $this->assertSame($contextinstanceids[$facetofaceid], (int) $sessionevent->contextinstanceid);
            $sessionsnapshot = $sessionevent->get_record_snapshot('facetoface_sessions', $session->id);
            $this->assertEquals((array) $session, (array) $sessionsnapshot);
            $facetofacesnapshot = $sessionevent->get_record_snapshot('facetoface', $facetofaceid);
            $this->assertEquals((array) $facetofaces[$facetofaceid], (array) $facetofacesnapshot);
        }

        $this->assertDebuggingNotCalled();
    }

    /**
     * A partially failed upload logs completed sessions but does not trigger the success summary.
     */
    public function test_partial_failure_logs_completed_sessions_without_success_summary(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['shortname' => 'PARTIAL_FAILURE']);
        $facetoface = $this->getDataGenerator()->create_module('facetoface', [
            'course' => $course->id,
            'name' => 'Partial failure activity',
        ]);

        $validrecord = $this->make_record('Completed session', '06/01/2030');
        $validrecord['Course Shortname'] = $course->shortname;
        $validrecord['Face-to-Face Activity Name'] = $facetoface->name;
        $invalidrecord = $this->make_record('Failed session', '32/01/2030');
        $invalidrecord['Course Shortname'] = $course->shortname;
        $invalidrecord['Face-to-Face Activity Name'] = $facetoface->name;

        $manager = new bulk_session_manager_sitelevel();
        $this->set_manager_records($manager, [$validrecord, $invalidrecord]);

        $this->assertEmpty($manager->validate());
        $success = $manager->process();
        $this->assertFalse($success);
        $this->assertNotEmpty($manager->get_errors());
        $sessions = $manager->get_created_sessions();
        $this->assertCount(1, $sessions);

        $summaryevent = csv_processed_bulksession_sitelevel::create([
            'context' => context_system::instance(),
            'objectid' => 0,
        ]);
        $sink = $this->redirectEvents();
        trigger_bulk_session_created_events($manager);
        if ($success) {
            add_bulk_session_event_snapshots($summaryevent, $manager, null);
            $summaryevent->trigger();
        }
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $sessionevent = reset($events);
        $session = reset($sessions);
        $this->assertInstanceOf(add_session::class, $sessionevent);
        $this->assertSame((int) $session->id, (int) $sessionevent->objectid);
        $this->assertSame(1, $DB->count_records('facetoface_sessions', ['facetoface' => $facetoface->id]));
        $this->assertDebuggingNotCalled();
    }

    /**
     * Create a valid bulk-upload record.
     *
     * @param string $details Session details.
     * @param string $date Session date in d/m/Y format.
     * @return array CSV record.
     */
    private function make_record(string $details, string $date): array {
        return [
            'Session Date/Time Known' => 'yes',
            'Start Date' => $date,
            'Start Time' => '10:00',
            'Finish Date' => $date,
            'Finish Time' => '11:00',
            'Allow Cancellations' => 'yes',
            'Capacity' => '10',
            'Allow Overbookings' => 'no',
            'Duration' => '60',
            'Normal Cost' => '0',
            'Discount Cost' => '0',
            'Details' => $details,
        ];
    }

    /**
     * Set records on a bulk manager without creating a draft CSV file.
     *
     * @param bulk_session_manager_parent $manager Bulk session manager.
     * @param array $records CSV records.
     * @return void
     */
    private function set_manager_records(bulk_session_manager_parent $manager, array $records): void {
        $property = new \ReflectionProperty(bulk_session_manager_parent::class, 'records');
        $property->setAccessible(true);
        $property->setValue($manager, $records);
    }
}
