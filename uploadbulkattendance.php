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
 * Handles bulk attendance CSV uploads for Face-to-Face, site-wide.
 * Manages CSV validation, preview, and attendance processing.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');

use core\output\notification;
use mod_facetoface\form\upload_bookings_bulk_attendance_form;
use mod_facetoface\form\confirm_bookings_bulk_attendance_form;
use mod_facetoface\booking_manager_bulk_attendance;
use mod_facetoface\event\csv_processed_bulkattendance;

// Set up the external admin page (Site administration > Plugins > Face-to-Face).
admin_externalpage_setup('modfacetoface_uploadbulkattendance');

// 2) Read URL parameters.
$fileid = optional_param('fileid', 0, PARAM_INT);
$validate = optional_param('validate', 0, PARAM_INT);
$process = optional_param('process', 0, PARAM_INT);
$caseinsensitive = optional_param('caseinsensitive', false,     PARAM_BOOL);

// 3) Require site configuration capability.
require_capability('moodle/site:config', context_system::instance());

// 4) Set up $PAGE.
$heading = get_string('uploadbulkbookings', 'mod_facetoface');
$PAGE->set_url(new moodle_url('/mod/facetoface/uploadbulkattendance.php', [
    'fileid'          => $fileid,
    'validate'        => $validate,
    'process'         => $process,
    'caseinsensitive' => $caseinsensitive
]));
$PAGE->set_title($heading);
$PAGE->set_heading($heading);
$PAGE->set_pagelayout('admin');

// 5) Instantiate the upload form once.
$uploadform = new upload_bookings_bulk_attendance_form();

/**
 * Utility function to display bulk-upload errors and then stop execution.
 *
 * @param array $errors  A list of errors; each error can be a string or an array whose first element is the row number.
 * @param int $fileid File ID.
 * @return void
 */
function display_bulk_upload_errors(array $errors, int $fileid): void {
    global $OUTPUT;

    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        get_string('error:bookingsuploadfileerrorsfound', 'mod_facetoface', count($errors)),
        notification::NOTIFY_ERROR
    );

    $table = new html_table();
    $table->attributes['class'] = 'f2fbookingsuploadlist m-auto generaltable mb-2';
    $table->head = [
        get_string('csvline', 'mod_facetoface'),
        get_string('status', 'mod_facetoface'),
    ];

    foreach ($errors as $error) {
        if (
            !is_array($error) ||
            count($error) < 2
        ) {
            // Simple string or invalid structure.
            $table->data[] = ['-', is_string($error) ? $error : json_encode($error)];

            continue;
        }
        // Add 1 to the index to match the line number displayed in Excel (CSV header: + 1).
        // GCHLOL: The row may be a comma-separated list for aggregated errors such as overbookings.
        $line = implode(', ', array_map(
            fn($errorrow) => (int) trim($errorrow) + 1,
            explode(',', (string) $error[0])
        ));
        $messages = array_slice($error, 1);

        foreach ($messages as $message) {
            $table->data[] = [$line, $message];
        }
    }

    echo html_writer::tag('div', html_writer::table($table), ['class' => 'flexible-wrap mb-4']);


    // Action buttons (aligned horizontally).
    // Button 1: Back.
    $htmlbuttons = $OUTPUT->single_button(
        new moodle_url('/mod/facetoface/uploadbulkattendance.php'),
        get_string('back'),
        'get'
    );

    // Button 2: Skip rows with errors.
    $htmlbuttons .= $OUTPUT->single_button(
        new moodle_url('/mod/facetoface/uploadbulkattendance.php', [
            'fileid'     => $fileid,
            'process'    => 1
        ]),
        get_string('updatevalidrows', 'mod_facetoface'),
        'post',
        ['class' => 'ml-3']
    );

    echo html_writer::tag('div', $htmlbuttons, ['class' => 'd-flex gap-2']);

    echo $OUTPUT->footer();

    exit;
}

// 6) Handle the “Upload & Preview” step.
if ($validate) {
    $data = $uploadform->get_data();

    if ($uploadform->is_cancelled()) {
        redirect(new moodle_url('/admin/search.php') . '#linkmodules');

        exit;
    }

    $fileid = $data->csvfile ?: 0;

    $confirmform = new confirm_bookings_bulk_attendance_form(
        null,
        ['fileid' => $fileid, 'process' => 1]
    );

    $manager = new booking_manager_bulk_attendance();
    $manager->load_from_file($fileid);
    $errors = $manager->validate();

    // If there are errors, handle them and exit.
    if (!empty($errors)) {
        display_bulk_upload_errors($errors, $fileid);
    }

    // If no errors, display the CSV preview.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('confirmbulkpreview', 'mod_facetoface'), 3);

    $records = $manager->get_records();

    if (empty($records)) {
        echo $OUTPUT->notification(get_string('norecordsfound', 'mod_facetoface'), notification::NOTIFY_INFO);
    }

    if (!empty($records)) {
        $table = new html_table();
        $table->attributes['class'] = 'f2fconfirmuploadlist m-auto generaltable mb-2';

        $firstrecord = reset($records);
        $headers = array_keys((array)$firstrecord);

        $table->head = $headers;

        foreach ($records as $record) {
            $rowdata = [];
            foreach ($headers as $h) {
                $rowdata[] = $record->$h ?? '';
            }
            $table->data[] = $rowdata;
        }

        echo html_writer::tag('div', html_writer::table($table), ['class' => 'flexible-wrap mb-4']);
    }

    $confirmform->display();

    echo $OUTPUT->footer();

    exit;
}


if (
    $process &&
    $fileid
) {
    $manager = new booking_manager_bulk_attendance();
    $manager->load_from_file($fileid);
    $confirmform = new confirm_bookings_bulk_attendance_form(null, ['fileid' => $fileid]);

    if ($confirmform->is_cancelled()) {
        redirect(new moodle_url('/mod/facetoface/uploadbulkattendance.php'));

        exit;
    }

    $errors = $manager->validate();

    // GCHLOL: Process even when validation errors exist; the manager skips the errored
    // rows, which is what the "Upload only rows with no errors" button promises.
    $success = $manager->process($errors);

    if ($success) {
        $event = csv_processed_bulkattendance::create([
            'context'  => context_system::instance(),
            'objectid' => 0,
        ]);
        $event->trigger();

        if (empty($errors)) {
            $message = get_string('bulkattendanceprocessed', 'mod_facetoface');
        } else {
            // GCHLOL: Report how many rows were processed and how many were skipped.
            $skippedrows = [];
            foreach ($errors as $error) {
                foreach (explode(',', (string) $error[0]) as $errorrow) {
                    $skippedrows[trim($errorrow)] = true;
                }
            }

            $message = get_string('bulkattendanceprocessedwithskips', 'mod_facetoface', (object) [
                'processed' => count($manager->get_records()) - count($skippedrows),
                'skipped'   => count($skippedrows),
            ]);
        }

        redirect(
            new moodle_url('/mod/facetoface/uploadbulkattendance.php'),
            $message,
            null,
            notification::NOTIFY_SUCCESS
        );
    }

    display_bulk_upload_errors($errors, $fileid);
}

$uploadform->set_data(['validate' => 1]);

echo $OUTPUT->header();

$uploadform->display();

echo $OUTPUT->footer();
