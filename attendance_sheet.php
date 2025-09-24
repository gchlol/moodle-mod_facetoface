<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Print attendance sheet.
 *
 * @package    mod_facetoface
 * @copyright  2024 Gold Coast Health
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_facetoface\attendance_sheet;
use mod_facetoface\session;
use tool_organisation\persistent\assignment;
use tool_organisation\persistent\assignment_metadata;
use tool_organisation\persistent\position;
use tool_organisation\persistent\position_metadata;

require(__DIR__ . '/../../config.php');
require_once("$CFG->dirroot/mod/facetoface/lib.php");

$sessionid = required_param('session', PARAM_INT);

// Load data.
if (!$session = facetoface_get_session($sessionid)) {
    throw new moodle_exception('error:incorrectcoursemodulesession', 'facetoface');
}
if (!$facetoface = $DB->get_record('facetoface', ['id' => $session->facetoface])) {
    throw new moodle_exception('error:incorrectfacetofaceid', 'facetoface');
}
if (!$course = $DB->get_record('course', ['id' => $facetoface->course])) {
    throw new moodle_exception('error:coursemisconfigured', 'facetoface');
}
if (!$cm = get_coursemodule_from_instance('facetoface', $facetoface->id, $course->id)) {
    throw new moodle_exception('error:incorrectcoursemodule', 'facetoface');
}

$context = context_module::instance($cm->id);
require_course_login($course, true, $cm);

$canviewattendees = has_capability('mod/facetoface:viewattendees', $context);
$cantakeattendance = has_capability('mod/facetoface:takeattendance', $context);

if (!$canviewattendees && !$cantakeattendance) {
    $errorurl = new moodle_url('/mod/facetoface/view.php', ['id' => $cm->id]);
    throw new moodle_exception('nopermissions', '', $errorurl->out(false), get_string('view'));
}

// Page Meta
$PAGE->set_url('/mod/facetoface/attendance_sheet.php', ['session' => $session->id]);

// Page Data
$data = (object) [
    'coursename'   => $course->fullname,
    'instancename' => $facetoface->name,
    'logourl'      => null,
    'sessiondate'  => null,
    'customfields' => [],
    'trainers'     => [],
    'headings'     => [],
    'attendees'    => [],
];

if ($facetoface->attendancesheetshowlogo) {
    $logourl = $OUTPUT->get_logo_url(null, 100);
    if ($logourl) {
        $data->logourl = $logourl->out(false);
    }
}

// Region Details Table
// Session Date
if ($session->datetimeknown) {
    $sessiondates = [];
    foreach ($session->sessiondates as $date) {
        $sessiondates[] = session::get_readable_session_datetime($date);
    }

    $data->sessiondate = implode(html_writer::empty_tag('br'), $sessiondates);
}

// Custom Fields
$customfields = facetoface_get_session_customfields();
$customfielddata = facetoface_get_customfielddata($session->id);
foreach ($customfields as $field) {
    $fielddata = $customfielddata[$field->shortname]->data ?? null;

    $formatteddata = '';
    if ($fielddata !== null) {
        $formatteddata = format_string($fielddata);
        if ($field->type === CUSTOMFIELD_TYPE_MULTISELECT) {
            $values = explode(CUSTOMFIELD_DELIMITER, $formatteddata);
            $formatteddata = implode(', ', $values);
        }
    }

    $data->customfields[] = (object) [
        'name'  => format_string($field->name),
        'value' => $formatteddata,
    ];
}

// Trainers
$trainerroles = facetoface_get_trainer_roles();
if ($trainerroles) {
    $trainers = facetoface_get_trainers($session->id) ?: [];

    foreach ($trainers as $roleid => $roletrainers) {
        if (!isset($trainerroles[$roleid])) {
            continue;
        }

        $trainerrole = $trainerroles[$roleid];
        $trainernames = [];
        foreach ($roletrainers as $trainer) {
            $trainernames[] = fullname($trainer);
        }

        if (!empty($trainernames)) {
            $data->trainers[] = (object) [
                'role'  => $trainerrole->name,
                'names' => implode(', ', $trainernames),
            ];
        }
    }
}
// endregion

// Region Attendees Table
// Headings
// Apply reasonable defaults if not configured.
$configuredcolumns = [
    attendance_sheet::COLUMN_NAME,
    attendance_sheet::COLUMN_USERNAME,
    attendance_sheet::COLUMN_POSITION,
];

// Specifically check isset and not empty string to allow only name (0) to be configured.
if (!empty($facetoface->attendancesheetcolumns)) {
    $configuredcolumns = explode(',', $facetoface->attendancesheetcolumns);
}

$columnoptions = attendance_sheet::get_columns();

foreach ($configuredcolumns as $column) {
    if (isset($columnoptions[$column])) {
        $data->headings[] = (object) [
            'key'   => $column,
            'label' => $columnoptions[$column],
        ];
    }
}

// Attendees
$ignoredstatuses = [MDL_F2F_STATUS_BOOKED];
$attendees = facetoface_get_attendees($session->id);

$inwhere = '';
$inparams = [];
if (!empty($userids)) {
    [$insql, $inparams] = $DB->get_in_or_equal($userids);
    $inwhere = "AND toa.userid $insql";
}

$sql = "SELECT
            toma.id,
            toa.userid,
            toma.paydiv1name,
            toma.payname,
            tomp.unitname,
            pos.name AS posname
        FROM {".assignment::TABLE."} toa
        JOIN {".position::TABLE."} pos ON toa.positionid = pos.id
        LEFT JOIN {".assignment_metadata::TABLE."} toma ON toa.id = toma.assignid
        LEFT JOIN {".position_metadata::TABLE."} tomp ON toa.positionid = tomp.positionid
        WHERE toa.active = 1 $inwhere";
$usermetadata = $DB->get_records_sql($sql, $inparams);

foreach ($attendees as $attendee) {
    $user = $DB->get_record('user', ['id' => $attendee->id]);

    $positions = [];
    $units = [];
    $streams = [];
    $paypoints = [];

    foreach ($usermetadata as $metadata) {
        if ($metadata->userid != $attendee->id) {
            continue;
        }

        $position = $metadata->posname;
        if (!empty($position) && !in_array($position, $positions)) {
            $positions[] = $position;
        }

        $unit = $metadata->unitname;
        if (!empty($unit) && !in_array($unit, $units)) {
            $units[] = $unit;
        }

        $stream = $metadata->paydiv1name;
        if (!empty($stream) && !in_array($stream, $streams)) {
            $streams[] = $stream;
        }

        $paypoint = $metadata->payname;
        if (!empty($paypoint) && !in_array($paypoint, $paypoints)) {
            $paypoints[] = $paypoint;
        }
    }

    $row = [];
    foreach ($configuredcolumns as $column) {
        switch ($column) {
            case attendance_sheet::COLUMN_NAME:
                $row[] = fullname($user);
                break;

            case attendance_sheet::COLUMN_USERNAME:
                $row[] = $user->username;
                break;

            case attendance_sheet::COLUMN_EMAIL:
                $row[] = $user->email;
                break;

            case attendance_sheet::COLUMN_UNIT:
                $row[] = implode(', ', $units);
                break;

            case attendance_sheet::COLUMN_POSITION:
                $row[] = implode(', ', $positions);
                break;

            case attendance_sheet::COLUMN_STREAM:
                $row[] = implode(', ', $streams);
                break;

            case attendance_sheet::COLUMN_PAYPOINT:
                $row[] = implode(', ', $paypoints);
                break;
        }
    }

    $status = '';
    if (!in_array($attendee->statuscode, $ignoredstatuses)) {
        $statuskey = facetoface_get_status($attendee->statuscode);
        $status = get_string("status_$statuskey", 'facetoface');
    }
    $row[] = $status;

    $data->attendees[] = (object) ['columns' => $row];
}
// endregion

// Rendering
$content = $OUTPUT->render_from_template('mod_facetoface/attendance_sheet', $data);

echo $OUTPUT->render_from_template('mod_facetoface/print', [
    'title'     => get_string('attendancesheet:heading', 'facetoface'),
    'page'      => $content,
    'landscape' => count($configuredcolumns) >= 4,
]);
