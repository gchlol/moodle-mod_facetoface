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
class bulk_session_upload_form extends moodleform {
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

        $mform->addElement(
            'header',
            'settingsheader',
            get_string('facetoface:uploadbulksessions', 'mod_facetoface')
        );

        // Example CSV link.
        $url = new moodle_url('/mod/facetoface/example_bulk.csv');
        $link = html_writer::link($url, get_string('examplecsv', 'mod_facetoface'));
        $mform->addElement(
            'static',
            'example_bulkcsv',
            get_string('facetoface:examplecsv', 'mod_facetoface'),
            $link
        );

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
        $mform->addRule(
            'csvfile',
            get_string('required'),
            'required',
            null,
            'client'
        );

        // Generate the table HTML via the helper function.
        $tablehtml = $this->generate_csv_help_table();

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

    /**
     * Creates the HTML table describing the required CSV fields.
     *
     * @return string HTML for the help table.
     */
    private function generate_csv_help_table(): string {
        $table = new html_table();

        // Header.
        $table->head = [
            get_string('csvuploadhelp:field', 'mod_facetoface'),
            get_string('csvuploadhelp:requirement', 'mod_facetoface'),
            get_string('csvuploadhelp:format', 'mod_facetoface'),
        ];

        // Session date / time known.
        $table->data[] = [
            get_string('csvuploadhelp:fieldsessiondatetime', 'mod_facetoface'),
            get_string('csvuploadhelp:required', 'mod_facetoface'),
            get_string('csvuploadhelp:yesorno', 'mod_facetoface'),
        ];

        // Start date.
        $table->data[] = [
            get_string('csvuploadhelp:startdate', 'mod_facetoface'),
            get_string('csvuploadhelp:required', 'mod_facetoface'),
            get_string('csvuploadhelp:date', 'mod_facetoface'),
        ];

        // Start time.
        $table->data[] = [
            get_string('csvuploadhelp:starttime', 'mod_facetoface'),
            get_string('csvuploadhelp:required', 'mod_facetoface'),
            get_string('csvuploadhelp:time', 'mod_facetoface'),
        ];

        // Finish date.
        $table->data[] = [
            get_string('csvuploadhelp:finishdate', 'mod_facetoface'),
            get_string('csvuploadhelp:required', 'mod_facetoface'),
            get_string('csvuploadhelp:date', 'mod_facetoface'),
        ];

        // Finish time.
        $table->data[] = [
            get_string('csvuploadhelp:finishtime', 'mod_facetoface'),
            get_string('csvuploadhelp:required', 'mod_facetoface'),
            get_string('csvuploadhelp:time', 'mod_facetoface'),
        ];

        // Allow cancellations.
        $table->data[] = [
            get_string('csvuploadhelp:allowcancellations', 'mod_facetoface'),
            get_string('csvuploadhelp:optional', 'mod_facetoface'),
            get_string('csvuploadhelp:yesorno', 'mod_facetoface'),
        ];

        // Capacity.
        $table->data[] = [
            get_string('csvuploadhelp:capacity', 'mod_facetoface'),
            get_string('csvuploadhelp:required', 'mod_facetoface'),
            get_string('csvuploadhelp:num', 'mod_facetoface'),
        ];

        // Allow overbookings.
        $table->data[] = [
            get_string('csvuploadhelp:allowoverbookings', 'mod_facetoface'),
            get_string('csvuploadhelp:optional', 'mod_facetoface'),
            get_string('csvuploadhelp:yesorno', 'mod_facetoface'),
        ];

        // Duration.
        $table->data[] = [
            get_string('csvuploadhelp:duration', 'mod_facetoface'),
            get_string('csvuploadhelp:required', 'mod_facetoface'),
            get_string('csvuploadhelp:mins', 'mod_facetoface'),
        ];

        // Normal Cost.
        $table->data[] = [
            get_string('csvuploadhelp:cost', 'mod_facetoface'),
            get_string('csvuploadhelp:optional', 'mod_facetoface'),
            get_string('csvuploadhelp:num', 'mod_facetoface'),
        ];

        // Discount Cost.
        $table->data[] = [
            get_string('csvuploadhelp:discount', 'mod_facetoface'),
            get_string('csvuploadhelp:optional', 'mod_facetoface'),
            get_string('csvuploadhelp:num', 'mod_facetoface'),
        ];

        // Details.
        $table->data[] = [
            get_string('csvuploadhelp:details', 'mod_facetoface'),
            get_string('csvuploadhelp:optional', 'mod_facetoface'),
            get_string('csvuploadhelp:stringtext', 'mod_facetoface'),
        ];

        // Custom fields.
        $table->data[] = [
            get_string('csvuploadhelp:customfield', 'mod_facetoface'),
            get_string('csvuploadhelp:optional', 'mod_facetoface'),
            get_string('csvuploadhelp:customfieldtext', 'mod_facetoface'),
        ];

        // Return the rendered HTML.
        return html_writer::table($table);
    }

}
