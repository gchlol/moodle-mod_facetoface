<?php
/**
 * Read and write attendance sheet configuration.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
global $CFG, $USER;

use mod_facetoface\enum\attendance_column;

defined('MOODLE_INTERNAL') || die();

function get_attendance_config_folder($CFG): string {
    $datafolder = $CFG->dataroot . '/mod/facetoface';
    if (!is_dir($datafolder)) {
        mkdir($datafolder, 0775, true);
    }

    return $datafolder;
}

function get_attendance_config_file($CFG, int $instanceid): string {
    $jsonfile = get_attendance_config_folder($CFG) . '/attendance_sheet_config_' . $instanceid . '.json';
    chmod($jsonfile, 0775);

    return $jsonfile;
}

/**
 * Read a .json file.
 *
 * @param string $filePath Path to the .json file.
 * @return array An array of .json content.
 */
function read_json(string $filePath): array {

    if (!file_exists($filePath)) {
        throw new Exception("JSON file not found: " . $filePath);
    }

    $jsonContent = file_get_contents($filePath);

    $json_content = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Error decoding JSON: " . json_last_error_msg());
    }

    return $json_content;
}

/**
 * Saves attendance sheet configuration to a JSON file.
 *
 * @param array<int> $attendance_array Array of integers representing attendance items.
 * @param string $jsonfile The JSON file path to save attencance_sheet columns and their default values.
 * @return bool True on success.
 * @throws Exception If the data folder cannot be created or the configuration file cannot be written.
 */
function save_attendance_sheet_config(array $attendance_array, string $jsonfile) {

    $output = [];
    foreach ($attendance_array as $value) {
        $output[] = [
            'column' => $value,
            'value' => ""
        ];
    }

    $output = attendance_column::map_array_key_to_enums($output, 'column');

    $jsondata = json_encode($output, JSON_PRETTY_PRINT);
    if (file_put_contents($jsonfile, $jsondata) === false) {
        throw new Exception('Failed to save configuration.');
    }

    return true;
}

/**
 * This function checks that the updated configured columns and their default values are in .json file.
 * If not, it tries to convert the columns from the legacy SQL table into the .json file.
 * If the configured columns are not defined anywhere, use default columns.
 * @param string $jsonfile The .json file path. Containing the updated attendance sheet columns and default values.
 * @param stdClass $instance The SQL table. Containing the legacy attendance sheet columns.
 *        Generally obtained with `$instance = $DB->get_record('facetoface', [ 'id' => $session->facetoface ]);`
 * @return array The content in the updated .json format.
 */
function return_updated_json_content(string $jsonfile, stdClass $instance) {
    // Already have updated configured columns with default values in .json file.
    if (file_exists($jsonfile)) {
        return read_json($jsonfile);
    }

    // If not, check the SQL database has the legacy column values.
    // If the SQL table has column values, save it to the 'atendance_sheet_config_{$USER->id}.json'
    // Specifically check isset and not empty string to allow only name (0) to be configured.
    if (
        isset($instance->attendancesheetcolumns) &&
        $instance->attendancesheetcolumns !== ''
    ) {
        $columns = $instance->attendancesheetcolumns;
        $configuredcolumns = !empty($columns) ? array_map('intval', explode(',', $columns)) : [];

    } else { // Apply reasonable defaults if not configured.
        $configuredcolumns = [
            attendance_column::NAME,
            attendance_column::USERNAME,
            attendance_column::POSITION,
        ];
    }

    // Save the old or default column values to .json
    save_attendance_sheet_config($configuredcolumns, $jsonfile);

    return read_json($jsonfile);
}

/**
 * Maps items to an associative array using 'column' as key and 'value' as value.
 *
 *  Example:
 *  Input:
 *  [
 *      ['column' => 'Name', 'value' => ''],
 *      ['column' => 'Pass\/Fail', 'value' => 'Pass\/Fail']
 *  ]
 *
 *  Output:
 *  [
 *      'name' => '',
 *      'Pass\/Fail' => 'Pass\/Fail'
 *  ]
 *
 * @param array $json_content Items with 'column' and 'value' keys.
 * @return array The associative array.
 */
function parse_json_to_associative_array(array $json_content): array {
    $associative_array = [];
    foreach ($json_content as $item) {
        if (isset($item['column'])) {
            $associative_array[$item['column']] = $item['value'];
        }
    }

    return $associative_array;
}
