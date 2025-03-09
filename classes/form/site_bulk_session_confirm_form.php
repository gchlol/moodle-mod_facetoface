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
use moodleform;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir.'/formslib.php');

/**
 * A Moodle form that collects confirmation details (e.g., suppressing emails)
 * before finalizing bulk session creation from CSV data.
 */
class site_bulk_session_confirm_form extends moodleform {

    /**
     * Defines elements and layouts on site-level confirmation form.
     */
    public function definition() {
        $mform = $this->_form;

        // Retrieve file ID from customdata, which references CSV file in draft.
        $fileid = $this->_customdata['fileid'] ?? 0;

        // Checkbox to allow user to suppress email notifications.
        $mform->addElement('advcheckbox', 'suppressemail', get_string('suppressemail', 'facetoface'));
        $mform->setType('suppressemail', PARAM_BOOL);

        // Hidden element to store file ID and pass it to form submission.
        $mform->addElement('hidden', 'fileid', $fileid);
        $mform->setType('fileid', PARAM_INT);

        // Hidden marker to indicate we're processing this form.
        $mform->addElement('hidden', 'process', 1);
        $mform->setType('process', PARAM_INT);

        // Button group: confirm or cancel.
        $buttonarray = [];
        $buttonarray[] = $mform->createElement(
            'submit',
            'confirmbtn',
            get_string('facetoface:confirmandprocess', 'mod_facetoface')
        );
        $buttonarray[] = $mform->createElement('cancel');

        $mform->addGroup($buttonarray, 'buttonar', '', ' ', false);
    }
}
