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

defined('MOODLE_INTERNAL') || exit;
require_once $CFG->dirroot.'/repository/lib.php';

/**
 * Upload bookings form class.
 *
 * @author     Jonas Sajonas
 * @copyright  GCHLOL, 2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_session_confirm_form extends \moodleform
{
    /**
     * Build form for importing bookings.
     *
     * {@inheritDoc}
     *
     * @see \moodleform::definition()
     */
    public function definition()
    {
        global $CFG;

        $mform = $this->_form;
        $f = $this->_customdata['f'] ?? 0;

        // Header.
        $mform->addElement('header', 'settingsheader', get_string('facetoface:bulk_session', 'mod_facetoface'));

        // Example CSV link (optional).
        $url = new \moodle_url('/mod/facetoface/example_bulk.csv');
        $link = \html_writer::link($url, 'example_bulk.csv');
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

        // Description or instruction (optional).
        $mform->addElement('static', 'csvuploadhelp', '',
            nl2br(get_string('facetoface:uploadsessionfiledesc', 'mod_facetoface')));

        // Additional checkbox for case-insensitive matching (if needed).
        $mform->addElement('advcheckbox', 'caseinsensitive', get_string('caseinsensitive', 'mod_facetoface'));
        $mform->setDefault('caseinsensitive', true);

        // Hidden field for facetoface instance ID.
        $mform->addElement('hidden', 'f', $f); 
        $mform->setType('f', PARAM_INT);

        // Hidden field to indicate we should validate/process after upload.
        $mform->addElement('hidden', 'validate', 1);
        $mform->setType('validate', PARAM_INT);

        // Submit button.
        $mform->addElement('submit', 'submit', get_string('facetoface:uploadandpreviewbulk', 'mod_facetoface'));
    }
}
