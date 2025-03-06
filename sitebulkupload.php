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
 * Upload form for csv file to handle enrolment of bookings in bulk.
 *
 * @author     Jonas Sajonas
 * @copyright  GCHLOL, 2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');

use core\output\notification;
use mod_facetoface\form\site_bulk_session_upload_form;
use mod_facetoface\form\site_bulk_session_confirm_form;
use mod_facetoface\site_bulk_manager;

// Setup the admin page; must match the admin_externalpage ID from settings.php:
admin_externalpage_setup('modfacetoface_sitebulkupload');

// Optional: ensure user has site config capability (though admin_externalpage_setup often does):
require_capability('moodle/site:config', context_system::instance());


$validate = optional_param('validate', 0, PARAM_INT);
$process  = optional_param('process', 0, PARAM_INT);
$fileid   = optional_param('fileid', 0, PARAM_INT);
$caseinsensitive = optional_param('caseinsensitive', false, PARAM_BOOL);


$PAGE->set_url('/mod/facetoface/sitebulkupload.php');
$PAGE->set_title(get_string('f2fbulksessions', 'mod_facetoface'));
$PAGE->set_heading(get_string('pluginname', 'mod_facetoface'));

if (!$validate && !$process) {
    $mform = new site_bulk_session_upload_form(null, [
        // Provide a hidden param so the form submission sets validate=1
        'validate' => 1
    ]);

    echo $OUTPUT->header();
    $mform->display();
    echo $OUTPUT->footer();
    exit;
}

// 5) Step 2: Validate & preview if user requested validation.
if ($validate) {
    // Reload the upload form to get the fileid.
    $mform = new site_bulk_session_upload_form();
    $data  = $mform->get_data();

    // If form wasn’t submitted properly, just redirect to the initial page.
    if (!$data) {
        echo $OUTPUT->header();
        echo $OUTPUT->notification(get_string('error:choosecsv', 'mod_facetoface'), 'error');
        $mform->display();
        echo $OUTPUT->footer();
        exit;
    }

    $fileid = $data->csvfile ?: 0;

    // Create confirm form (the next step).
    $confirmform = new site_bulk_session_confirm_form(null, [
        'fileid' => $fileid,
        'caseinsensitive' => $caseinsensitive,
        'process' => 1
    ]);

    // Use site_bulk_manager for site-level CSV handling.
    $manager = new site_bulk_manager();
    $manager->load_from_file($fileid);
    $manager->set_case_insensitive($caseinsensitive);

    // Validate CSV data.
    $errors = $manager->validate();
    if (!empty($errors)) {
        echo $OUTPUT->header();
        echo $OUTPUT->notification(
            get_string('error:bulkuploadfileerrorsfound', 'mod_facetoface', count($errors)),
            notification::NOTIFY_ERROR
        );

        // Display errors in a table.
        $table = new html_table();
        $table->attributes['class'] = 'f2fbookingsuploadlist generaltable mb-2';
        $table->head = [
            get_string('uucsvline', 'tool_uploaduser'),
            get_string('status', 'facetoface')
        ];

        foreach ($errors as $error) {
            // If $error is ["line #", "message", "message2"...]
            if (is_array($error) && count($error) >= 2) {
                $line     = $error[0];
                $messages = array_slice($error, 1);
                foreach ($messages as $msg) {
                    $table->data[] = [$line, $msg];
                }
            } else {
                $table->data[] = ['-', is_string($error) ? $error : json_encode($error)];
            }
        }

        echo html_writer::table($table);
        echo $OUTPUT->footer();
        exit;
    }

    // If no errors, show a preview table plus the "confirm" form.
    $records = $manager->get_records();

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('facetoface:confirmbulkpreview', 'mod_facetoface'), 3);

    if (!empty($records)) {
        $table = new html_table();
        $table->attributes['class'] = 'f2fconfirmuploadlist generaltable mb-2';
        $table->head = $manager->get_headers();

        foreach ($records as $record) {
            $table->data[] = array_values($record);
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification(
            get_string('facetoface:norecordsfound', 'mod_facetoface'),
            notification::NOTIFY_INFO
        );
    }

    $confirmform->display();
    echo $OUTPUT->footer();
    exit;
}

// 6) Step 3: Process the CSV if user confirmed.
if ($process && $fileid) {
    $manager = new site_bulk_manager();
    $manager->load_from_file($fileid);
    $manager->set_case_insensitive($caseinsensitive);

    // If your confirm form has a "suppressemail" checkbox, handle that:
    $confirmform = new site_bulk_session_confirm_form();
    $confirmdata = $confirmform->get_data();
    if (!empty($confirmdata->suppressemail)) {
        $manager->suppress_email();
    }

    // Validate again, just to be safe.
    $errors = $manager->validate();
    if (empty($errors)) {
        $manager->process();
        redirect(
            new moodle_url('/mod/facetoface/sitebulkupload.php'),
            get_string('f2fbulksessionsdone', 'mod_facetoface'),
            null,
            notification::NOTIFY_SUCCESS
        );
    } else {
        $errmsg = get_string('error:bulkuploadfileerrorsfound', 'mod_facetoface', count($errors));
        redirect(
            new moodle_url('/mod/facetoface/sitebulkupload.php'),
            $errmsg,
            null,
            notification::NOTIFY_ERROR
        );
    }
}
