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

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir.'/formslib.php');

/**
 * Confirmation form for bulk session uploads in Face-to-Face module.
 * Allows users to proceed with session processing.
 *
 * @package     mod_facetoface
 * @copyright   2025 Gold Coast Health
 * @author      Jonas Sajonas
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_session_confirm_form extends moodleform {

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;

        $f = $this->_customdata['f'] ?? 0;
        $fileid = $this->_customdata['fileid'] ?? 0;

        // Hidden fields.
        $mform->addElement('hidden', 'f', $f);
        $mform->setType('f', PARAM_INT);

        $mform->addElement('hidden', 'fileid', $fileid);
        $mform->setType('fileid', PARAM_INT);

        $mform->addElement('hidden', 'process', 1);
        $mform->setType('process', PARAM_INT);

        // Add a group of buttons.
        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'confirmbtn',
            get_string('facetoface:confirmandprocess', 'mod_facetoface'));
        $buttonarray[] = $mform->createElement('cancel');

        $mform->addGroup($buttonarray, 'buttonar', '', ' ', false);
    }
}
