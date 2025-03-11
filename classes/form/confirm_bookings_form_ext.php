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

use html_writer;
use moodle_url;
use moodleform;
use single_button;

defined('MOODLE_INTERNAL') || die;
require_once($CFG->libdir . '/formslib.php');

class confirm_bookings_form_ext extends moodleform {

    /**
     * Form definition
     */
    public function definition() {
        global $OUTPUT;

        $mform = $this->_form;
        $fileid = $this->_customdata['fileid'] ?? 0;
        $caseinsensitive = $this->_customdata['caseinsensitive'] ?? true;

        // Suppress email checkbox.
        $mform->addElement('advcheckbox', 'suppressemail', get_string('suppressemail', 'facetoface'), '', [], [0, 1]);
        $mform->addHelpButton('suppressemail', 'suppressemail', 'facetoface');
        $mform->setType('supressemail', PARAM_BOOL);

        // Reference to the uploaded file.
        $mform->addElement('hidden', 'fileid', $fileid);
        $mform->setType('fileid', PARAM_INT);

        $mform->addElement('hidden', 'caseinsensitive', $caseinsensitive);
        $mform->setType('caseinsensitive', PARAM_BOOL);

        $mform->addElement('hidden', 'process', $this->_customdata['process'] ?? 0);
        $mform->setType('process', PARAM_INT);

        $backurl = new moodle_url('/mod/facetoface/upload_ext.php');
        $htmlbuttons = $OUTPUT->render((new single_button(
            new moodle_url('/mod/facetoface/upload_ext.php', [ 'fileid' => $fileid, 'process' => 1]),
            get_string('facetoface:confirmandprocess', 'mod_facetoface'),
            'post',
            true
        )));
        $htmlbuttons .= $OUTPUT->single_button($backurl, get_string('back'), 'get', ['class' => 'ml-3']);

        $htmlbuttons = html_writer::tag('div', $htmlbuttons, ['class' => 'd-flex gap-2']);
        $mform->addElement('html', $htmlbuttons);
    }
}
