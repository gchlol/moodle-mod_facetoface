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

 require_once('../../config.php');
 require_once($CFG->dirroot . '/mod/facetoface/lib.php');
 
 use core\output\notification;
 use mod_facetoface\form\upload_bulk_sessions_form;
 use mod_facetoface\form\confirm_bulk_sessions_form;
 use mod_facetoface\bulk_sessions_manager;
 
 $f = optional_param('f', 0, PARAM_INT); // The facetoface module ID.
 $fileid = optional_param('fileid', 0, PARAM_INT); // The fileid of the file uploaded.
 $validate = optional_param('validate', 0, PARAM_INT); // Whether or not the user wants to process the upload (after verification).
 $process = optional_param('process', 0, PARAM_INT); // Whether or not the user wants to process the upload (after verification).
 
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
 
 // **STEP 1: Validate the uploaded CSV file**
 if ($validate) {
     $heading = get_string('facetoface:validatebulksessions', 'facetoface');
 
     $mform = new upload_bulk_sessions_form(null);
     $data = $mform->get_data();
     $fileid = $data->csvfile ?: 0;
 
     // **Confirmation form before processing**
     $mform = new confirm_bulk_sessions_form(null, ['f' => $f, 'fileid' => $fileid]);
 
     $manager = new bulk_sessions_manager($f);
     $manager->load_from_file($fileid);
 
     // Validate CSV records
     $errors = $manager->validate();
 
     if (!empty($errors)) {
         echo $OUTPUT->header();
         echo $OUTPUT->notification(get_string('error:bulkuploadfileerrorsfound', 'mod_facetoface', count($errors)), 'error');
 
         // **Display errors in a table**
         $table = new html_table();
         $table->attributes['class'] = 'f2fbookingsuploadlist m-auto generaltable mb-2';
         $table->head = [get_string('uucsvline', 'tool_uploaduser'), get_string('status', 'facetoface')];
 
         foreach ($errors as $line => $message) {
             $table->data[] = [$line, $message];
         }
 
         echo html_writer::tag('div', html_writer::table($table), ['class' => 'flexible-wrap mb-4']);
         echo $OUTPUT->footer();
         exit;
     } else {
         // If no errors, show confirmation form
         echo $OUTPUT->header();
         $mform->display();
         echo $OUTPUT->footer();
         exit;
     }
 }
 
 // **STEP 2: Process the CSV after confirmation**
 if ($process && $fileid && $f) {
     $manager = new bulk_sessions_manager($f);
     $manager->load_from_file($fileid);
 
     // Read confirmation form data
     $confirmdata = (new confirm_bulk_sessions_form(null))->get_data();
 
     // Process the records after confirmation
     $errors = $manager->validate();
     if (empty($errors)) {
         $manager->process();
 
         // Logging event
         $params = [
             'context'  => $modulecontext,
             'objectid' => $f,
         ];
         $event = \mod_facetoface\event\csv_processed::create($params);
         $event->add_record_snapshot('facetoface', $facetoface);
         $event->trigger();
 
         // Redirect back with success message
         redirect(
             new moodle_url('/mod/facetoface/bulkupload.php', ['f' => $f]),
             get_string('facetoface:bulksessionsprocessed', 'mod_facetoface'),
             null,
             notification::NOTIFY_SUCCESS
         );
     }
 
     // If errors exist, redirect back with error message.
     $errmsg = get_string('error:bulkuploadfileerrorsfound', 'mod_facetoface', count($errors));
     redirect(
         new moodle_url('/mod/facetoface/bulkupload.php', ['f' => $f]),
         $errmsg,
         null,
         notification::NOTIFY_ERROR
     );
 }
 
 // **STEP 3: Display the initial upload form**
 $mform = new upload_bulk_sessions_form(null);
 $mform->set_data(['f' => $f, 'validate' => 1]);
 $heading = get_string('facetoface:uploadbulksessions', 'facetoface');
 
 $PAGE->set_url(new moodle_url('/mod/facetoface/bulkupload.php', ['courseid' => $course->id, 'cmid' => $cm->id]));
 $PAGE->set_pagelayout('standard');
 $PAGE->set_title($heading);
 $PAGE->set_heading($heading);
 
 echo $OUTPUT->header();
 $mform->display();
 echo $OUTPUT->footer();