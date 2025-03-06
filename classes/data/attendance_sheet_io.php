<?php global $CFG, $USER;

/**
 * Save attendance sheet configuration from array.
 *
 * This script defines a function to save an attendance sheet configuration given an array of integers and a user id.
 * Each configuration item contains a unique id and a "labels" array with two entries.
 * For example, an input array of [0,1] produces the JSON:
 *
 * [
 *     {
 *         "id": "1741142236659",
 *         "labels": [
 *             {
 *                 "value": 0,
 *                 "first": true
 *             },
 *             {
 *                 "value": ""
 *             }
 *         ]
 *     },
 *     {
 *         "id": "1741142238839",
 *         "labels": [
 *             {
 *                 "value": 1,
 *                 "first": true
 *             },
 *             {
 *                 "value": ""
 *             }
 *         ]
 *     }
 * ]
 *
 * Note: For the value 2, the function casts it to a string to match the expected output.
 *
 * @package   mod_facetoface
 */

use mod_facetoface\enum\attendance_column;
use mod_facetoface\enum\enum_base;

defined('MOODLE_INTERNAL') || die();


$attendanceconfigjson = $CFG->dataroot . '/mod_facetoface/attendance_sheet_config_' . $USER->id . '.json';

class attendance_column_json extends enum_base {
    const COLUMN = 0;
    const DEFAULT_VALUE = 1;
}

/**
 * Read included head columns from .json file.
 *
 * The .json file will have content similar to:
 * [
 *     {
 *         "id": "1741142236659",
 *         "labels": [
 *             {
 *                 "value": 0,
 *                 "first": true
 *             },
 *             {
 *                 "value": ""
 *             }
 *         ]
 *     },
 *     {
 *         "id": "1741142241147",
 *         "labels": [
 *             {
 *                 "value": "h",
 *                 "first": true
 *             },
 *             {
 *                 "value": ""
 *             }
 *         ]
 *     },
 *     {
 *         "id": "1741142265872",
 *         "labels": [
 *             {
 *                 "value": "a",
 *                 "first": true
 *             },
 *             {
 *                 "value": "b"
 *             }
 *         ]
 *     }
 * ]
 *
 * @param string $filePath Path to the .json file.
 * @return array $configured_columns An array of values from labels[0]['value'] => labels[1]['value'] for all items.
 *               e.g. [0=>"", "h"=>"", "a"=>"b"] in the example.
 */
function read_columns_pairs_from_json($filePath) {
    // Check if the file exists.
    if (!file_exists($filePath)) {
        throw new Exception("JSON file not found: " . $filePath);
    }

    // Read the file contents.
    $jsonContent = file_get_contents($filePath);

    // Decode the JSON into an associative array.
    $data = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Error decoding JSON: " . json_last_error_msg());
    }

    // Loop through each item and extract the "Column" and "Default Value" pair.
    $configured_columns = [];
    foreach ($data as $item) {
        if (isset($item['labels'][attendance_column_json::COLUMN]['value'])) {
            $configured_columns[$item['labels'][attendance_column_json::COLUMN]['value']] =
                $item['labels'][attendance_column_json::DEFAULT_VALUE]['value'];
        }
    }

    return $configured_columns;
}

/**
 * Saves attendance sheet configuration to a JSON file.
 *
 * @param array $attendance_array Array of integers representing attendance items.
 * @param string $jsonfile The JSON file path to save attencance_sheet columns and their default values.
 * @return bool True on success.
 * @throws Exception If the data folder cannot be created or the configuration file cannot be written.
 */
function save_attendance_sheet_config(array $attendance_array, string $jsonfile) {
    global $CFG;

    // Define the data folder.
    $datafolder = $CFG->dataroot . '/mod_facetoface';
    if (!is_dir($datafolder)) {
        if (!mkdir($datafolder, 0700, true)) {
            throw new Exception('Failed to create data folder.');
        }
    }

    $output = [];
    foreach ($attendance_array as $value) {
        // Generate a unique id based on the current time in milliseconds.
        $id = (string)round(microtime(true) * 1000);
        // Pause briefly to ensure unique IDs.
        usleep(1000);

        // For value 2, cast to string to match the expected output.
        $first_value = ($value === 2) ? (string)$value : $value;

        $output[] = [
            'id' => $id,
            'labels' => [
                ['value' => $first_value, 'first' => true],
                ['value' => ""]
            ]
        ];
    }

    $jsondata = json_encode($output, JSON_PRETTY_PRINT);
    if (file_put_contents($jsonfile, $jsondata) === false) {
        throw new Exception('Failed to save configuration.');
    }
    // Set file permissions so that only the owner can read and write.
    chmod($jsonfile, 0600);

    return true;
}

/**
 * This function checks that the updated configured columns and their default values are in .json file.
 * If not, it tries to convert the columns from the legacy SQL table into the .json file.
 * If the configured columns are not defined anywhere, use default columns.
 * @param string $jsonfile The .json file path. Containing the updated attendance sheet columns and default values.
 * @param stdClass $instance The SQL table. Containing the legacy attendance sheet columns.
 *        Generally obtained with `$instance = $DB->get_record('facetoface', [ 'id' => $session->facetoface ]);`
 * @return array<int|string, string> The columns and default values the user set up in a .json format.
 */
function return_updated_configured_columns_and_defaults(string $jsonfile, stdClass $instance) {
    // Already have updated configured columns with default values in .json file.
    if (file_exists($jsonfile)) {
        return read_columns_pairs_from_json($jsonfile);
    }

    // If not, check the SQL database has the legacy column values.
    // If the SQL table has column values, save it to the 'atendance_sheet_config_{$USER->id}.json'
    // Specifically check isset and not empty string to allow only name (0) to be configured.
    if (
        isset($instance->attendancesheetcolumns) &&
        $instance->attendancesheetcolumns !== ''
    ) {
        $configured_columns = explode(',', $instance->attendancesheetcolumns);
    } else { // Apply reasonable defaults if not configured.
        $configured_columns = [
            attendance_column::NAME,
            attendance_column::USERNAME,
            attendance_column::POSITION,
        ];
    }

    // Save the old or default column values to
    save_attendance_sheet_config($configured_columns, $jsonfile);

    return read_columns_pairs_from_json($jsonfile);
}