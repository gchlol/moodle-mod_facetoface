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
use moodle_url;
use html_writer;

defined('MOODLE_INTERNAL') || die();

/**
 * CSV upload form for Face-to-Face site-wide (admin context).
 *
 * @package   mod_facetoface
 */
class bulk_session_upload_form_sitelevel extends bulk_session_upload_form_parent {
    protected string $formelementkey = 'f2fid';
    protected string $headername = 'sitebulkuploadheader';
    public function __construct() {
        parent::__construct();

        $this->headerstring = get_string('sitebulkuploadheader', 'mod_facetoface');
    }

    protected function get_form_header(): string {
        return get_string('sitebulkuploadheader', 'mod_facetoface');
    }

    protected function get_example_csv(): string {
        $url = new moodle_url('/mod/facetoface/example_bulksessions.csv');
        // 'examplebulksessionscsv' points to "example_bulksessions.csv".
        return html_writer::link($url, get_string('examplebulksessionscsv', 'mod_facetoface'));
    }

    protected function get_csv_field_definitions(): array {
        $fields = [
            ['field' => 'csvuploadhelp:courseshortname',    'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:text'],
            ['field' => 'csvuploadhelp:activityname',       'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:text']
        ];

        return array_merge($fields, parent::get_csv_field_definitions());
    }
}
