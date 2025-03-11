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
 * @package   mod_facetoface
 * @copyright 2025, Gold Coast Health
 * @author    Jonas Sajonas
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');

use core\output\notification;
use mod_facetoface\booking_manager_ext;
use mod_facetoface\form\upload_bookings_form_ext;
use mod_facetoface\form\confirm_bookings_form_ext;

admin_externalpage_setup('modfacetoface_upload_ext');


// Grab parameters controlling flow.
$validate = optional_param('validate', 0, PARAM_INT);
$process  = optional_param('process', 0, PARAM_INT);
$fileid   = optional_param('fileid', 0, PARAM_INT);
$caseinsensitive = optional_param('caseinsensitive', false, PARAM_BOOL);

$PAGE->set_url(new moodle_url('/mod/facetoface/upload_ext.php'));
$PAGE->set_title(get_string('pickfacetofaceinstance', 'mod_facetoface'));
$PAGE->set_heading(get_string('pluginname', 'mod_facetoface'));

if (!$validate && !$process) {
    // Step 1: Show the initial upload form.
    $mform = new upload_bookings_form_ext(null, [
        'validate' => 1 // So the form knows to set validate=1 on submit.
    ]);

    echo $OUTPUT->header();
    $mform->display();
    echo $OUTPUT->footer();
    exit;
}

if ($validate && !$process) {
    // Step 2: The user submitted the CSV file, so parse it, check for errors, show confirmation preview.
    $mform = new upload_bookings_form_ext();
    $data  = $mform->get_data();

    if (!$data || empty($data->csvfile)) {
        echo $OUTPUT->header();
        echo $OUTPUT->notification(get_string('error:choosecsv', 'mod_facetoface'), 'error');
        $mform->display();
        echo $OUTPUT->footer();
        exit;
    }

    // We have a valid CSV file in $data->csvfile (draft area itemid).
    $fileid = $data->csvfile;

    // Create a booking manager ext instance and load the file.
    $manager = new booking_manager_ext();
    $manager->set_case_insensitive($caseinsensitive);
    $manager->load_from_file($fileid);

    // Validate.
    $errors = $manager->validate();
    echo $OUTPUT->header();

    if (!empty($errors)) {
        // Show errors.
        echo $OUTPUT->notification(
            get_string('error:bulkuploadfileerrorsfound', 'mod_facetoface', count($errors)),
            'error'
        );

        // Display error table.
        $table = new html_table();
        $table->attributes['class'] = 'generaltable mb-3';
        $table->head = [get_string('uucsvline', 'tool_uploaduser'), get_string('status')];

        foreach ($errors as $error) {
            $rownum = $error[0];
            $errmsg = $error[1];
            $table->data[] = [$rownum, $errmsg];
        }
        echo html_writer::table($table);

        // Link back if you want.
        echo html_writer::link(new moodle_url('/mod/facetoface/upload_ext.php'), get_string('back'));
        echo $OUTPUT->footer();
        exit;
    } else {
        // No errors => show preview.
        $records = $manager->get_records();

        echo $OUTPUT->heading(get_string('uploadpreview', 'mod_facetoface'), 4);

        // If you want a table of all records.
        if (!empty($records)) {
            $table = new html_table();
            $table->head = booking_manager_ext::get_headers();
            foreach ($records as $r) {
                $table->data[] = array_values((array)$r);

            }
            echo html_writer::table($table);
        }

        // Show confirm form.
        $confirmform = new confirm_bookings_form_ext(null, [
            'fileid' => $fileid,
            'caseinsensitive' => $caseinsensitive,
            'process' => 1
        ]);
        $confirmform->display();
    }

    echo $OUTPUT->footer();
    exit;
}

if ($process && $fileid) {
    // Step 3: The user confirmed and we now finalize the bookings.
    $confirmform = new confirm_bookings_form_ext();
    if ($confirmform->is_cancelled()) {
        redirect(new moodle_url('/mod/facetoface/upload_ext.php'));
    }

    $manager = new booking_manager_ext();
    $manager->set_case_insensitive($caseinsensitive);
    $manager->load_from_file($fileid);

    // Did they check "suppress emails" in the confirm form?
    $confirmdata = $confirmform->get_data();
    if (!empty($confirmdata->suppressemail)) {
        $manager->suppress_email();
    }

    // Validate again (safety check).
    $errors = $manager->validate();
    if (!empty($errors)) {
        // Show error and redirect or display them.
        $errmsg = get_string('error:bulkuploadfileerrorsfound', 'mod_facetoface', count($errors));
        redirect(new moodle_url('/mod/facetoface/upload_ext.php'), $errmsg, null, notification::NOTIFY_ERROR);
    }

    // Process.
    $manager->process();

    // Show success and redirect.
    redirect(
        new moodle_url('/mod/facetoface/upload_ext.php'),
        get_string('facetoface:csvprocessed', 'mod_facetoface'),
        null,
        notification::NOTIFY_SUCCESS
    );
}

// Default fallback.
echo $OUTPUT->header();
echo $OUTPUT->footer();
