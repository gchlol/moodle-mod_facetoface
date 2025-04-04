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
require_once($CFG->libdir.'/formslib.php');

/**
 * Form for uploading bulk session CSV files in Face-to-Face module.
 * Provides file selection, validation, and preview before processing.
 *
 * @package   mod_facetoface
 * @copyright 2025, Gold Coast Health
 * @author      Jonas Sajonas
 * @license      http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class site_bulk_session_upload_form extends moodleform {

    /**
     * Build form for importing Face-to-Face session data.
     *
     * @return void
     */
    public function definition() {
        global $CFG;

        $mform = $this->_form;

        // Retrieve a validate flag from customdata, defaulting to 0 if not provided.
        $validateflag = $this->_customdata['validate'] ?? 0;

        // Pass validation flag through the form.
        $mform->addElement('hidden', 'validate', $validateflag);
        $mform->setType('validate', PARAM_INT);

        // Add a header section to group form elements.
        $mform->addElement(
            'header',
            'sitebulkuploadheader',
            get_string('facetoface:sitebulkuploadheader', 'mod_facetoface')
        );

        // Example CSV file.
        $url = new moodle_url('/mod/facetoface/sitewide_bulkexample.csv');
        $link = html_writer::link($url, get_string('examplecsv', 'mod_facetoface'));
        $mform->addElement(
            'static',
            'examplecsv',
            get_string('facetoface:examplecsv', 'mod_facetoface'),
            $link
        );

        // File manager set for CSV upload.
        $maxbytes = get_max_upload_file_size($CFG->maxbytes, 0);
        $mform->addElement('filemanager', 'csvfile',
            get_string('facetoface:uploadsessionfile', 'mod_facetoface'),
            null,
            [
                'subdirs' => 0,
                'maxfiles' => 1,
                'accepted_types' => 'csv',
                'maxbytes' => $maxbytes,
                'return_types' => FILE_INTERNAL | FILE_EXTERNAL,
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

        // Add a static element to display CSV field descriptions.
        $mform->addElement('static', 'csvuploadhelp', '', $tablehtml);

        // Checkbox to allow user to ignore case sensitivity when validating.
        $mform->addElement('advcheckbox', 'caseinsensitive', get_string('caseinsensitive', 'mod_facetoface'));
        $mform->setDefault('caseinsensitive', true);

        $this->add_action_buttons(
            true,
            get_string('facetoface:uploadandpreviewbulk', 'mod_facetoface')
        );
    }

    /**
     * Builds and returns an HTML table describing the required CSV fields for bulk sessions.
     *
     * @return string HTML for the help table.
     */
    private function generate_csv_help_table(): string {
        $table = new html_table();

        $table->head = [
            get_string('csvuploadhelp:field', 'mod_facetoface'),
            get_string('csvuploadhelp:requirement', 'mod_facetoface'),
            get_string('csvuploadhelp:format', 'mod_facetoface'),
        ];

        $rows = [
            [
                'field' => 'csvuploadhelp:courseshortname',
                'requirement' => 'csvuploadhelp:required',
                'format' => 'csvuploadhelp:text',
            ],
            [
                'field' => 'csvuploadhelp:activityname',
                'requirement' => 'csvuploadhelp:required',
                'format' => 'csvuploadhelp:text',
            ],
            [
                'field' => 'csvuploadhelp:fieldsessiondatetime',
                'requirement' => 'csvuploadhelp:required',
                'format' => 'csvuploadhelp:yesorno',
            ],
            [
                'field' => 'csvuploadhelp:startdate',
                'requirement' => 'csvuploadhelp:required',
                'format' => 'csvuploadhelp:date',
            ],
            [
                'field' => 'csvuploadhelp:starttime',
                'requirement' => 'csvuploadhelp:required',
                'format' => 'csvuploadhelp:time',
            ],
            [
                'field' => 'csvuploadhelp:finishdate',
                'requirement' => 'csvuploadhelp:required',
                'format' => 'csvuploadhelp:date',
            ],
            [
                'field' => 'csvuploadhelp:finishtime',
                'requirement' => 'csvuploadhelp:required',
                'format' => 'csvuploadhelp:time',
            ],
            [
                'field' => 'csvuploadhelp:allowcancellations',
                'requirement' => 'csvuploadhelp:optional',
                'format' => 'csvuploadhelp:yesorno',
            ],
            [
                'field' => 'csvuploadhelp:capacity',
                'requirement' => 'csvuploadhelp:required',
                'format' => 'csvuploadhelp:num',
            ],
            [
                'field' => 'csvuploadhelp:allowoverbookings',
                'requirement' => 'csvuploadhelp:optional',
                'format' => 'csvuploadhelp:yesorno',
            ],
            [
                'field' => 'csvuploadhelp:duration',
                'requirement' => 'csvuploadhelp:required',
                'format' => 'csvuploadhelp:mins',
            ],
            [
                'field' => 'csvuploadhelp:cost',
                'requirement' => 'csvuploadhelp:optional',
                'format' => 'csvuploadhelp:num',
            ],
            [
                'field' => 'csvuploadhelp:discount',
                'requirement' => 'csvuploadhelp:optional',
                'format' => 'csvuploadhelp:num',
            ],
            [
                'field' => 'csvuploadhelp:details',
                'requirement' => 'csvuploadhelp:optional',
                'format' => 'csvuploadhelp:text',
            ],
            [
                'field' => 'csvuploadhelp:customfield',
                'requirement' => 'csvuploadhelp:optional',
                'format' => 'csvuploadhelp:text',
            ],
        ];

        // Build each table row by looping over the $rows array.
        foreach ($rows as $row) {
            $table->data[] = [
                get_string($row['field'], 'mod_facetoface'),
                get_string($row['requirement'], 'mod_facetoface'),
                get_string($row['format'], 'mod_facetoface'),
            ];
        }

        return html_writer::table($table);
    }
}
