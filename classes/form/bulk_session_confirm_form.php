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
 * Confirmation form for bulk session uploads in Face-to-Face module.
 * Allows users to proceed with session processing.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_facetoface\form;
use moodleform;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir.'/formslib.php');

/**
 * Base confirmation form for bulk session processing (common hidden fields for both contexts).
 * @package   mod_facetoface
 */
abstract class bulk_session_confirm_form extends moodleform {

    /**
     * Builds confirmation form for bulk session processing.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;

        // Retrieve the new parameter f2fid from custom data.
        $f2fid = $this->_customdata['f2fid'] ?? 0;
        $fileid = $this->_customdata['fileid'] ?? 0;

        // Add hidden fields using the new name.
        $mform->addElement('hidden', 'f2fid', $f2fid);
        $mform->setType('f2fid', PARAM_INT);

        $mform->addElement('hidden', 'fileid', $fileid);
        $mform->setType('fileid', PARAM_INT);

        $mform->addElement('hidden', 'process', 1);
        $mform->setType('process', PARAM_INT);

        // Add button group (confirm + cancel).
        $this->add_action_buttons(
            true,
            get_string('facetoface:confirmandprocess', 'mod_facetoface')
        );
    }
}
