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

// Flow-control parameters.
$validate = optional_param('validate', 0, PARAM_INT);
$process = optional_param('process', 0, PARAM_INT);
$fileid = optional_param('fileid', 0, PARAM_INT);
$caseinsensitive = optional_param('caseinsensitive', false, PARAM_BOOL);
$heading = get_string('uploadbulkbookings', 'facetoface');

$PAGE->set_url(new moodle_url('/mod/facetoface/upload_ext.php'));
$PAGE->set_title($heading);
$PAGE->set_heading($heading);

// Show upload form, no actions yet.
if (!$validate && !$process) {
    display_upload_form();

    exit;
}

// User submitted CSV, validate but not finalise.
if ($validate && !$process) {
    handle_csv_validation($fileid, $caseinsensitive);

    exit;
}

// Process final booking.
if ($process && $fileid) {
    handle_csv_processing($fileid, $caseinsensitive);

    exit;
}

// Default fallback if no condition matched.
echo $OUTPUT->header();
echo $OUTPUT->footer();


/**
 * Display the initial upload form.
 */
function display_upload_form(): void {
    global $OUTPUT;

    $mform = new upload_bookings_form_ext(null, ['validate' => 1]);

    echo $OUTPUT->header();

    $mform->display();

    echo $OUTPUT->footer();
}

/**
 * Handle the flow when user has submitted the CSV for validation,
 * but not final confirmation/processing.
 *
 * @param int $fileid The draft file ID (initially 0 if not set yet).
 * @param bool $caseinsensitive Whether user matching is case-insensitive.
 */
function handle_csv_validation(int &$fileid, bool $caseinsensitive): void {
    global $OUTPUT;

    $mform = new upload_bookings_form_ext();
    $data  = $mform->get_data();

    // If no data or missing CSV, show error and re-display form.
    if (!$data || empty($data->csvfile)) {
        echo $OUTPUT->header();

        throw new moodle_exception('error:cannotloadfile', 'mod_facetoface');

        echo $OUTPUT->footer();

        return;
    }

    $fileid = $data->csvfile;

    // Initialise manager with uploaded file.
    $manager = new booking_manager_ext();
    $manager->set_case_insensitive($caseinsensitive);
    $manager->load_from_file($fileid);

    // Validate.
    $errors = $manager->validate();
    echo $OUTPUT->header();

    if (!empty($errors)) {
        display_errors_table($errors);
        echo html_writer::link(new moodle_url('/mod/facetoface/upload_ext.php'), get_string('back'));
        echo $OUTPUT->footer();

        return;
    }

    // Show preview and confirmation form.
    $records = $manager->get_records();

    echo $OUTPUT->heading(get_string('uploadpreview', 'mod_facetoface'), 4);

    if (!empty($records)) {
        display_records_preview($records);
    }

    // Show confirm form.
    $confirmform = new confirm_bookings_form_ext(null, [
        'fileid' => $fileid,
        'caseinsensitive' => $caseinsensitive,
        'process' => 1
    ]);
    $confirmform->display();

    echo $OUTPUT->footer();
}

/**
 * Handle final processing step after user confirmed.
 *
 * @param int $fileid The draft file ID with CSV.
 * @param bool $caseinsensitive Whether user matching is case-insensitive.
 */
function handle_csv_processing(int $fileid, bool $caseinsensitive): void {
    global $OUTPUT;

    $confirmform = new confirm_bookings_form_ext();
    if ($confirmform->is_cancelled()) {

        redirect(new moodle_url('/mod/facetoface/upload_ext.php'));
    }

    $manager = new booking_manager_ext();
    $manager->set_case_insensitive($caseinsensitive);
    $manager->load_from_file($fileid);

    // Check if suppress emails was ticked.
    $confirmdata = $confirmform->get_data();
    if (!empty($confirmdata->suppressemail)) {
        $manager->suppress_email();
    }

    // Validate again as a final check.
    $errors = $manager->validate();
    if (!empty($errors)) {
        $errmsg = get_string('error:bulkuploadfileerrorsfound', 'mod_facetoface', count($errors));

        redirect(
            new moodle_url('/mod/facetoface/upload_ext.php'),
            $errmsg,
            null,
            notification::NOTIFY_ERROR
        );
    }

    // Process actual bookings.
    $manager->process();

    // Show success and redirect.
    redirect(
        new moodle_url('/mod/facetoface/upload_ext.php'),
        get_string('facetoface:csvprocessed', 'mod_facetoface'),
        null,
        notification::NOTIFY_SUCCESS
    );
}

/**
 * Show each error row in a table.
 */
function display_errors_table(array $errors): void {
    global $OUTPUT;

    echo $OUTPUT->notification(
        get_string('error:bulkuploadfileerrorsfound', 'mod_facetoface', count($errors)),
        'error'
    );

    $table = new html_table();
    $table->attributes['class'] = 'generaltable mb-3';
    $table->head = [
        get_string('csvline', 'tool_uploaduser'),
        get_string('status')
    ];

    foreach ($errors as $error) {
        $rownum = $error[0];
        $errmsg = $error[1];
        $table->data[] = [$rownum, $errmsg];
    }
    echo html_writer::table($table);
}

/**
 * Show a preview of all CSV records.
 */
function display_records_preview(array $records): void {
    $table = new html_table();
    $table->head = booking_manager_ext::get_headers();

    foreach ($records as $r) {
        $table->data[] = array_values((array)$r);
    }

    echo html_writer::table($table);
}
