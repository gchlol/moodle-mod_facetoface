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
 * Handles bulk session uploads for the Face-to-Face module.
 * Manages CSV validation, preview, and session creation.
 *
 * @package     mod_facetoface
 * @copyright   2025 Gold Coast Health
 * @author      Jonas Sajonas
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');

use core\output\notification;
use mod_facetoface\form\bulk_session_upload_form;
use mod_facetoface\form\bulk_session_confirm_form;
use mod_facetoface\bulk_session_manager;

$f = required_param('f', PARAM_INT);
$PAGE->set_url(new moodle_url('/mod/facetoface/uploadbulksessions.php', ['f' => $f]));
$heading = get_string('facetoface:validatebulksessions', 'facetoface');

$fileid   = optional_param('fileid', 0, PARAM_INT);
$validate = optional_param('validate', 0, PARAM_INT);
$process  = optional_param('process', 0, PARAM_INT);

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

$context       = context_course::instance($course->id);
$modulecontext = context_module::instance($cm->id);
require_capability('mod/facetoface:editsessions', $context);
require_capability('mod/facetoface:uploadbulksessions', $context);

$PAGE->set_pagelayout('standard');
$PAGE->set_title($heading);
$PAGE->set_heading($heading);

// Instantiate the upload form once.
$uploadform = new bulk_session_upload_form(null, ['f' => $f]);

// Helper function to display errors and exit.
function handle_bulk_upload_errors($errors) {
    global $OUTPUT;

    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('error:bulkuploadfileerrorsfound', 'mod_facetoface', count($errors)), 'error');

    $table = new html_table();
    $table->attributes['class'] = 'f2fbookingsuploadlist m-auto generaltable mb-2';
    $table->head = [
        get_string('uucsvline', 'tool_uploaduser'),
        get_string('status', 'facetoface')
    ];

    foreach ($errors as $error) {
        if (!is_array($error) || count($error) < 2) {
            $table->data[] = ["-", is_string($error) ? $error : json_encode($error)];
            continue;
        }

        $line = $error[0];
        $messages = array_slice($error, 1);

        foreach ($messages as $message) {
            $table->data[] = [$line, $message];
        }
    }

    echo html_writer::tag('div', html_writer::table($table), ['class' => 'flexible-wrap mb-4']);
    echo $OUTPUT->footer();

    exit;
}

if ($validate) {
    $uploaddata = $uploadform->get_data();

    if ($uploadform->is_cancelled()) {
        redirect(new moodle_url('/mod/facetoface/view.php', ['f' => $f]));
        exit;
    }

    $fileid = $uploaddata->csvfile ?: 0;

    // Create the confirm form.
    $confirmform = new bulk_session_confirm_form(null, ['f' => $f, 'fileid' => $fileid]);

    $manager = new bulk_session_manager($f);
    $manager->load_from_file($fileid);

    $errors = $manager->validate();

    // If there are errors, handle them and exit.
    if (!empty($errors)) {
        handle_bulk_upload_errors($errors);
    }

    // If no errors, display the CSV preview.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('facetoface:confirmbulkpreview', 'facetoface'), 3);

    $records = $manager->get_records();
    if (empty($records)) {
        echo $OUTPUT->notification(get_string('facetoface:norecordsfound', 'facetoface'), 'info');
    }

    if (!empty($records)) {
        $table = new html_table();
        $table->attributes['class'] = 'f2fconfirmuploadlist m-auto generaltable mb-2';
        $table->head = bulk_session_manager::get_headers();

        foreach ($records as $record) {
            $table->data[] = array_values($record);
        }
        echo html_writer::tag('div', html_writer::table($table), ['class' => 'flexible-wrap mb-4']);
    }

    // Display the confirm form.
    $confirmform->display();
    echo $OUTPUT->footer();
    exit;
}



if (
    $process &&
    $fileid &&
    $f
    ) {
    $manager = new bulk_session_manager($f);
    $manager->load_from_file($fileid);

    $confirmform = new bulk_session_confirm_form(null, ['f' => $f, 'fileid' => $fileid]);

    if ($confirmform->is_cancelled()) {
        redirect(new moodle_url('/mod/facetoface/uploadbulksessions.php', ['f' => $f]));

        exit;
    }

    $confirmdata = $confirmform->get_data();
    $errors = $manager->validate();

    if (empty($errors)) {
         $manager->process();

         $params = [
             'context'  => $modulecontext,
             'objectid' => $f,
         ];

         $event = \mod_facetoface\event\csv_processed_bulksession::create($params);
         $event->add_record_snapshot('facetoface', $facetoface);
         $event->trigger();

         redirect(
             new moodle_url('/mod/facetoface/uploadbulksessions.php', ['f' => $f]),
             get_string('facetoface:bulksessionsprocessed', 'mod_facetoface'),
             null,
             notification::NOTIFY_SUCCESS
         );
    }

    handle_bulk_upload_errors($errors);
}

// Default display: show the upload form.
$uploadform->set_data(['f' => $f, 'validate' => 1]);

echo $OUTPUT->header();

$uploadform->display();

echo $OUTPUT->footer();
