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

use context_user;
use stdClass;

/**
 * Tests for the Session Visibility column of bulk session uploads.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_facetoface\bulk_session_manager_parent
 */
final class bulk_session_manager_test extends \advanced_testcase {
    /**
     * Set up each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Upload levels that share the Session Visibility handling.
     *
     * @return array
     */
    public static function level_provider(): array {
        return [
            'activity level' => ['activity'],
            'site level' => ['site'],
        ];
    }

    /**
     * Session Visibility hides a session for 0 and shows it for 1 or blank, ignoring surrounding spaces.
     *
     * @dataProvider level_provider
     * @param string $level 'activity' or 'site'.
     */
    public function test_session_visibility_column_sets_visibility(string $level): void {
        global $DB;

        [$manager, $facetoface, $columns] = $this->create_manager($level);
        $this->set_manager_records($manager, [
            $columns + $this->make_record('Hidden', '0'),
            $columns + $this->make_record('Shown', '1'),
            $columns + $this->make_record('Blank', ''),
            $columns + $this->make_record('Padded hidden', ' 0 '),
            $columns + $this->make_record('Padded shown', ' 1 '),
        ]);

        $this->assertSame([], $manager->validate());
        $this->assertTrue($manager->process());

        $visibility = $this->get_session_visibility($facetoface);
        $this->assertEquals([
            'Hidden' => 0,
            'Shown' => 1,
            'Blank' => 1,
            'Padded hidden' => 0,
            'Padded shown' => 1,
        ], $visibility);

        // Event snapshots use the created records, so they must match the database.
        $created = $manager->get_created_sessions();
        $this->assertCount(5, $created);
        foreach ($created as $session) {
            $this->assertSame($visibility[$session->details], $session->visible);
        }
    }

    /**
     * Uploads without the Session Visibility column still create visible sessions.
     *
     * @dataProvider level_provider
     * @param string $level 'activity' or 'site'.
     */
    public function test_missing_session_visibility_column_shows_sessions(string $level): void {
        [$manager, $facetoface, $columns] = $this->create_manager($level);
        $this->set_manager_records($manager, [$columns + $this->make_record('No column', null)]);

        $this->assertSame([], $manager->validate());
        $this->assertTrue($manager->process());
        $this->assertEquals(['No column' => 1], $this->get_session_visibility($facetoface));
    }

    /**
     * Values other than 0, 1 or blank.
     *
     * @return array
     */
    public static function invalid_visibility_provider(): array {
        return [
            'two' => ['2'],
            'negative' => ['-1'],
            'leading zero' => ['01'],
            'decimal' => ['1.0'],
            'yes' => ['yes'],
            'word' => ['Hide'],
            'markup' => ['<b>1</b>'],
        ];
    }

    /**
     * Validation rejects a Session Visibility value that is not 0, 1 or blank, and escapes it in the error.
     *
     * @dataProvider invalid_visibility_provider
     * @param string $value The invalid Session Visibility value.
     */
    public function test_invalid_session_visibility_is_rejected(string $value): void {
        [$manager, $facetoface, $columns] = $this->create_manager('activity');
        $this->set_manager_records($manager, [
            $columns + $this->make_record('Valid', '0'),
            $columns + $this->make_record('Invalid', $value),
        ]);

        $errors = $manager->validate();

        $this->assertSame([[1, get_string('error:invalidsessionvisibility', 'facetoface', s($value))]], $errors);
        $this->assertStringNotContainsString('<', $errors[0][1]);
    }

    /**
     * An uploaded CSV file can include the Session Visibility column or leave it out.
     */
    public function test_uploaded_csv_sets_session_visibility(): void {
        [$manager, $facetoface] = $this->create_manager('activity');
        $header = 'Session Date/Time Known,Start Date,Start Time,Finish Date,Finish Time,Allow Cancellations,Capacity,'
            . 'Allow Overbookings,Duration,Normal Cost,Discount Cost,Details';
        $row = 'yes,01/01/2030,10:00,01/01/2030,11:00,yes,10,no,60,0,0';

        $manager->load_from_file($this->create_draft_csv(implode("\n", [
            "{$header},Session Visibility",
            "{$row},Hidden session,0",
            "{$row},Shown session,1",
            "{$row},Blank session,",
        ])));
        $this->assertSame([], $manager->validate());
        $this->assertTrue($manager->process());

        $nocolumnmanager = new bulk_session_manager_activitylevel((int) $facetoface->id);
        $nocolumnmanager->load_from_file($this->create_draft_csv(implode("\n", [
            $header,
            "{$row},Session without the column",
        ])));
        $this->assertSame([], $nocolumnmanager->validate());
        $this->assertTrue($nocolumnmanager->process());

        $this->assertEquals([
            'Hidden session' => 0,
            'Shown session' => 1,
            'Blank session' => 1,
            'Session without the column' => 1,
        ], $this->get_session_visibility($facetoface));
    }

    /**
     * The example CSV files linked from the upload forms are valid and include the Session Visibility column.
     */
    public function test_example_csv_files_include_session_visibility(): void {
        global $CFG;

        // The site-level example names this course and activity.
        $course = $this->getDataGenerator()->create_course(['shortname' => 'shortname_of_course']);
        $facetoface = $this->getDataGenerator()->create_module('facetoface', [
            'course' => $course->id,
            'name' => 'complete_name_of_activity',
        ]);

        $managers = [
            'example_sessions.csv' => new bulk_session_manager_activitylevel((int) $facetoface->id),
            'example_bulksessions.csv' => new bulk_session_manager_sitelevel(),
        ];
        foreach ($managers as $filename => $manager) {
            $manager->load_from_file($this->create_draft_csv(
                file_get_contents("{$CFG->dirroot}/mod/facetoface/{$filename}")
            ));

            $this->assertSame([], $manager->validate(), $filename);
            $records = $manager->get_records();
            $this->assertNotEmpty($records, $filename);
            foreach ($records as $record) {
                $this->assertContains($record['Session Visibility'] ?? null, ['0', '1'], $filename);
            }
        }
    }

    /**
     * Creates a Face-to-Face activity and a bulk session manager that uploads to it.
     *
     * @param string $level 'activity' or 'site'.
     * @return array The manager, the activity, and the columns each of the manager's records needs to find the activity.
     */
    private function create_manager(string $level): array {
        $course = $this->getDataGenerator()->create_course();
        $facetoface = $this->getDataGenerator()->create_module('facetoface', ['course' => $course->id]);

        if ($level === 'activity') {
            return [new bulk_session_manager_activitylevel((int) $facetoface->id), $facetoface, []];
        }

        return [
            new bulk_session_manager_sitelevel(),
            $facetoface,
            [
                'Course Shortname' => $course->shortname,
                'Face-to-Face Activity Name' => $facetoface->name,
            ],
        ];
    }

    /**
     * Creates a valid bulk-upload record.
     *
     * @param string $details Session details, used to identify the session.
     * @param string|null $visibility Session Visibility value, or null to leave the column out.
     * @return array CSV record.
     */
    private function make_record(string $details, ?string $visibility): array {
        $record = [
            'Session Date/Time Known' => 'yes',
            'Start Date' => '01/01/2030',
            'Start Time' => '10:00',
            'Finish Date' => '01/01/2030',
            'Finish Time' => '11:00',
            'Allow Cancellations' => 'yes',
            'Capacity' => '10',
            'Allow Overbookings' => 'no',
            'Duration' => '60',
            'Normal Cost' => '0',
            'Discount Cost' => '0',
            'Details' => $details,
        ];

        if ($visibility !== null) {
            $record['Session Visibility'] = $visibility;
        }

        return $record;
    }

    /**
     * Sets records on a bulk manager without creating a draft CSV file.
     *
     * @param bulk_session_manager_parent $manager Bulk session manager.
     * @param array $records CSV records.
     * @return void
     */
    private function set_manager_records(bulk_session_manager_parent $manager, array $records): void {
        $property = new \ReflectionProperty(bulk_session_manager_parent::class, 'records');
        $property->setValue($manager, $records);
    }

    /**
     * Saves CSV content as a draft file of the current user, as the upload form does.
     *
     * @param string $content CSV content.
     * @return int Draft item ID.
     */
    private function create_draft_csv(string $content): int {
        global $USER;

        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'sessions.csv',
        ], $content);

        return $draftitemid;
    }

    /**
     * Gets the visibility of each session in an activity.
     *
     * @param stdClass $facetoface Face-to-Face activity.
     * @return int[] Visibility keyed by session details.
     */
    private function get_session_visibility(stdClass $facetoface): array {
        global $DB;

        return array_map('intval', $DB->get_records_menu(
            'facetoface_sessions',
            ['facetoface' => $facetoface->id],
            '',
            'details, visible'
        ));
    }
}
