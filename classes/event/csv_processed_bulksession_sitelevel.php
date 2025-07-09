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
 * Event triggered when bulk sessions CSV is processed at site level.
 *
 * @package   mod_facetoface
 */
class csv_processed_bulksession_sitelevel extends csv_processed_bulksession_parent {
    protected function init(): void {
        parent::init();
        $this->data['objecttable'] = '';  // No specific DB table linked.
    }

    /**
     * Returns name of the event.
     *
     * @return string
     * @throws coding_exception
     */
    public static function get_name(): string {
        return get_string('eventcsvprocessedsitebulksession', 'mod_facetoface');
    }

    /**
     * Returns description of what happened.
     *
     * @returns string
     */
    public function get_description(): string {
        return get_string(
            'eventcsvprocessedsitebulksessiondesc',
            'mod_facetoface',
            (object)['userid' => $this->userid]
        );
    }

    /**
     * Get URL related to the action
     *
     * @return moodle_url
     */
    public function get_url(): moodle_url {
        return new moodle_url('/mod/facetoface/uploadbulksessions_sitelevel.php');
    }

    /**
     * Custom validation
     *
     * @throws coding_exception
     * @return void
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (!isset($this->data['objectid'])) {
            throw new coding_exception('The \'objectid\' must be set for csv_processed_bulksession event.');
        }
    }
}
