<?php
/**
 * Handles bulk session uploads for the Face-to-Face module.
 * Manages CSV validation, preview, and session creation.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');
require_once($CFG->dirroot . '/mod/facetoface/uploadbulksessions_common.php');

use mod_facetoface\form\bulk_session_upload_form_activitylevel;
use mod_facetoface\form\bulk_session_confirm_form_activitylevel;
use mod_facetoface\bulk_session_manager_activitylevel;
use mod_facetoface\event\csv_processed_bulksession_activitylevel;

$f2fid = required_param('f2fid', PARAM_INT);
$PAGE->set_url(new moodle_url('/mod/facetoface/uploadbulksessions_activitylevel.php', ['f2fid' => $f2fid]));
$heading = get_string('validatebulksessions', 'facetoface');

[$fileid, $validate, $process] = get_optional_params();

if (!$facetoface = $DB->get_record('facetoface', ['id' => $f2fid])) {
    throw new moodle_exception('error:incorrectfacetofaceid', 'facetoface');
}

if (!$course = $DB->get_record('course', ['id' => $facetoface->course])) {
    throw new moodle_exception('error:coursemisconfigured', 'facetoface');
}

if (!$cm = get_coursemodule_from_instance('facetoface', $facetoface->id, $course->id)) {
    throw new moodle_exception('error:incorrectcoursemoduleid', 'facetoface');
}

require_course_login($course, true, $cm);

$context = context_course::instance($course->id);
$modulecontext = context_module::instance($cm->id);
require_capability('mod/facetoface:editsessions', $context);
require_capability('mod/facetoface:uploadbulksessions', $context);

$PAGE->set_pagelayout('standard');
$PAGE->set_title($heading);
$PAGE->set_heading($heading);

/**
 * Displays bulk-upload errors and ends execution.
 *
 * @param array $errors An array of errors to display.
 * @return void <!> This function `exit`s after calling.
 */
function handle_bulk_upload_errors_activity(array $errors): void {
    global $OUTPUT;

    $table = handle_bulk_upload_errors($errors);

    echo html_writer::tag('div', html_writer::table($table), ['class' => 'flexible-wrap mb-4']);

    echo html_writer::start_div('mt-4 text-center');
    $backurl = new moodle_url('/mod/facetoface/uploadbulksessions_activitylevel.php', ['f2fid' => optional_param('f2fid', 0, PARAM_INT)]);
    echo html_writer::link($backurl, get_string('back'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_div();

    echo $OUTPUT->footer();

    exit;
}

// Shared variables
$manager = new bulk_session_manager_activitylevel($f2fid);
$uploadform = new bulk_session_upload_form_activitylevel(
    null,
    ['f2fid' => $f2fid]  // moodleform parameters. DON'T DELETE ME!
);

// Validate sessions.
if ($validate) {
    $makeconfirmform = fn(int $fileid) => new bulk_session_confirm_form_activitylevel(
        null,
        [
            'f2fid'  => $f2fid,
            'fileid' => $fileid,
        ]
    );

    handle_bulk_session_validation(
        $cancelurl=new moodle_url('/mod/facetoface/view.php', ['id' => $cm->id]),
        $uploadform,
        $makeconfirmform,
        $manager
    );

    exit;
}

// Confirmation.
if (
    $process &&
    $fileid &&
    $f2fid
) {
    $successurl = new moodle_url('/mod/facetoface/uploadbulksessions_activitylevel.php', ['f2fid' => $f2fid]);
    $cancelurl = $successurl;

    $confirmform = new bulk_session_confirm_form_activitylevel(null, ['f2fid' => $f2fid, 'fileid' => $fileid]);

    $params = [
        'context'  => $modulecontext,
        'objectid' => $f2fid,
    ];
    $event = csv_processed_bulksession_activitylevel::create($params);

    $bulkuploaderrorhandler = fn($errors) => handle_bulk_upload_errors_activity($errors);

    process_bulk_session_confirmation(
        $fileid,
        $successurl,
        $cancelurl,
        $confirmform,
        $manager,
        $event,
        $bulkuploaderrorhandler,
        $facetoface
    );

    exit; // redundant to be explicit.
}

// Default display: show the upload form.
$uploadform->set_data(['f2fid' => $f2fid, 'validate' => 1]);

echo $OUTPUT->header();

$uploadform->display();

echo $OUTPUT->footer();
