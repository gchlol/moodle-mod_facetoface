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

namespace mod_facetoface\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/repository/lib.php');
require_once($CFG->libdir.'/formslib.php');


/**
 * A form that facilitates the uploading of a CSV file containing session data,
 * along with options to validate or process the uploaded data.
 */
class site_bulk_session_upload_form extends \moodleform {

    /**
     * Defines the form elements.
     */
    public function definition() {
        global $CFG;

        // MoodleForm object instance to add elements.
        $mform = $this->_form;

        // Retrieve a validate flag from customdata, defaulting to 0 if not provided.
        $validateflag = $this->_customdata['validate'] ?? 0;

        // Hidden element to store the validation flag and carry it through form submissions.
        $mform->addElement('hidden', 'validate', $validateflag);
        $mform->setType('validate', PARAM_INT);

        // Add a header section to group form elements.
        $mform->addElement('header', 'sitebulkuploadheader', get_string('sitebulkuploadheader', 'mod_facetoface'));

        // Link to an example CSV file.
        $url = new \moodle_url('/mod/facetoface/sitewide_bulkexample.csv');
        $link = \html_writer::link($url, 'example.csv');
        $mform->addElement('static', 'examplecsv', '', $link);

        // File manager set for CSV upload.
        $maxbytes = get_max_upload_file_size($CFG->maxbytes, 0);
        $mform->addElement('filemanager', 'csvfile', get_string('upsf', 'mod_facetoface'), null, [
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => ['.csv'],
            'maxbytes' => $maxbytes,
            'return_types' => FILE_INTERNAL | FILE_EXTERNAL,
        ]);
        $mform->setType('csvfile', PARAM_INT);  // Store a  file ID.

        // Description of CSV fields in a table format.
        $desc = get_string('sitebulkuploadfiledesc', 'mod_facetoface');
        $rows = explode("\n", $desc);

        // Build HTML for a simple table listing each CSV field and its description.
        $tablehtml = '<table class="uploadsessiondesc" border="1" cellspacing="0" cellpadding="5">';
        $tablehtml .= '<thead><tr><th>Field</th><th>Description</th></tr></thead><tbody>';

        // Iterate over each row in the description and add a table row for each.
        foreach ($rows as $row) {
            $row = trim($row);
            if (!empty($row)) {
                $parts = explode('|', $row);
                if (count($parts) === 2) {
                    $field = trim($parts[0]);
                    $descpart = trim($parts[1]);
                    $tablehtml .= '<tr><td>'.htmlspecialchars($field).'</td>';
                    $tablehtml .= '<td>'.htmlspecialchars($descpart).'</td></tr>';
                }
            }
        }
        $tablehtml .= '</tbody></table>';

        // Add a static element to display CSV field descriptions.
        $mform->addElement('static', 'csvuploadhelp', '', $tablehtml);

        // Checkbox to allow user to ignore case sensitivity when validating.
        $mform->addElement('advcheckbox', 'caseinsensitive', get_string('caseinsensitive', 'mod_facetoface'));
        $mform->setDefault('caseinsensitive', true);

        // Button group: validate or process.
        $this->add_action_buttons(false, get_string('upandprev', 'mod_facetoface'));
    }

    /**
     * Validate the form data. Ensures that a file is indeed provided for CSV upload.
     *
     * @param array $data  Submitted form data.
     * @param array $files Files uploaded through the form.
     * @return array Array of validation errors, if any.
     */
    public function validation($data, $files) {
        // Use parent validation first to check basic constraints set by MoodleForm.
        $errors = parent::validation($data, $files);

        // Check if a CSV file was provided.
        // If not, add an error.
        if (empty($data['csvfile'])) {
            $errors['csvfile'] = get_string('required');
        }
        return $errors;
    }


}
