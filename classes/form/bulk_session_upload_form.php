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
     * Build form for importing bookings.
     *
     * {@inheritDoc}
     *
     * @see \moodleform::definition()
     */
    public function definition() {
        global $CFG;

        $mform = $this->_form;

        $f = $this->_customdata['f'] ?? 0;
        $mform->addElement('hidden', 'f', $f);
        $mform->setType('f', PARAM_INT);

        $mform->addElement('header', 'settingsheader', get_string('facetoface:uploadbulksessions', 'mod_facetoface'));

        // Example CSV link.
        $url = new \moodle_url('/mod/facetoface/example_bulk.csv');
        $link = \html_writer::link($url, get_string('examplecsv', 'mod_facetoface'));
        $mform->addElement('static', 'example_bulkcsv', get_string('facetoface:examplecsv', 'mod_facetoface'), $link);

        // File manager for CSV upload.
        $maxbytes = get_max_upload_file_size($CFG->maxbytes, 0);
        $mform->addElement('filemanager', 'csvfile', get_string('facetoface:uploadsessionfile', 'mod_facetoface'), null, [
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => 'csv',
            'maxbytes' => $maxbytes,
            'return_types' => FILE_INTERNAL | FILE_EXTERNAL,
        ]);

        $mform->setType('csvfile', PARAM_INT);
        $mform->addRule('csvfile', get_string('required'), 'required', null, 'client');

        // Get the restructured description.
        $desc = get_string('facetoface:uploadsessionfiledesc', 'mod_facetoface');

        // Split into rows by newlines.
        $rows = explode("\n", $desc);

        // Start building the table HTML.
        $tablehtml = '<table class="uploadsessiondesc" border="1" cellspacing="0" cellpadding="5">';
        $tablehtml .= '<thead><tr><th>Field</th><th>Description</th></tr></thead><tbody>';

        // Process each row.
        foreach ($rows as $row) {
            $row = trim($row);
            if (!empty($row)) {
                // Split the row by the pipe delimiter.
                $parts = explode('|', $row);
                if (count($parts) == 2) {
                    $field = trim($parts[0]);
                    $descpart = trim($parts[1]);
                    $tablehtml .= '<tr>';
                    $tablehtml .= '<td>' . htmlspecialchars($field) . '</td>';
                    $tablehtml .= '<td>' . htmlspecialchars($descpart) . '</td>';
                    $tablehtml .= '</tr>';
                }
            }
        }
        $tablehtml .= '</tbody></table>';

        // Add the static element using the HTML table.
        $mform->addElement('static', 'csvuploadhelp', '', $tablehtml);

        // Hidden field to validate/process after upload.
        $mform->addElement('hidden', 'validate', 1);
        $mform->setType('validate', PARAM_INT);

        $mform->addElement('submit', 'submit', get_string('facetoface:uploadandpreviewbulk', 'mod_facetoface'));
    }
}
