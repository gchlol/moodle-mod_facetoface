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

namespace mod_facetoface\form;

use moodleform;
use moodle_url;
use html_writer;
use html_table;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/repository/lib.php');

/**
 * Form for uploading bulk session CSV files in Face-to-Face module.
 * Provides file selection, validation, and preview before processing.
 *
 * @package     mod_facetoface
 * @copyright   2025 Gold Coast Health
 * @author      Jonas Sajonas
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class bulk_session_upload_form extends \moodleform {
    /**
     * Build form for importing Face-to-Face session data.
     *
     * @return void
     */
    public function definition(): void {
        global $CFG;

        $mform = $this->_form;

        $f2fid = $this->_customdata['f'] ?? 0;
        $mform->addElement('hidden', 'f', $f2fid);
        $mform->setType('f', PARAM_INT);

        $mform->addElement('header', 'settingsheader', get_string('facetoface:uploadbulksessions', 'mod_facetoface'));

        // Example CSV link.
        $url = new moodle_url('/mod/facetoface/example_bulk.csv');
        $link = html_writer::link($url, get_string('examplecsv', 'mod_facetoface'));
        $mform->addElement('static', 'example_bulkcsv', get_string('facetoface:examplecsv', 'mod_facetoface'), $link);

        // File manager for CSV upload.
        $maxbytes = get_max_upload_file_size($CFG->maxbytes);
        $mform->addElement('filemanager', 'csvfile',
            get_string('facetoface:uploadsessionfile', 'mod_facetoface'),
            null,
            [
                'subdirs'       => 0,
                'maxfiles'      => 1,
                'accepted_types' => 'csv',
                'maxbytes'      => $maxbytes,
                'return_types'  => FILE_INTERNAL | FILE_EXTERNAL,
            ]
        );
        $mform->setType('csvfile', PARAM_INT);
        $mform->addRule('csvfile', get_string('required'), 'required', null, 'client');

        // Build a table with 3 columns: Field, Required, Format.
        $table = new html_table();
        $table->head = [
            get_string('upload_field', 'mod_facetoface'),
            get_string('upload_required', 'mod_facetoface'),
            get_string('upload_format', 'mod_facetoface')
        ];

        // 1) Session Date/Time Known
        $table->data[] = [
            get_string('upload_field_sessiondatetime', 'mod_facetoface'),
            get_string('upload_req_yesno', 'mod_facetoface'),
            get_string('upload_format_na', 'mod_facetoface')
        ];

        $table->data[] = [
            get_string('upload_field_startdate', 'mod_facetoface'),
            get_string('upload_req_yes', 'mod_facetoface'),
            get_string('upload_format_date', 'mod_facetoface')
        ];

        $table->data[] = [
            get_string('upload_field_starttime', 'mod_facetoface'),
            get_string('upload_req_yes', 'mod_facetoface'),
            get_string('upload_format_time', 'mod_facetoface')
        ];

        $table->data[] = [
            get_string('upload_field_finishdate', 'mod_facetoface'),
            get_string('upload_req_yes', 'mod_facetoface'),
            get_string('upload_format_date', 'mod_facetoface')
        ];

        $table->data[] = [
            get_string('upload_field_finishtime', 'mod_facetoface'),
            get_string('upload_req_yes', 'mod_facetoface'),
            get_string('upload_format_time', 'mod_facetoface')
        ];

        $table->data[] = [
            get_string('upload_field_allowcancellations', 'mod_facetoface'),
            get_string('upload_req_yesno', 'mod_facetoface'),
            get_string('upload_format_na', 'mod_facetoface')
        ];

        $table->data[] = [
            get_string('upload_field_capacity', 'mod_facetoface'),
            get_string('upload_req_yes', 'mod_facetoface'),
            get_string('upload_format_numeric', 'mod_facetoface')
        ];

        $table->data[] = [
            get_string('upload_field_allowoverbookings', 'mod_facetoface'),
            get_string('upload_req_yesno', 'mod_facetoface'),
            get_string('upload_format_na', 'mod_facetoface')
        ];

        $table->data[] = [
            get_string('upload_field_duration', 'mod_facetoface'),
            get_string('upload_req_yes', 'mod_facetoface'),
            get_string('upload_format_numericmins', 'mod_facetoface')
        ];

        $table->data[] = [
            get_string('upload_field_normalcost', 'mod_facetoface'),
            get_string('upload_req_optional', 'mod_facetoface'),
            get_string('upload_format_numeric', 'mod_facetoface')
        ];

        $table->data[] = [
            get_string('upload_field_discountcost', 'mod_facetoface'),
            get_string('upload_req_optional', 'mod_facetoface'),
            get_string('upload_format_numeric', 'mod_facetoface')
        ];

        $table->data[] = [
            get_string('upload_field_details', 'mod_facetoface'),
            get_string('upload_req_optional', 'mod_facetoface'),
            get_string('upload_format_string', 'mod_facetoface')
        ];

        $table->data[] = [
            get_string('upload_field_customfield', 'mod_facetoface'),
            get_string('upload_req_optional', 'mod_facetoface'),
            get_string('upload_format_customfield', 'mod_facetoface')
        ];

        $tablehtml = html_writer::table($table);

        // Add the table as a static element.
        $mform->addElement('static', 'csvuploadhelp', '', $tablehtml);

        // Hidden field to validate/process after upload.
        $mform->addElement('hidden', 'validate', 1);
        $mform->setType('validate', PARAM_INT);

        $this->add_action_buttons(
            true,
            get_string('facetoface:uploadandpreviewbulk', 'mod_facetoface')
        );
    }
}
