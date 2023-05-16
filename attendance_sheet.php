<?php

use mod_facetoface\custom_capability_checker;
use tool_organisation\persistent\position;

require(__DIR__ . '/../../config.php');
require_once("$CFG->dirroot/mod/facetoface/lib.php");

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
$logo_url = $OUTPUT->get_logo_url(null, 100);
if ($logo_url) {
    $data->logo_url = $logo_url->out(false);
}

// Session Date
$data->session_date = null;
if ($session->datetimeknown) {
    $session_dates = [];
    foreach ($session->sessiondates as $date) {
        $date_data = facetoface_format_session_times($date->timestart, $date->timefinish, null);

        $session_lang_key = !empty($sessionobj->timezone) ? 'sessionstartdateandtime' : 'sessionstartdateandtimewithouttimezone';
        if ($date_data->startdate !== $date_data->enddate) {
            $session_lang_key = !empty($sessionobj->timezone) ? 'sessionstartfinishdateandtime' : 'sessionstartfinishdateandtimewithouttimezone';
        }

        $session_dates[] = get_string($session_lang_key, 'facetoface', $date_data);
    }

    $data->session_date = implode(html_writer::empty_tag('br'), $session_dates);
}

// Custom Fields
$data->custom_fields = [];
$custom_fields = facetoface_get_session_customfields();
$custom_field_data = facetoface_get_customfielddata($session->id);
foreach ($custom_fields as $field) {
    if (empty($custom_field_data[$field->shortname])) {
        continue;
    }

    $field_data = $custom_field_data[$field->shortname]->data;
    $formatted_data = format_string($field_data);

    if ($field->type === CUSTOMFIELD_TYPE_MULTISELECT) {
        $values = explode(CUSTOMFIELD_DELIMITER, $formatted_data);
        $formatted_data = implode(', ', $values);
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

// Attendees
$ignored_statuses = [ MDL_F2F_STATUS_BOOKED ];
$data->attendees = [];
$attendees = facetoface_get_attendees($session->id);
foreach ($attendees as $attendee) {
    $user = $DB->get_record('user', [ 'id' => $attendee->id ]);

    $status = '';
    if (!in_array($attendee->statuscode, $ignored_statuses)) {
        $status_key = facetoface_get_status($attendee->statuscode);
        $status = get_string("status_$status_key", 'facetoface');
    }

    $position_names = [];
    $positions = position::get_positions_by_userid($user->id);
    foreach ($positions as $position) {
        $position_names[] = $position->get('name');
    }

    $positions_output = implode(', ', $position_names);

    $data->attendees[] = (object)[
        'username' => $user->username,
        'name' => fullname($user),
        'position' => $positions_output,
        'status' => $status,
    ];
}

// Rendering
$content = $OUTPUT->render_from_template('mod_facetoface/attendance_sheet', $data);

echo $OUTPUT->render_from_template('mod_facetoface/print', [
    'title' => get_string('attendancesheet:heading', 'facetoface'),
    'page' => $content,
]);