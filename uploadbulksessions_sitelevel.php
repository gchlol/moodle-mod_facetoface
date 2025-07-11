<?php
/**
 * Handles bulk session uploads for the Face-to-Face module site level.
 * Manages CSV validation, preview, and session creation.
 * @package   mod_facetoface
 * @copyright 2025, Gold Coast Health
 * @author    Jonas Sajonas
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');
require_once($CFG->dirroot . '/mod/facetoface/uploadbulksessions_common.php');

use mod_facetoface\form\bulk_session_upload_form_sitelevel;
use mod_facetoface\form\bulk_session_confirm_form_sitelevel;
use mod_facetoface\bulk_session_manager_sitelevel;
use mod_facetoface\event\csv_processed_bulksession_sitelevel;

// Set up the external admin page (in Site administration > Plugins > Face-to-face).
admin_externalpage_setup('modfacetoface_sitebulkupload');

[$fileid, $validate, $process] = get_optional_params();

// Require site configuration capability.
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/mod/facetoface/uploadbulksessions_sitelevel.php');
$PAGE->set_title(get_string('facetoface:sitebulksessions', 'mod_facetoface'));
$PAGE->set_heading(get_string('pluginname', 'mod_facetoface'));

/**
 * Utility function to display bulk-upload errors and then stop execution.
 *
 * @param array $errors A list of errors (each error can be a simple string
 *
 * @return void
 * @throws coding_exception
 */
function display_bulk_upload_errors_site($errors): void {
    global $OUTPUT;

    $table = handle_bulk_upload_errors($errors);

    echo html_writer::tag('div', html_writer::table($table), ['class' => 'flexible-wrap mb-4']);

    echo $OUTPUT->single_button(
        new moodle_url('/mod/facetoface/uploadbulksessions_sitelevel.php'),
        get_string('back'),
        'get',
        ['class' => 'mb-4']
    );

    echo $OUTPUT->footer();

    exit;
}

// Shared variables
$manager = new bulk_session_manager_sitelevel();
$uploadform = new bulk_session_upload_form_sitelevel();

// Validate sessions.
if ($validate) {
    $makeconfirmform = fn(int $fileid) => new bulk_session_confirm_form_sitelevel(
        null, [
            'fileid' => $fileid,
            'process' => 1
        ]
    );

    handle_bulk_session_validation(
        $cancelurl=new moodle_url('/admin/search.php') . '#linkmodules',
        $uploadform,
        $makeconfirmform,
        $manager
    );

    exit;
}

// Confirmation.
if (
    $process &&
    $fileid
) {
    $successurl = new moodle_url('/mod/facetoface/uploadbulksessions_sitelevel.php');
    $cancelurl = $successurl;

    $confirmform = new bulk_session_confirm_form_sitelevel(null, ['fileid' => $fileid]);

    $params = [
        'context' => context_system::instance(),
        'objectid' => 0,
    ];

    $event = csv_processed_bulksession_sitelevel::create($params);

    $bulkuploaderrorhandler = fn($errors) => display_bulk_upload_errors_site($errors);

    process_bulk_session_confirmation(
        $fileid,
        $successurl,
        $cancelurl,
        $confirmform,
        $manager,
        $event,
        $bulkuploaderrorhandler,
        $facetoface=null // Site-level
    );

    exit; // redundant to be explicit.
}

$uploadform->set_data(['validate' => 1]);

echo $OUTPUT->header();

$uploadform->display();

echo $OUTPUT->footer();
