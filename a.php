<?php

// a.php - Minimal solution to display the table.
require_once('../../config.php');
require_once('attendance_sheet_settings.class.php');

require_login();

$PAGE->set_url(new moodle_url('/mod/facetoface/a.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Attendance Sheet Settings');
$PAGE->set_heading('Attendance Sheet Settings');

$attendance = new attendance_sheet_settings();

echo $OUTPUT->header();
echo $attendance->render();
echo $OUTPUT->footer();