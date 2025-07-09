<?php
/**
 * The mod_facetoface CSV bulk session processed event.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_facetoface\event;
use moodle_url;
use coding_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Event triggered when bulk sessions CSV is processed at activity level.
 * @package   mod_facetoface
 */
class csv_processed_bulksession_activitylevel extends csv_processed_bulksession_parent {
    protected function init(): void {
        parent::init();
        $this->data['objecttable'] = 'facetoface';
        $this->contextlevel = CONTEXT_MODULE;
    }

    public static function get_name(): string {
        return get_string('eventcsvprocessedbulksession', 'mod_facetoface');
    }

    public function get_description(): string {
        return get_string('eventcsvprocessedbulksessiondesc', 'mod_facetoface', [
            'userid'            => $this->userid,
            'contextinstanceid' => $this->contextinstanceid
        ]);
    }

    public function get_url(): moodle_url {
        return new moodle_url('/mod/facetoface/uploadbulksessions.php', ['f2fid' => $this->objectid]);
    }

    protected function validate_data(): void {
        parent::validate_data();
        if ($this->contextlevel != CONTEXT_MODULE) {
            throw new coding_exception('Context level must be CONTEXT_MODULE.');
        }
    }
}
