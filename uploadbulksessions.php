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

require_once '../../config.php';
require_once $CFG->dirroot.'/mod/facetoface/lib.php';

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
require_capability('mod/facetoface:uploadbulksessions', $context);

if ($validate) {
    $heading = get_string('facetoface:uploadbulksessions', 'facetoface');
    $mform = new upload_bulk_sessions_form();

    // Check if the form was cancelled.
    if ($mform->is_cancelled()) {
        redirect(new moodle_url('/mod/facetoface/view.php', ['id' => $cm->id]));
    }
    // Process submitted form data.
    elseif ($data = $mform->get_data()) {
        // Get the file id from the filemanager field.
        $fileid = $data->csvfile ?: 0;
        $manager = new mod_facetoface\bulk_sessions_manager($f);
        if ($manager->load_from_file($fileid)) {
            $errors = $manager->validate();
            if (empty($errors)) {
                if ($manager->process()) {
                    redirect(new moodle_url('/mod/facetoface/view.php', ['id' => $cm->id]),
                        get_string('facetoface:bulkuploadsuccess', 'facetoface'),
                        null,
                        notification::NOTIFY_SUCCESS);
                } else {
                    redirect(new moodle_url('/mod/facetoface/bulkupload.php', ['f' => $f]),
                        implode('<br>', $manager->get_errors()),
                        null,
                        notification::NOTIFY_ERROR);
                }
            } else {
                notification::add(implode('<br>', $errors), notification::NOTIFY_ERROR);
            }
        } else {
            notification::add(get_string('error:filenotloaded', 'facetoface'), notification::NOTIFY_ERROR);
        }
    }
} elseif ($process && $fileid && $f) {
    // This branch could be used for a separate processing step if needed.
    $errmsg = get_string('error:bulkuploaderrors', 'facetoface');
    redirect(new moodle_url('/mod/facetoface/bulkupload.php', ['f' => $f]), $errmsg, null, notification::NOTIFY_ERROR);
} else {
    // Initial display: Show the upload form.
    $mform = new upload_bulk_sessions_form();
    // Prepopulate hidden fields (instance id and validate flag).
    $mform->set_data(['f' => $f, 'validate' => 1]);
    $heading = get_string('facetoface:uploadbulksessions', 'facetoface');
}

$PAGE->set_url(new moodle_url('/mod/facetoface/bulkupload.php', ['f' => $f]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title($heading);
$PAGE->set_heading($heading);

echo $OUTPUT->header();
$mform->display();
echo $OUTPUT->footer();
