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
 * Handles bulk session uploads for the Face-to-Face module site level.
 * Manages CSV validation, preview, and session creation.
 * @package   mod_facetoface
 * @copyright 2025, Gold Coast Health
 * @author    Jonas Sajonas
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');

use core\output\notification;
use mod_facetoface\form\site_bulk_session_upload_form;
use mod_facetoface\form\site_bulk_session_confirm_form;
use mod_facetoface\site_bulk_manager;
use mod_facetoface\event\csv_processed_sitebulksession;

// Set up the external admin page (in Site administration > Plugins > Face-to-face).
admin_externalpage_setup('modfacetoface_sitebulkupload');

$fileid = optional_param('fileid', 0, PARAM_INT);
$validate = optional_param('validate', 0, PARAM_INT);
$process  = optional_param('process', 0, PARAM_INT);

// Require site configuration capability.
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/mod/facetoface/sitebulkupload.php');
$PAGE->set_title(get_string('facetoface:sitebulksessions', 'mod_facetoface'));
$PAGE->set_heading(get_string('pluginname', 'mod_facetoface'));

// Instantiate the upload form once.
$uploadform = new site_bulk_session_upload_form();

/**
 * Utility function to display bulk-upload errors and then stop execution.
 *
 * @param array $errors A list of errors (each error can be a simple string
 * @return void
 */
function display_bulk_upload_errors($errors): void {
    global $OUTPUT;

    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        get_string('error:uploadsessionserrorsfound', 'mod_facetoface', count($errors)),
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
            $table->data[] = ["-", is_string($error) ? $error : json_encode($error)];

            continue;
        }

        $line = $error[0] + 2;
        $messages = array_slice($error, 1);

        foreach ($messages as $message) {
            $table->data[] = [$line, $message];
        }
    }

    echo html_writer::tag('div', html_writer::table($table), ['class' => 'flexible-wrap mb-4']);

    echo $OUTPUT->single_button(
        new moodle_url('/mod/facetoface/sitebulkupload.php'),
        get_string('back'),
        'get',
        ['class' => 'mb-4']
    );

    echo $OUTPUT->footer();

    exit;
}

if ($validate) {
    $data  = $uploadform->get_data();

    if ($uploadform->is_cancelled()) {
        redirect(new moodle_url('/admin/search.php') . '#linkmodules');

        exit;
    }

    $fileid = $data->csvfile ?: 0;

    $confirmform = new site_bulk_session_confirm_form(
        null, [
            'fileid' => $fileid,
            'process' => 1
        ]
    );

    $manager = new site_bulk_manager();
    $manager->load_from_file($fileid);
    $errors = $manager->validate();

    // If there are errors, handle them and exit.
    if (!empty($errors)) {
        display_bulk_upload_errors($errors);
    }

     // If no errors, display the CSV preview.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('confirmbulkpreview', 'mod_facetoface'), 3);

    $records = $manager->get_records();
    if (empty($records)) {
        echo $OUTPUT->notification(get_string('norecordsfound', 'mod_facetoface'), notification::NOTIFY_INFO);
    }

    // If validation errors exist, display them and stop.
    if (!empty($records)) {
        $table = new html_table();
        $table->attributes['class'] = 'f2fconfirmuploadlist m-auto generaltable mb-2';

        $firstrecord = reset($records);
        $headers = array_keys($firstrecord);

        $table->head = $headers;

        foreach ($records as $record) {
            $rowdata = [];
            foreach ($headers as $h) {
                $rowdata[] = $record[$h] ?? '';
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
    $manager = new site_bulk_manager();
    $manager->load_from_file($fileid);
    $confirmform = new site_bulk_session_confirm_form(null, ['fileid' => $fileid]);

    if ($confirmform->is_cancelled()) {
        redirect(new moodle_url('/mod/facetoface/sitebulkupload.php'));

        exit;
    }

    $errors = $manager->validate();

    if (empty($errors)) {
        $success = $manager->process();

        if ($success) {

            $params = [
                'context' => context_system::instance(),
                'objectid' => 0,
            ];

            $event = csv_processed_sitebulksession::create($params);
            $event->trigger();

            redirect(
                new moodle_url('/mod/facetoface/sitebulkupload.php'),
                get_string('bulksessionsprocessed', 'mod_facetoface'),
                null,
                notification::NOTIFY_SUCCESS
            );
        } else {
            display_bulk_upload_errors($manager->get_errors());
        }
    }

    display_bulk_upload_errors($errors);
}

$uploadform->set_data(['validate' => 1]);

echo $OUTPUT->header();

$uploadform->display();

echo $OUTPUT->footer();
