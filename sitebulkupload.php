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
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');

use core\output\notification;
use mod_facetoface\form\site_bulk_session_upload_form;
use mod_facetoface\form\site_bulk_session_confirm_form;
use mod_facetoface\site_bulk_manager;

admin_externalpage_setup('modfacetoface_sitebulkupload');

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
        'validate' => 1
    ]);

    echo $OUTPUT->header();
    $mform->display();
    echo $OUTPUT->footer();
    exit;
}

if ($validate) {
    $mform = new site_bulk_session_upload_form();
    $data  = $mform->get_data();

    if (!$data  || empty($data->csvfile)) {
        echo $OUTPUT->header();
        echo $OUTPUT->notification(get_string('error:choosecsv', 'mod_facetoface'), 'error');
        $mform->display();
        echo $OUTPUT->footer();
        exit;
    }

    $fileid = $data->csvfile ?: 0;
    $confirmform = new site_bulk_session_confirm_form(null, [
        'fileid' => $fileid,
        'caseinsensitive' => $caseinsensitive,
        'process' => 1
    ]);

    $manager = new site_bulk_manager();
    $manager->load_from_file($fileid);
    $manager->set_case_insensitive($caseinsensitive);
    $errors = $manager->validate();

    if (!empty($errors)) {
        echo $OUTPUT->header();
        echo $OUTPUT->notification(
            get_string('error:bulkuploadfileerrorsfound', 'mod_facetoface', count($errors)),
            notification::NOTIFY_ERROR
        );

        $table = new html_table();
        $table->attributes['class'] = 'f2fbookingsuploadlist generaltable mb-2';
        $table->head = [
            get_string('uucsvline', 'tool_uploaduser'),
            get_string('status', 'facetoface')
        ];

        foreach ($errors as $error) {
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

        echo html_writer::tag('p',
        html_writer::link(
            new moodle_url('/mod/facetoface/sitebulkupload.php'),
            get_string('back')
        )
        );
        echo $OUTPUT->footer();
        exit;
    }

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


if ($process && $fileid) {
    $manager = new site_bulk_manager();
    $manager->load_from_file($fileid);
    $manager->set_case_insensitive($caseinsensitive);

    $confirmform = new site_bulk_session_confirm_form();

    if ($confirmform->is_cancelled()) {
        redirect(new moodle_url('/mod/facetoface/sitebulkupload.php'));
    }
    $confirmdata = $confirmform->get_data();
    if (!empty($confirmdata->suppressemail)) {
        $manager->suppress_email();
    }

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
