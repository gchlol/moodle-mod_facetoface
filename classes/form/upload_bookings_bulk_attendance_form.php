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

require_once($CFG->dirroot . '/repository/lib.php');
require_once($CFG->libdir.'/formslib.php');

/**
 * Form for uploading bulk attendance CSV files in Face-to-Face settings.
 * Provides file selection, validation, and preview before processing.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_bookings_bulk_attendance_form extends moodleform {

    /**
     * Build form for importing bulk attendance CSV files.
     *
     * @return void
     */
    public function definition(): void {
        global $CFG;

        $mform = $this->_form;

        $mform->addElement('header', 'settingsheader', get_string('uploadbulkbookings', 'mod_facetoface'));

        $url = new moodle_url('/mod/facetoface/example_bookings.csv');
        $link = html_writer::link(
            $url,
            get_string('examplecsvfilename', 'mod_facetoface')
        );

        $mform->addElement(
            'static',
            'examplecsv',
            get_string('examplecsv', 'mod_facetoface'),
            $link
        );

        $maxbytes = get_max_upload_file_size($CFG->maxbytes);
        $mform->addElement(
            'filemanager',
            'csvfile',
            get_string('uploadbulkbookingsfile', 'mod_facetoface'),
            null, [
                'subdirs' => 0,
                'maxfiles' => 1,
                'accepted_types' => 'csv',
                'maxbytes' => $maxbytes,
                'return_types' => FILE_INTERNAL | FILE_EXTERNAL,
            ]
        );

        $mform->setType('csvfile', PARAM_INT);
        $mform->addRule('csvfile', get_string('required'), 'required', null, 'client');

        // Help table showing required CSV headers.
        $tablehtml = $this->generate_csv_help_table();
        $mform->addElement('static', 'csvuploadhelp', '', $tablehtml);

        $mform->addElement('advcheckbox', 'caseinsensitive', get_string('caseinsensitive', 'mod_facetoface'));
        $mform->setDefault('caseinsensitive', true);

        // Hidden fields that control processing.
        $mform->addElement('hidden', 'validate');
        $mform->setType('validate', PARAM_INT);

        $customdata = $this->_customdata ?? [];
        if (!empty($customdata['validate'])) {
            $mform->setDefault('validate', $customdata['validate']);
        }

        $this->add_action_buttons(
            true,
            get_string('uploadandpreviewbulkbookings', 'mod_facetoface')
        );
    }

    /**
     * Builds and returns an HTML table describing the required CSV fields for bulk attendance.
     *
     * @return string HTML for the help table.
     */
    private function generate_csv_help_table(): string {
        $table = new html_table();

        // Table headers.
        $table->head = [
            get_string('csvuploadhelp:field', 'facetoface'),
            get_string('csvuploadhelp:requirement', 'facetoface'),
            get_string('csvuploadhelp:format', 'facetoface'),
        ];

        // Rows for each required column.
        $rows = [
            [
                'field'       => 'csvuploadhelp:courseshortname',
                'requirement' => 'csvuploadhelp:required',
                'format'      => 'csvuploadhelp:text',
            ],
            [
                'field'       => 'csvuploadhelp:activityname',
                'requirement' => 'csvuploadhelp:required',
                'format'      => 'csvuploadhelp:text',
            ],
            [
                'field'       => 'csvuploadhelp:username',
                'requirement' => 'csvuploadhelp:required',
                'format'      => 'csvuploadhelp:text',
            ],
            [
                'field'       => 'csvuploadhelp:session',
                'requirement' => 'csvuploadhelp:required',
                'format'      => 'csvuploadhelp:num',
            ],
            [
                'field'       => 'csvuploadhelp:status',
                'requirement' => 'csvuploadhelp:optional',
                'format'      => 'csvuploadhelp:statustype',
            ],
            [
                'field'       => 'csvuploadhelp:discountcode',
                'requirement' => 'csvuploadhelp:optional',
                'format'      => 'csvuploadhelp:text',
            ],
            [
                'field'       => 'csvuploadhelp:notificationtype',
                'requirement' => 'csvuploadhelp:optional',
                'format'      => 'csvuploadhelp:oneofnotif',
            ],
        ];

        foreach ($rows as $row) {
            $table->data[] = [
                get_string($row['field'], 'facetoface'),
                get_string($row['requirement'], 'facetoface'),
                get_string($row['format'], 'facetoface'),
            ];
        }

        return html_writer::table($table);
    }
}
