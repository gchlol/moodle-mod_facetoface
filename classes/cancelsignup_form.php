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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/formslib.php');

/**
 * Cancel signup form
 *
 * Copyright (C) 2007-2011 Catalyst IT (http://www.catalyst.net.nz)
 * Copyright (C) 2011-2013 Totara LMS (http://www.totaralms.com)
 * Copyright (C) 2014 onwards Catalyst IT (http://www.catalyst-eu.net)
 *
 * @package    mod_facetoface
 * @copyright  2014 onwards Catalyst IT <http://www.catalyst-eu.net>
 * @author     Stacey Walker <stacey@catalyst-eu.net>
 * @author     Alastair Munro <alastair.munro@totaralms.com>
 * @author     Aaron Barnes <aaron.barnes@totaralms.com>
 * @author     Francois Marier <francois@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_facetoface_cancelsignup_form extends moodleform {
    /**
     * Define form.
     */
    public function definition() {
        $mform =& $this->_form;

        $mform->addElement('header', 'general', get_string('cancelbooking', 'facetoface'));

        // Hidden element for standard signup cancellation
        $mform->addElement('hidden', 's', $this->_customdata['s']);
        $mform->setType('s', PARAM_INT);

        // Hidden element bulk signup cancellation
        $f = $this->_customdata['f'] ?? 0;
        $mform->addElement('hidden', 'f', $this->_customdata['f']);
        $mform->setType('f', PARAM_INT);

        $mform->addElement('hidden', 'backtoallsessions', $this->_customdata['backtoallsessions']);
        $mform->setType('backtoallsessions', PARAM_INT);

        // Get signup cancellation message depending on type
        $isbulkcancellation = !empty($f);
        if ($isbulkcancellation) {
            $confirmmessage = get_string('cancellationconfirmall', 'facetoface');
        } else {
            $confirmmessage = get_string('cancellationconfirm', 'facetoface');
        }

        $mform->addElement('html', $confirmmessage); // Instructions.

        $mform->addElement('text', 'cancelreason', get_string('cancelreason', 'facetoface'), 'size="60" maxlength="255"');
        $mform->setType('cancelreason', PARAM_TEXT);

        $buttonarray = [];
        $buttonarray[] =& $mform->createElement('submit', 'submitbutton', get_string('yes'));
        $buttonarray[] =& $mform->createElement('cancel', 'cancelbutton', get_string('no'));
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
    }
}
