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
 * @package    mod_facetoface
 * @author     Kevin Pham <kevinpham@catalyst-au.net>
 * @copyright  Catalyst IT, 2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');

use core\output\notification;
use mod_facetoface\form\upload_bookings_form;
use mod_facetoface\form\confirm_bookings_form;
use mod_facetoface\booking_manager;

$f = optional_param('f', 0, PARAM_INT); // The facetoface module ID.
$fileid = optional_param('fileid', 0, PARAM_INT); // The fileid of the file uploaded.
$validate = optional_param('validate', 0, PARAM_INT); // Whether or not the user wants to process the upload (after verification).
$process = optional_param('process', 0, PARAM_INT); // Whether or not the user wants to process the upload (after verification).
$step = optional_param('step', '', PARAM_ALPHA); // The current step in the process.
$caseinsensitive = optional_param('caseinsensitive', false, PARAM_BOOL); // If emails should match a user case insensitively.

if (!$facetoface = $DB->get_record('facetoface', ['id' => $f])) {
    throw new moodle_exception('error:incorrectfacetofaceid', 'facetoface');
}
if (!$course = $DB->get_record('course', ['id' => $facetoface->course])) {
    throw new moodle_exception('error:coursemisconfigured', 'facetoface');
}
if (!$cm = get_coursemodule_from_instance('facetoface', $facetoface->id, $course->id)) {
    throw new moodle_exception('error:incorrectcoursemoduleid', 'facetoface');
}

require_course_login($course, true, $cm);
$context = context_course::instance($course->id);
$modulecontext = context_module::instance($cm->id);
require_capability('mod/facetoface:editsessions', $context);
require_capability('mod/facetoface:uploadbookings', $context);


// Render form, which should only consist of an upload element.
if ($validate) {
    // Form submitted, but not ready for processing -> validate.
    $heading = get_string('facetoface:validatebookings', 'facetoface');

    $mform = new upload_bookings_form(null);
    $data = $mform->get_data();
    $fileid = $data->csvfile ?: 0;

    $mform = new confirm_bookings_form(null, ['f' => $f, 'fileid' => $fileid, 'caseinsensitive' => $caseinsensitive]);

    $bm = new booking_manager($f);
    $bm->load_from_file($fileid);
    $bm->set_case_insensitive($caseinsensitive);

    // Validate entries.
    $errors = $bm->validate();

    // Set form data to allow user to continue and process the uploaded file on their next form submit.
} else if ($process && $fileid && $f) {
    // Form submitted, and ready for processing -> process.
    $bm = new booking_manager($f);
    $bm->load_from_file($fileid);
    $bm->set_case_insensitive($caseinsensitive);

    // Get the options selected by the user at confirm time.
    $confirmdata = (new confirm_bookings_form(null))->get_data();

    if (!empty($confirmdata->suppressemail)) {
        $bm->suppress_email();
    }

    // Validate entries.
    $errors = $bm->validate();
    if (empty($errors)) {
        // Process entries.
        $bm->process();

        // Logging and events trigger.
        $params = [
            'context'  => $modulecontext,
            'objectid' => $f,
        ];
        $event = \mod_facetoface\event\csv_processed::create($params);
        $event->add_record_snapshot('facetoface', $facetoface);
        $event->trigger();

        // Redirect back to start with notification.
        redirect(
            new moodle_url('/mod/facetoface/upload.php', ['f' => $f]),
            get_string('facetoface:csvprocessed', 'mod_facetoface'),
            null,
            notification::NOTIFY_SUCCESS);
    }

    $errmsg = get_string('error:bookingsuploadfileerrorsfound', 'mod_facetoface', count($errors));
    redirect(
        new moodle_url('/mod/facetoface/upload.php', ['f' => $f]),
        $errmsg,
        null,
        notification::NOTIFY_ERROR);
} else {
    $mform = new upload_bookings_form(null);
    $mform->set_data(['f' => $f, 'validate' => 1]);

    // Form not subumitted -> prep the form with current context (f2f module id).
    $heading = get_string('facetoface:uploadbookings', 'facetoface');
}

$PAGE->set_url(new moodle_url('/mod/facetoface/upload.php', ['courseid' => $course->id, 'cmid' => $cm->id]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title($heading);
$PAGE->set_heading($heading);

echo $OUTPUT->header();

// List out any issues in a table.
if ($validate && !empty($errors)) {
    // Print summary statement.
    echo \core\notification::error(get_string('error:bookingsuploadfileerrorsfound', 'mod_facetoface', count($errors)));

    $table = new html_table();
    $table->attributes['class'] = 'f2fbookingsuploadlist m-auto generaltable mb-2';

    $table->head[] = get_string('uucsvline', 'tool_uploaduser');
    $table->head[] = get_string('status');
    $table->data = $errors;

    echo html_writer::tag('div', html_writer::table($table), ['class' => 'flexible-wrap mb-4']);
}

if ($validate && empty($errors)) {
    // Bonus: show a preview/summary for good records (e.g. 40 records will be processed).
    echo \core\notification::success(get_string('facetoface:uploadreadytoprocess', 'mod_facetoface'));
}

$mform->display();

// Display footer.
echo $OUTPUT->footer();
