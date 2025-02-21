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
 * @author     Jonas Sajonas
 * @copyright  GCHLOL, 2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');

use core\output\notification;
use mod_facetoface\form\upload_bulk_sessions_form;

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
$context = context_module::instance($cm->id);
require_capability('mod_facetoface:uploadbulksessions', $context);

if ($validate) {
$heading = get_string('facetoface:uploadbulksessions', 'facetoface'); 
   // Form submitted for validation.
   $mform = new \mod_facetoface\form\upload_bulk_sessions_form(null);
   $data = $mform->get_data();
   $fileid = $data->csvfile ?: 0;

   // Load data from file and validate records.
   // $manager->load_from_file($fileid);
   // $errors = $manager->validate();
   // For now, we simulate:
   $errors = []; // Replace with actual validation.

   // Re-display confirm form if needed.
   // You might use another form to confirm before processing.
} else if ($process && $fileid && $f) {
   // Form submitted for processing.
   // $manager = new bulk_sessions_manager($f);
   // $manager->load_from_file($fileid);
   // $errors = $manager->validate();
   // if (empty($errors)) {
   //     $manager->process(); // Create sessions.
   //     // Log event etc.
   //     redirect(new moodle_url('/mod/facetoface/bulkupload.php', ['f' => $f]),
   //         get_string('bulkuploadsuccess', 'facetoface'), null, notification::NOTIFY_SUCCESS);
   // }
   // Otherwise, redirect with error notification.
   // For now, simulate:
   $errmsg = get_string('error:bulkuploaderrors', 'facetoface');
   redirect(new moodle_url('/mod/facetoface/bulkupload.php', ['f' => $f]), $errmsg, null, notification::NOTIFY_ERROR);
} else {
   $mform = new \mod_facetoface\form\upload_bulk_sessions_form(null);
   // Prepopulate hidden fields if necessary.
   $mform->set_data(['f' => $f, 'validate' => 1]);
   $heading = get_string('facetoface:uploadbulksessions', 'facetoface');
}

$PAGE->set_url(new moodle_url('/mod/facetoface/bulkupload.php', ['f' => $f]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title($heading);
$PAGE->set_heading($heading);

echo $OUTPUT->header();

// Optionally display any validation errors or a preview of the CSV data.
// Then display the form:
$mform->display();

echo $OUTPUT->footer();
