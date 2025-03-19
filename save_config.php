<?php
/**
 * Saves the attendance sheet configuration submitted via the settings form.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use mod_facetoface\enum\attendance_column;

require_once('../../config.php');
require_once("$CFG->dirroot/mod/facetoface/classes/data/attendance_sheet_io.php");

require_sesskey(); // CSRF protection.
require_login();

$instanceid = required_param('instanceid', PARAM_INT);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['config_data'])) {
    die('Invalid request.');
}

$configdata = json_decode($_POST['config_data'], true);
if ($configdata === null) {
    die('Invalid configuration data.');
}

attendance_column::map_array_key_to_enums($configdata, 'column');

// Save the configuration to the JSON file.
$jsonfile = get_attendance_config_file($CFG, $instanceid);
$result = file_put_contents(
    get_attendance_config_file($CFG, $instanceid),
    json_encode($configdata, JSON_PRETTY_PRINT)
);

if ($result === false) {
    die('Failed to save configuration.');
}
