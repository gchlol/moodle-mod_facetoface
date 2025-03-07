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


class site_bulk_session_confirm_form extends moodleform {

    /**
     * Defines elements on the site-level confirmation form.
     */
    public function definition() {
        $mform = $this->_form;

        $fileid = $this->_customdata['fileid'] ?? 0;

        // Example checkbox (suppress email).
        $mform->addElement('advcheckbox', 'suppressemail', get_string('suppressemail', 'facetoface'));
        $mform->setType('suppressemail', PARAM_BOOL);

        $mform->addElement('hidden', 'fileid', $fileid);
        $mform->setType('fileid', PARAM_INT);

        // A hidden marker to indicate we're processing the form.
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
