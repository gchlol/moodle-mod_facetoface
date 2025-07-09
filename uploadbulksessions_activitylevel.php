<?php
/**
 * Handles bulk session uploads for the Face-to-Face module.
 * Manages CSV validation, preview, and session creation.
 *
 * Child script for activity context.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');

use mod_facetoface\form\bulk_session_upload_form_activitylevel;
use mod_facetoface\form\bulk_session_confirm_form_activitylevel;
use mod_facetoface\bulk_session_manager_activitylevel;

// Required parameters.
$f2fid = required_param('f2fid', PARAM_INT);
// Validate Face-to-Face instance and set up course context.
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
$context       = context_course::instance($course->id);
$modulecontext = context_module::instance($cm->id);
// Capabilities: editing sessions and bulk session upload.
require_capability('mod/facetoface:editsessions', $context);
require_capability('mod/facetoface:uploadbulksessions', $context);

// Page setup for activity context.
$PAGE->set_url(new moodle_url('/mod/facetoface/uploadbulksessions_activitylevel.php', ['f2fid' => $f2fid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('validatebulksessions', 'facetoface'));
$PAGE->set_heading(get_string('validatebulksessions', 'facetoface'));

// Prepare variables for parent script.
$sitelevel          = false;
$uploadFormClassName = bulk_session_upload_form_activitylevel::class;
$uploadFormOptions   = ['f2fid' => $f2fid];
$uploadFormDefaults  = ['f2fid' => $f2fid, 'validate' => 1];

$confirmFormClassName = bulk_session_confirm_form_activitylevel::class;
$confirmFormOptions   = ['f2fid' => $f2fid, 'fileid' => 0];  // fileid will be set on validate.
$confirmFormOptions2   = ['f2fid' => $f2fid];  // fileid will be set on validate.

$bulkManager         = new bulk_session_manager_activitylevel($f2fid);
// URLs for redirects and error handling.
$cancelurl    = new moodle_url('/mod/facetoface/view.php', ['id' => $cm->id]);
$successurl   = new moodle_url('/mod/facetoface/uploadbulksessions_activitylevel.php', ['f2fid' => $f2fid]);
$errorbackurl = new moodle_url('/mod/facetoface/uploadbulksessions_activitylevel.php', ['f2fid' => $f2fid]);
$cancelurl2   = new moodle_url('/mod/facetoface/uploadbulksessions_activitylevel.php', ['f2fid' => $f2fid]);

// Include shared logic.
require_once($CFG->dirroot . '/mod/facetoface/uploadbulksessions_parent.php');
