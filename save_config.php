<?php
/**
 * Saves the attendance sheet configuration submitted via the settings form.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
global $USER;

use mod_facetoface\enum\attendance_column;

require_once('../../config.php');
require_once("$CFG->dirroot/mod/facetoface/classes/data/attendance_sheet_io.php");

require_sesskey(); // CSRF protection.
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['config_data'])) {
    die('Invalid request.');
}

$configdata = json_decode($_POST['config_data'], true);
if ($configdata === null) {
    die('Invalid configuration data.');
}

// Map the attendance_sheet table GUI header names to their corresponding enum values.
$attendance_columns_names_to_enums = attendance_column::map_attendance_columns_names_to_enums();

// Loop over each configuration item and, if the first label matches a fixed column,
// replace its value with the corresponding enum value.
foreach ($configdata as &$item) {
    if (isset($item['labels'][0]['value'])) {
        $colvalue = $item['labels'][0]['value'];
        if (isset($attendance_columns_names_to_enums[$colvalue])) {
            $item['labels'][0]['value'] = $attendance_columns_names_to_enums[$colvalue];
        }
    }
}

// Save the configuration to the JSON file.
$jsonfile = get_attendance_config_file($CFG, $USER);
$result = file_put_contents(get_attendance_config_file($CFG, $USER), json_encode($configdata, JSON_PRETTY_PRINT));
if ($result === false) {
    die('Failed to save configuration.');
}

chmod($jsonfile, 0775);