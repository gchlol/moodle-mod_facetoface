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

class site_bulk_session_upload_form extends \moodleform {

    /**
     * Defines the form elements.
     */
    public function definition() {
        global $CFG;
        $mform = $this->_form;
        $validateflag = $this->_customdata['validate'] ?? 0;

        $mform->addElement('hidden', 'validate', $validateflag);
        $mform->setType('validate', PARAM_INT);

        $mform->addElement('header', 'sitebulkuploadheader', get_string('sitebulkuploadheader', 'mod_facetoface'));

        // Example CSV link.
        $url = new \moodle_url('/mod/facetoface/sitewide_bulkexample.csv');
        $link = \html_writer::link($url, 'example.csv');
        $mform->addElement('static', 'examplecsv', '', $link);

        // File manager for CSV upload.
        $maxbytes = get_max_upload_file_size($CFG->maxbytes, 0);
        $mform->addElement('filemanager', 'csvfile', get_string('upsf', 'mod_facetoface'), null, [
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => ['.csv'],
            'maxbytes' => $maxbytes,
            'return_types' => FILE_INTERNAL | FILE_EXTERNAL,
        ]);
        $mform->setType('csvfile', PARAM_INT);
        $desc = get_string('sitebulkuploadfiledesc', 'mod_facetoface');
        $rows = explode("\n", $desc);
        $tablehtml = '<table class="uploadsessiondesc" border="1" cellspacing="0" cellpadding="5">';
        $tablehtml .= '<thead><tr><th>Field</th><th>Description</th></tr></thead><tbody>';
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
        $mform->addElement('static', 'csvuploadhelp', '', $tablehtml);
        $mform->addElement('advcheckbox', 'caseinsensitive', get_string('caseinsensitive', 'mod_facetoface'));
        $mform->setDefault('caseinsensitive', true);
        $this->add_action_buttons(false, get_string('upandprev', 'mod_facetoface'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['csvfile'])) {
            $errors['csvfile'] = get_string('required');
        }
        return $errors;
    }


}
