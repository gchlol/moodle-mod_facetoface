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
 * Copyright (C) 2007-2011 Catalyst IT (http://www.catalyst.net.nz)
 * Copyright (C) 2011-2013 Totara LMS (http://www.totaralms.com)
 * Copyright (C) 2014 onwards Catalyst IT (http://www.catalyst-eu.net)
 *
 * @package    mod
 * @subpackage facetoface
 * @copyright  2014 onwards Catalyst IT <http://www.catalyst-eu.net>
 * @author     Stacey Walker <stacey@catalyst-eu.net>
 * @author     Alastair Munro <alastair.munro@totaralms.com>
 * @author     Aaron Barnes <aaron.barnes@totaralms.com>
 * @author     Francois Marier <francois@catalyst.net.nz>
 */

use mod_facetoface\enum\attendance_column;
use mod_facetoface\util\enum_util;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/course/moodleform_mod.php');
require_once($CFG->dirroot.'/mod/facetoface/lib.php');

class mod_facetoface_mod_form extends moodleform_mod {

    public function definition() {
        global $CFG;

        $mform =& $this->_form;

        // General.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), array('size' => '64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();

        $mform->addElement('text', 'thirdparty', get_string('thirdpartyemailaddress', 'facetoface'), array('size' => '64'));
        $mform->setType('thirdparty', PARAM_NOTAGS);
        $mform->addHelpButton('thirdparty', 'thirdpartyemailaddress', 'facetoface');

        $mform->addElement('checkbox', 'thirdpartywaitlist', get_string('thirdpartywaitlist', 'facetoface'));
        $mform->addHelpButton('thirdpartywaitlist', 'thirdpartywaitlist', 'facetoface');

        $display = array();
        for ($i = 0; $i <= 18; $i += 2) {
            $display[$i] = $i;
        }
        $mform->addElement('select', 'display', get_string('sessionsoncoursepage', 'facetoface'), $display);
        $mform->setDefault('display', 6);
        $mform->addHelpButton('display', 'sessionsoncoursepage', 'facetoface');

        $mform->addElement('checkbox', 'approvalreqd', get_string('approvalreqd', 'facetoface'));
        $mform->addHelpButton('approvalreqd', 'approvalreqd', 'facetoface');

        if (has_capability('mod/facetoface:configurecancellation', $this->context)) {
            $mform->addElement('advcheckbox', 'allowcancellationsdefault', get_string('allowcancellationsdefault', 'facetoface'));
            $mform->setDefault('allowcancellationsdefault', 1);
            $mform->addHelpButton('allowcancellationsdefault', 'allowcancellationsdefault', 'facetoface');
        }

        $mform->addElement('header', 'calendaroptions', get_string('calendaroptions', 'facetoface'));

        $calendaroptions = array(
            F2F_CAL_NONE   => get_string('none'),
            F2F_CAL_COURSE => get_string('course'),
            F2F_CAL_SITE   => get_string('site')
        );
        $mform->addElement('select', 'showoncalendar', get_string('showoncalendar', 'facetoface'), $calendaroptions);
        $mform->setDefault('showoncalendar', F2F_CAL_COURSE);
        $mform->addHelpButton('showoncalendar', 'showoncalendar', 'facetoface');

        $mform->addElement('advcheckbox', 'usercalentry', get_string('usercalentry', 'facetoface'));
        $mform->setDefault('usercalentry', true);
        $mform->addHelpButton('usercalentry', 'usercalentry', 'facetoface');

        $mform->addElement('text', 'shortname', get_string('shortname'), array('size' => 32, 'maxlength' => 32));
        $mform->setType('shortname', PARAM_TEXT);
        $mform->addHelpButton('shortname', 'shortname', 'facetoface');
        $mform->addRule('shortname', null, 'maxlength', 32);

        // Request message.
        $mform->addElement('header', 'request', get_string('requestmessage', 'facetoface'));
        $mform->addHelpButton('request', 'requestmessage', 'facetoface');

        $mform->addElement('text', 'requestsubject', get_string('email:subject', 'facetoface'), array('size' => '55'));
        $mform->setType('requestsubject', PARAM_TEXT);
        $mform->setDefault('requestsubject', get_string('setting:defaultrequestsubjectdefault', 'facetoface'));
        $mform->disabledIf('requestsubject', 'approvalreqd');

        $mform->addElement('textarea', 'requestmessage', get_string('email:message', 'facetoface'), 'wrap="virtual" rows="15" cols="70"');
        $mform->setDefault('requestmessage', get_string('setting:defaultrequestmessagedefault', 'facetoface'));
        $mform->disabledIf('requestmessage', 'approvalreqd');

        $mform->addElement('textarea', 'requestinstrmngr', get_string('email:instrmngr', 'facetoface'), 'wrap="virtual" rows="10" cols="70"');
        $mform->setDefault('requestinstrmngr', get_string('setting:defaultrequestinstrmngrdefault', 'facetoface'));
        $mform->disabledIf('requestinstrmngr', 'approvalreqd');

        // Confirmation message.
        $mform->addElement('header', 'confirmation', get_string('confirmationmessage', 'facetoface'));
        $mform->addHelpButton('confirmation', 'confirmationmessage', 'facetoface');

        $mform->addElement('text', 'confirmationsubject', get_string('email:subject', 'facetoface'), array('size' => '55'));
        $mform->setType('confirmationsubject', PARAM_TEXT);
        $mform->setDefault('confirmationsubject', get_string('setting:defaultconfirmationsubjectdefault', 'facetoface'));

        $mform->addElement('textarea', 'confirmationmessage', get_string('email:message', 'facetoface'), 'wrap="virtual" rows="15" cols="70"');
        $mform->setDefault('confirmationmessage', get_string('setting:defaultconfirmationmessagedefault', 'facetoface'));

        $mform->addElement('checkbox', 'emailmanagerconfirmation', get_string('emailmanager', 'facetoface'));
        $mform->addHelpButton('emailmanagerconfirmation', 'emailmanagerconfirmation', 'facetoface');

        $mform->addElement('textarea', 'confirmationinstrmngr', get_string('email:instrmngr', 'facetoface'), 'wrap="virtual" rows="4" cols="70"');
        $mform->addHelpButton('confirmationinstrmngr', 'confirmationinstrmngr', 'facetoface');
        $mform->disabledIf('confirmationinstrmngr', 'emailmanagerconfirmation');
        $mform->setDefault('confirmationinstrmngr', get_string('setting:defaultconfirmationinstrmngrdefault', 'facetoface'));

        // Reminder message.
        $mform->addElement('header', 'reminder', get_string('remindermessage', 'facetoface'));
        $mform->addHelpButton('reminder', 'remindermessage', 'facetoface');

        $mform->addElement('text', 'remindersubject', get_string('email:subject', 'facetoface'), array('size' => '55'));
        $mform->setType('remindersubject', PARAM_TEXT);
        $mform->setDefault('remindersubject', get_string('setting:defaultremindersubjectdefault', 'facetoface'));

        $mform->addElement('textarea', 'remindermessage', get_string('email:message', 'facetoface'), 'wrap="virtual" rows="15" cols="70"');
        $mform->setDefault('remindermessage', get_string('setting:defaultremindermessagedefault', 'facetoface'));

        $mform->addElement('checkbox', 'emailmanagerreminder', get_string('emailmanager', 'facetoface'));
        $mform->addHelpButton('emailmanagerreminder', 'emailmanagerreminder', 'facetoface');

        $mform->addElement('textarea', 'reminderinstrmngr', get_string('email:instrmngr', 'facetoface'), 'wrap="virtual" rows="4" cols="70"');
        $mform->addHelpButton('reminderinstrmngr', 'reminderinstrmngr', 'facetoface');
        $mform->disabledIf('reminderinstrmngr', 'emailmanagerreminder');
        $mform->setDefault('reminderinstrmngr', get_string('setting:defaultreminderinstrmngrdefault', 'facetoface'));

        $reminderperiod = array();
        for ($i = 1; $i <= 20; $i += 1) {
            $reminderperiod[$i] = $i;
        }
        $mform->addElement('select', 'reminderperiod', get_string('reminderperiod', 'facetoface'), $reminderperiod);
        $mform->setDefault('reminderperiod', 2);
        $mform->addHelpButton('reminderperiod', 'reminderperiod', 'facetoface');

        // Waitlisted message.
        $mform->addElement('header', 'waitlisted', get_string('waitlistedmessage', 'facetoface'));
        $mform->addHelpButton('waitlisted', 'waitlistedmessage', 'facetoface');

        $mform->addElement('text', 'waitlistedsubject', get_string('email:subject', 'facetoface'), array('size' => '55'));
        $mform->setType('waitlistedsubject', PARAM_TEXT);
        $mform->setDefault('waitlistedsubject', get_string('setting:defaultwaitlistedsubjectdefault', 'facetoface'));

        $mform->addElement('textarea', 'waitlistedmessage', get_string('email:message', 'facetoface'), 'wrap="virtual" rows="15" cols="70"');
        $mform->setDefault('waitlistedmessage', get_string('setting:defaultwaitlistedmessagedefault', 'facetoface'));

        // Cancellation message.
        $mform->addElement('header', 'cancellation', get_string('cancellationmessage', 'facetoface'));
        $mform->addHelpButton('cancellation', 'cancellationmessage', 'facetoface');

        $mform->addElement('text', 'cancellationsubject', get_string('email:subject', 'facetoface'), array('size' => '55'));
        $mform->setType('cancellationsubject', PARAM_TEXT);
        $mform->setDefault('cancellationsubject', get_string('setting:defaultcancellationsubjectdefault', 'facetoface'));

        $mform->addElement('textarea', 'cancellationmessage', get_string('email:message', 'facetoface'), 'wrap="virtual" rows="15" cols="70"');
        $mform->setDefault('cancellationmessage', get_string('setting:defaultcancellationmessagedefault', 'facetoface'));

        $mform->addElement('checkbox', 'emailmanagercancellation', get_string('emailmanager', 'facetoface'));
        $mform->addHelpButton('emailmanagercancellation', 'emailmanagercancellation', 'facetoface');

        $mform->addElement('textarea', 'cancellationinstrmngr', get_string('email:instrmngr', 'facetoface'), 'wrap="virtual" rows="4" cols="70"');
        $mform->addHelpButton('cancellationinstrmngr', 'cancellationinstrmngr', 'facetoface');
        $mform->disabledIf('cancellationinstrmngr', 'emailmanagercancellation');
        $mform->setDefault('cancellationinstrmngr', get_string('setting:defaultcancellationinstrmngrdefault', 'facetoface'));

        // Attendance Sheet
        $mform->addElement('header', 'attendancesheetheader', get_string('attendancesheet:heading', 'facetoface'));

        $mform->addElement('selectyesno', 'attendancesheetshowlogo', get_string('modform:showlogo', 'mod_facetoface'));
        $mform->setDefault('attendancesheetshowlogo', 1);
        $mform->addHelpButton('attendancesheetshowlogo', 'modform:showlogo', 'mod_facetoface');

        $column_checkboxes = [];
        $column_options = enum_util::menu_options(attendance_column::class);
        foreach ($column_options as $key => $label) {
            $column_checkboxes[] = $mform->createElement('checkbox', $key, $label);
        }
        $mform->addGroup($column_checkboxes, 'attendancesheetcolumns', 'Columns', html_writer::empty_tag('br'));

        $features = new stdClass;
        $features->groups = false;
        $features->groupings = false;
        $features->groupmembersonly = false;
        $features->outcomes = false;
        $features->gradecat = false;
        $features->idnumber = true;
        $this->standard_coursemodule_elements($features);

        $this->add_action_buttons();
    }

    public function data_preprocessing(&$defaultvalues) {

        // Fix manager emails.
        if (empty($defaultvalues['confirmationinstrmngr'])) {
            $defaultvalues['confirmationinstrmngr'] = null;
        } else {
            $defaultvalues['emailmanagerconfirmation'] = 1;
        }

        if (empty($defaultvalues['reminderinstrmngr'])) {
            $defaultvalues['reminderinstrmngr'] = null;
        } else {
            $defaultvalues['emailmanagerreminder'] = 1;
        }

        if (empty($defaultvalues['cancellationinstrmngr'])) {
            $defaultvalues['cancellationinstrmngr'] = null;
        } else {
            $defaultvalues['emailmanagercancellation'] = 1;
        }

        if (
            !isset($defaultvalues['attendancesheetcolumns']) ||
            $defaultvalues['attendancesheetcolumns'] === ''
        ) {
            $defaultvalues['attendancesheetcolumns'] = [];

        } else {
            $keys = explode(',', $defaultvalues['attendancesheetcolumns']);
            $defaultvalues['attendancesheetcolumns'] = array_fill_keys($keys, 1);
        }
    }

    /**
     * GCHLOL: Display module-specific activity completion rules.
     * Part of the API defined by moodleform_mod
     * @return array Array of string IDs of added items, empty array if none
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $items = array();

        $group = array();
        $group[] = $mform->createElement('advcheckbox', 'completionpass', null, get_string('completionpass', 'quiz'),
            array('group' => 'cpass'));
        $mform->disabledIf('completionpass', 'notchecked');
        $mform->addGroup($group, 'completionpassgroup', get_string('completionpass', 'quiz'), ' &nbsp; ', false);
        $mform->addHelpButton('completionpassgroup', 'completionpass', 'quiz');
        $items[] = 'completionpassgroup';
        return $items;
    }

    /**
     * GCHLOL: Called during validation. Indicates whether a module-specific completion rule is selected.
     *
     * @param array $data Input data (not yet validated)
     * @return bool True if one or more rules is enabled, false if none are.
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionpass']);
    }
}
