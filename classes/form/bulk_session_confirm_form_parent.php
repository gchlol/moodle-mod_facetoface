<?php
// classes/form/bulk_session_confirm_form_parent.php
namespace mod_facetoface\form;
use moodleform;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir.'/formslib.php');

/**
 * Base confirmation form for bulk session processing (common hidden fields for both contexts).
 * @package   mod_facetoface
 */
abstract class bulk_session_confirm_form_parent extends moodleform {
    public function definition(): void {
        $mform = $this->_form;
        // Include f2f ID if provided (activity context).
        if (!empty($this->_customdata['f2fid'])) {
            $mform->addElement('hidden', 'f2fid', $this->_customdata['f2fid']);
            $mform->setType('f2fid', PARAM_INT);
        }
        // Always include file ID and process flag.
        $mform->addElement('hidden', 'fileid', $this->_customdata['fileid'] ?? 0);
        $mform->setType('fileid', PARAM_INT);
        $mform->addElement('hidden', 'process', 1);
        $mform->setType('process', PARAM_INT);
        // Add confirmation submit and cancel buttons.
        $this->add_action_buttons(true, get_string('facetoface:confirmandprocess', 'mod_facetoface'));
    }
}
