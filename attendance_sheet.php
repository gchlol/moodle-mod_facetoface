<?php

global $USER;

use mod_facetoface\custom_capability_checker;
use mod_facetoface\enum\attendance_column;
use mod_facetoface\enum\enum_base;
use mod_facetoface\session;
use mod_facetoface\util\enum_util;
use tool_organisation\persistent\assignment;
use tool_organisation\persistent\hierarchy;
use tool_organisation\persistent\level;
use tool_organisation\persistent\level_data;
use tool_organisation\persistent\position;

require(__DIR__ . '/../../config.php');
require_once("$CFG->dirroot/mod/facetoface/lib.php");

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

$session_id = required_param('session', PARAM_INT);

$session = facetoface_get_session($session_id);
if (!$session) {
    throw new moodle_exception('error:incorrectcoursemodulesession', 'facetoface');
}

$instance = $DB->get_record('facetoface', [ 'id' => $session->facetoface ]);
if (!$instance) {
    throw new moodle_exception('error:incorrectfacetofaceid', 'facetoface');
}

$course = $DB->get_record('course', [ 'id' => $instance->course ]);
if (!$course) {
    throw new moodle_exception('error:coursemisconfigured', 'facetoface');
}

$cm = get_coursemodule_from_instance('facetoface', $instance->id, $course->id);
if (!$cm) {
    throw new moodle_exception('error:incorrectcoursemodule', 'facetoface');
}

// Page Meta
$PAGE->set_url('/mod/facetoface/attendance_sheet.php', [ 'session' => $session->id ]);
require_course_login($course);

$context = context_course::instance($course->id);

$capability_checker = new custom_capability_checker();
$can_view = (
    $capability_checker->manager_permissions ||
    has_capability('mod/facetoface:viewattendees', $context)
);
if (!$can_view) {
    $error_url = new moodle_url('/mod/facetoface/view.php', [ 'id' => $cm->id ]);

    throw new moodle_exception('nopermissions', '', $error_url->out(false), get_string('view'));
}

// Page Data
$data = new stdClass();
$data->course_name = $course->fullname;
$data->instance_name = $instance->name;

$data->logo_url = null;
if ($instance->attendancesheetshowlogo) {
    $logo_url = $OUTPUT->get_logo_url(null, 100);
    if ($logo_url) {
        $data->logo_url = $logo_url->out(false);
    }
}

// region Details Table

// Session Date
$data->session_date = null;
if ($session->datetimeknown) {
    $session_dates = [];
    foreach ($session->sessiondates as $date) {
        $session_dates[] = session::get_readable_session_datetime($date);
    }

    $data->session_date = implode(html_writer::empty_tag('br'), $session_dates);
}

// Custom Fields
$data->custom_fields = [];
$custom_fields = facetoface_get_session_customfields();
$custom_field_data = facetoface_get_customfielddata($session->id);
foreach ($custom_fields as $field) {
    $field_data = $custom_field_data[$field->shortname]->data ?? null;

    $formatted_data = '';
    if ($field_data !== null) {
        $formatted_data = format_string($field_data);
        if ($field->type === CUSTOMFIELD_TYPE_MULTISELECT) {
            $values = explode(CUSTOMFIELD_DELIMITER, $formatted_data);
            $formatted_data = implode(', ', $values);
        }
    }

    $data->custom_fields[] = (object)[
        'name' => format_string($field->name),
        'value' => $formatted_data,
    ];
}

// Trainers
$data->trainers = [];
$trainer_roles = facetoface_get_trainer_roles();
if ($trainer_roles) {
    $trainers = facetoface_get_trainers($session->id) ?: [];

    foreach ($trainers as $role_id => $role_trainers) {
        if (!isset($trainer_roles[$role_id])) {
            continue;
        }

        $trainer_role = $trainer_roles[$role_id];
        $trainer_names = [];
        foreach ($role_trainers as $trainer) {
            $trainer_names[] = fullname($trainer);
        }

        if (!empty($trainer_names)) {
            $data->trainers[] = (object)[
                'role' => $trainer_role->name,
                'names' => implode(', ', $trainer_names),
            ];
        }
    }
}

// endregion

// region Attendees Table

// Headings
//// Apply reasonable defaults if not configured.
//$configured_columns = [
//    attendance_column::NAME,
//    attendance_column::USERNAME,
//    attendance_column::POSITION,
//];
//// Specifically check isset and not empty string to allow only name (0) to be configured.
//if (
//    isset($instance->attendancesheetcolumns) &&
//    $instance->attendancesheetcolumns !== ''
//) {
//    $configured_columns = explode(',', $instance->attendancesheetcolumns);
//}



$datafolder = $CFG->dataroot . '/mod_facetoface';
$configured_columns = read_columns_pairs_from_json($datafolder . '/attendance_sheet_config_' . $USER->id . '.json');
$column_options = enum_util::menu_options(attendance_column::class);
$data->headings = [];
foreach ($configured_columns as $column_key => $column_defaultvalue) {
    if (isset($column_options[$column_key])) {
        // Map enum value to the attendance_sheet table header name
        $label = $column_options[$column_key];
    } else {
        // Display a string as the attendance_sheet table header name directly
        $label = $column_key;
    }
    $data->headings[] = (object)[
        'key' => $column_key,
        'label' => $label,
    ];
}

// Attendees
$ignored_statuses = [ MDL_F2F_STATUS_BOOKED ];
$attendees = facetoface_get_attendees($session->id);

$unit_hierarchy_id = null;
if (in_array(attendance_column::UNIT, $configured_columns)) {
    $unit_hierarchy = hierarchy::get_record([ 'idnumber' => 'unit' ], MUST_EXIST);
    $unit_hierarchy_id = $unit_hierarchy->get('id');
}

$user_paypoints = [];
if (
    !empty($attendees) &&
    array_intersect(
        $configured_columns,
        [
            attendance_column::STREAM,
            attendance_column::PAYPOINT,
        ]
    )
) {
    [ $users_sql, $users_params ] = $DB->get_in_or_equal(array_column($attendees, 'id'));

    $paypoint_records = $DB->get_records_sql(
        "
            select  assignment.id as assignment_id,
                    level.id,
                    level.name,
                    assignment.userid as user_id
            from    {" . assignment::TABLE . "} assignment
                    join {" . level_data::TABLE . "} level_data on
                        level_data.assignid = assignment.id
                    join {" . level::TABLE . "} level on
                        level.id = level_data.levelid
                    join {" . hierarchy::TABLE . "} hierarchy on
                        hierarchy.id = level.hierarchyid
            where   assignment.userid $users_sql and
                    hierarchy.idnumber = 'paypoint'
        ",
        $users_params
    );

    foreach ($paypoint_records as $record) {
        $user_id = $record->user_id;
        if (!isset($user_paypoints[$user_id])) {
            $user_paypoints[$user_id] = [];
        }

        $user_paypoints[$user_id][] = $record;
    }
}

$data->attendees = [];
foreach ($attendees as $attendee) {
    $user = $DB->get_record('user', [ 'id' => $attendee->id ]);

    $column_data = [];
    foreach ($configured_columns as $column_key => $column_defaultvalue) {
        // header_only and header_and_rows type columns will use user-defined 'Default Values'
        if (!isset($column_options[$column_key])) {
            // Add user-defined 'Default Values'
            $column_data[] = $column_defaultvalue;

            continue;
        }
        switch ($column_key) {
            case attendance_column::NAME:
                $column_data[] = fullname($user);

                break;

            case attendance_column::USERNAME:
                $column_data[] = $user->username;

                break;

            case attendance_column::EMAIL:
                $column_data[] = $user->email;

                break;

            case attendance_column::UNIT:
                $unit_names = [];
                $positions = position::get_positions_by_userid($user->id);
                foreach ($positions as $position) {
                    $level_data_items = $position->get_level_data();
                    foreach ($level_data_items as $level_data) {
                        $level = level::get_record([
                            'id' => $level_data->get('levelid'),
                            'hierarchyid' => $unit_hierarchy->get('id'),
                        ]);

                        if ($level) {
                            $unit_names[] = $level->get('name');
                        }
                    }
                }

                $column_data[] = implode(', ', array_unique($unit_names));

                break;

            case attendance_column::POSITION:
                $position_names = [];
                $positions = position::get_positions_by_userid($user->id);
                foreach ($positions as $position) {
                    $position_names[] = $position->get('name');
                }

                $column_data[] = implode(', ', array_unique($position_names));

                break;

            case attendance_column::STREAM:
            case attendance_column::PAYPOINT:
                $paypoints = $user_paypoints[$user->id] ?? [];
                if ($column_key == attendance_column::PAYPOINT) {
                    $column_data[] = implode(', ', array_unique(array_column($paypoints, 'name')));

                    break;
                }

                $stream_names = [];
                foreach ($paypoints as $paypoint) {
                    $level = new level($paypoint->id);
                    $stream_level = $level->get_parent_level()->get_parent_level();

                    $stream_names[] = $stream_level->get('name');
                }

                $column_data[] = implode(', ', array_unique($stream_names));

                break;
        }
    }

    $status = '';
    if (!in_array($attendee->statuscode, $ignored_statuses)) {
        $status_key = facetoface_get_status($attendee->statuscode);
        $status = get_string("status_$status_key", 'facetoface');
    }
    $column_data[] = $status;

    $data->attendees[] = (object)[ 'columns' => $column_data ];
}

// endregion

// Rendering
$content = $OUTPUT->render_from_template('mod_facetoface/attendance_sheet', $data);

echo $OUTPUT->render_from_template('mod_facetoface/print', [
    'title' => get_string('attendancesheet:heading', 'facetoface'),
    'page' => $content,
    'landscape' => count($configured_columns) >= 4,
]);