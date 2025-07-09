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
use core\event\base;
use coding_exception;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Base event for bulk session CSV processing (common setup for activity and site contexts).
 * @package   mod_facetoface
 */
abstract class csv_processed_bulksession_parent extends base {
    /**
     * Init common properties (CRUD read, participating level). Objecttable set in child.
     */
    protected function init(): void {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        // $this->data['objecttable'] will be defined by child classes.
    }
}
