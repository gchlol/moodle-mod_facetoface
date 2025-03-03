<?php
/**
 * Save attendance sheet configuration.
 *
 * This script saves the attendance sheet configuration submitted via the settings form.
 *
 * @package mod_facetoface
 */

require_once('../../config.php');
require_sesskey(); // CSRF protection.

// (Optional) Check user permissions here if needed.

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['config_data'])) {
    die('Invalid request.');
}

$configdata = json_decode($_POST['config_data'], true);
if ($configdata === null) {
    die('Invalid configuration data.');
}

$datafolder = $CFG->dataroot . '/mod_facetoface';
$jsonfile = $datafolder . '/attendance_sheet_config.json';

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
//redirect(new moodle_url('/mod/facetoface/a.php'), 'Configuration saved successfully.');