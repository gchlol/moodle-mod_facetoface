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
 * Form for uploading bulk session CSV files in Face-to-Face module.
 * Provides file selection, validation, and preview before processing.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_facetoface\form;
use moodleform;
use html_writer;
use html_table;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir.'/formslib.php');
require_once($CFG->dirroot.'/repository/lib.php');

/**
 * Base form for uploading bulk session CSV (common fields for activity and site).
 *
 * @package   mod_facetoface
 */
abstract class bulk_session_upload_form_parent extends moodleform {
    protected string $formelementkey;
    protected string $headername;
    protected string $headerstring;

    /**
     * Get the form page-description label.
     *
     * @return string Localised form header string.
     */
    abstract protected function get_form_header(): string;

    /**
     * Get the HTML anchor (<a>) tag for the example CSV file.
     *
     * @return string HTML link element for the example CSV (context-specific file).
     */
    abstract protected function get_example_csv(): string;


    /**
     * Build the form elements common to both contexts.
     */
    public function definition(): void {
        global $CFG;

        $mform = $this->_form;

        $f2fidkey = $this->formelementkey;
        $f2fid = $this->_customdata[$f2fidkey] ?? 0;
        $mform->addElement('hidden', $f2fidkey, $f2fid);
        $mform->setType($f2fidkey, PARAM_INT);

        $mform->addElement(
            'header',
            'bulksessionsheader',
            $this->get_form_header()
        );

        // Example CSV file link (context-specific).
        $example = $this->get_example_csv();
        $mform->addElement('static', 'examplecsv', get_string('examplecsv', 'mod_facetoface'), $example);

        // File manager for CSV upload.
        $maxbytes = get_max_upload_file_size($CFG->maxbytes);
        $mform->addElement('filemanager', 'csvfile',
            get_string('uploadsessionfile', 'mod_facetoface'),
            null,
            [
                'subdirs'        => 0,
                'maxfiles'       => 1,
                'accepted_types' => ['csv'],
                'maxbytes'       => $maxbytes,
                'return_types'   => FILE_INTERNAL | FILE_EXTERNAL
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

        // Help table describing CSV fields.
        $tablehtml = $this->generate_csv_help_table();

        $mform->addElement('static', 'csvuploadhelp', '', $tablehtml);

        // Hidden field to trigger validation on submission.
        $validateFlag = $this->_customdata['validate'] ?? 1;

        $mform->addElement('hidden', 'validate', $validateFlag);
        $mform->setType('validate', PARAM_INT);

        $this->add_action_buttons(
            true,
            get_string('uploadandpreviewbulk', 'mod_facetoface')
        );
    }

    /**
     * Builds an HTML table describing required CSV fields.
     *
     * @return string HTML for the help table.
     */
    protected function generate_csv_help_table(): string {
        $table = new html_table();
        $table->head = [
            get_string('csvuploadhelp:field', 'mod_facetoface'),
            get_string('csvuploadhelp:requirement', 'mod_facetoface'),
            get_string('csvuploadhelp:format', 'mod_facetoface')
        ];

        // Get field definitions (context-specific implementation).
        $rows = $this->get_csv_field_definitions();

        foreach ($rows as $row) {
            $table->data[] = [
                get_string($row['field'], 'mod_facetoface'),
                get_string($row['requirement'], 'mod_facetoface'),
                get_string($row['format'], 'mod_facetoface')
            ];
        }

        return html_writer::table($table);
    }

    /**
     * Retrieves the definitions for the fields required in a CSV upload process.
     * Each row must contain all three keys.
     *
     * @return array The list of field definitions.
     */
    protected function get_csv_field_definitions(): array {
        return [
            ['field' => 'csvuploadhelp:fieldsessiondatetime', 'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:yesorno'],
            ['field' => 'csvuploadhelp:startdate',            'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:date'],
            ['field' => 'csvuploadhelp:starttime',            'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:time'],
            ['field' => 'csvuploadhelp:finishdate',           'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:date'],
            ['field' => 'csvuploadhelp:finishtime',           'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:time'],
            ['field' => 'csvuploadhelp:allowcancellations',   'requirement' => 'csvuploadhelp:optional',  'format' => 'csvuploadhelp:yesorno'],
            ['field' => 'csvuploadhelp:capacity',             'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:num'],
            ['field' => 'csvuploadhelp:allowoverbookings',    'requirement' => 'csvuploadhelp:optional',  'format' => 'csvuploadhelp:yesorno'],
            ['field' => 'csvuploadhelp:duration',             'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:mins'],
            ['field' => 'csvuploadhelp:cost',                 'requirement' => 'csvuploadhelp:optional',  'format' => 'csvuploadhelp:num'],
            ['field' => 'csvuploadhelp:discount',             'requirement' => 'csvuploadhelp:optional',  'format' => 'csvuploadhelp:num'],
            ['field' => 'csvuploadhelp:details',              'requirement' => 'csvuploadhelp:optional',  'format' => 'csvuploadhelp:text'],
            ['field' => 'csvuploadhelp:customfieldfacility',  'requirement' => 'csvuploadhelp:optional',  'format' => 'csvuploadhelp:text'],
            ['field' => 'csvuploadhelp:customfieldlocation',  'requirement' => 'csvuploadhelp:optional',  'format' => 'csvuploadhelp:text'],
            ['field' => 'csvuploadhelp:customfieldroom',      'requirement' => 'csvuploadhelp:optional',  'format' => 'csvuploadhelp:text']
        ];
    }}
