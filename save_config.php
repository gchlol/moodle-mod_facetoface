<?php
/**
 * Save attendance sheet configuration.
 *
 * This script saves the attendance sheet configuration submitted via the settings form.
 *
 * @package mod_facetoface
 */

use mod_facetoface\enum\attendance_sheet_column;

require_once('../../config.php');
require_sesskey(); // CSRF protection.

global $USER;

// TODO: Check user has the permission to view the attendance_sheet_config file


if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['config_data'])) {
    die('Invalid request.');
}

$configdata = json_decode($_POST['config_data'], true);
if ($configdata === null) {
    die('Invalid configuration data.');
}

// Map the attendance_sheet table GUI header names to their corresponding enum values.
$attendance_columns_names_to_enums = attendance_sheet_column::map_attendance_columns_names_to_enums();

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

$datafolder = $CFG->dataroot . '/mod_facetoface';
$jsonfile = $datafolder . '/attendance_sheet_config_' . $USER->id . '.json';

// Ensure the data folder exists.
if (!is_dir($datafolder)) {
    mkdir($datafolder, 0700, true);
}

// Save the configuration to the JSON file.
$result = file_put_contents($jsonfile, json_encode($configdata, JSON_PRETTY_PRINT));
if ($result === false) {
    die('Failed to save configuration.');
}

// Set file permissions so that only the owner can read and write.
chmod($jsonfile, 0600);

// Redirect back to the settings page with a success message.
redirect(new moodle_url('/mod/facetoface/settings.php'), 'Configuration saved successfully.');