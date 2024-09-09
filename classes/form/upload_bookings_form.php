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

use moodle_url;
use html_writer;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/repository/lib.php');

/**
 * Upload bookings form class
 *
 * @package    mod_facetoface
 * @author     Kevin Pham <kevinpham@catalyst-au.net>
 * @copyright  Catalyst IT, 2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_bookings_form extends \moodleform {

    /**
     * Build form for importing bookings.
     *
     * {@inheritDoc}
     * @see \moodleform::definition()
     */
    public function definition() {
        global $CFG;

        $mform = $this->_form;

        $mform->addElement('header', 'settingsheader', get_string('upload'));

        $url = new moodle_url('/mod/facetoface/example.csv');
        $link = html_writer::link($url, 'example.csv');
        $mform->addElement('static', 'examplecsv', get_string('facetoface:examplecsv', 'mod_facetoface'), $link);

        $maxbytes = get_max_upload_file_size($CFG->maxbytes, 0);
        $mform->addElement('filemanager', 'csvfile', get_string('facetoface:uploadbookingsfile', 'mod_facetoface'), null, [
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => 'csv',
            'maxbytes' => $maxbytes,
            'return_types' => FILE_INTERNAL | FILE_EXTERNAL,
        ]);
        $mform->setType('csvfile', PARAM_INT);
        $mform->addRule('csvfile', get_string('required'), 'required', null, 'client');

        $mform->addElement('static', 'csvuploadhelp', '',
            nl2br(get_string('facetoface:uploadbookingsfiledesc', 'mod_facetoface')));

        $mform->addElement('advcheckbox', 'caseinsensitive', get_string('caseinsensitive', 'mod_facetoface'));
        $mform->setDefault('caseinsensitive', true);

        // The facetoface module ID.
        $mform->addElement('hidden', 'f');
        $mform->setType('f', PARAM_INT);

        // Whether or not the form should process what has been uploaded.
        $mform->addElement('hidden', 'validate');
        $mform->setType('validate', PARAM_INT);

        $mform->addElement('submit', 'submit', get_string('facetoface:uploadandpreview', 'mod_facetoface'));
    }
}
