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

defined('MOODLE_INTERNAL') || die();

use core\output\notification;
use mod_facetoface\event\csv_processed_bulksession_activitylevel;
use mod_facetoface\event\csv_processed_bulksession_sitelevel;

/**
 * Displays bulk-upload errors and ends execution.
 *
 * @param array $errors An array of errors to display.
 * @return void
 * @throws coding_exception
 */
function display_bulk_upload_errors(array $errors, moodle_url $backurl, bool $sitecontext = false): void {
    global $OUTPUT;

    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        get_string('error:uploadsessionserrorsfound', 'mod_facetoface', count($errors)),
        notification::NOTIFY_ERROR
    );

    $table = new html_table();
    $table->attributes['class'] = 'f2fbookingsuploadlist m-auto generaltable mb-2';
    $table->head = [
        get_string('csvline', 'mod_facetoface'),
        get_string('status', 'mod_facetoface')
    ];

    // Populate table rows with errors.
    foreach ($errors as $error) {
        if (
            !is_array($error) ||
            count($error) < 2
        ) {
            $table->data[] = ["-", is_string($error) ? $error : json_encode($error)];

            continue;
        }

        $line = $error[0] + 2;
        $messages = array_slice($error, 1);

        foreach ($messages as $message) {
            $table->data[] = [$line, $message];
        }
    }

    echo html_writer::tag('div', html_writer::table($table), ['class' => 'flexible-wrap mb-4']);

    // Output a "Back" button/link.
    if ($sitecontext) {
        // Site context: use a single button back to site upload page.
        echo $OUTPUT->single_button(
            $backurl,
            get_string('back'),
            'get',
            ['class' => 'mb-4']
        );

    } else {
        // Activity context: center a styled link back to activity view.
        echo html_writer::start_div('mt-4 text-center');
        echo html_writer::link($backurl, get_string('back'), ['class' => 'btn btn-secondary']);
        echo html_writer::end_div();
    }

    echo $OUTPUT->footer();

    exit;
}

// Retrieve common parameters for file upload.
$fileid   = optional_param('fileid', 0, PARAM_INT);
$validate = optional_param('validate', 0, PARAM_INT);
$process  = optional_param('process', 0, PARAM_INT);

// Instantiate the upload form (already defined in child includes).
$uploadform = new $uploadFormClassName(null, $uploadFormOptions);  // $uploadFormClassName and $uploadFormOptions are set in child.

if ($validate) {
    $formdata = $uploadform->get_data();

    // If cancel pressed, redirect to the appropriate page.
    if ($uploadform->is_cancelled()) {
        redirect($cancelurl);

        exit;
    }

    $fileid   = $formdata->csvfile ?: 0;

    // Prepare confirmation form for processing.
    $confirmform = new $confirmFormClassName(
        null,
        $confirmFormOptions
    );  // $confirmFormClassName and $confirmFormOptions set in child.

    // Load CSV and validate via manager.
    $manager = $bulkManager;  // $bulkManager is an instance set in child.
    $manager->load_from_file($fileid);
    $errors = $manager->validate();

    // If there are errors, handle them and exit.
    if (!empty($errors)) {
        display_bulk_upload_errors($errors, $errorbackurl, $sitelevel);
    }

    // If no errors, display the CSV preview.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('confirmbulkpreview', 'mod_facetoface'), 3);

    $records = $manager->get_records();
    if (empty($records)) {
        echo $OUTPUT->notification(get_string('norecordsfound', 'mod_facetoface'), notification::NOTIFY_INFO);
    }

    if (!empty($records)) {
        $table = new html_table();
        $table->attributes['class'] = 'f2fconfirmuploadlist m-auto generaltable mb-2';

        // Use first record to set table headers.
        $firstrecord = reset($records);
        $headers = array_keys($firstrecord);

        $table->head = $headers;

        foreach ($records as $record) {
            $rowdata = [];
            foreach ($headers as $h) {
                $rowdata[] = $record[$h] ?? '';
            }
            $table->data[] = $rowdata;
        }

        echo html_writer::tag('div', html_writer::table($table), ['class' => 'flexible-wrap mb-4']);
    }

    $confirmform->display();

    echo $OUTPUT->footer();

    exit;
}

if (
    $process &&
    $fileid
) {
    // Instantiate manager and confirm form for processing step.
    $manager = $bulkManager;
    $manager->load_from_file($fileid);
    $confirmform = new $confirmFormClassName(null, $confirmFormOptions2);

    if ($confirmform->is_cancelled()) {
        redirect($cancelurl2);

        exit;
    }

    $errors = $manager->validate();

    if (empty($errors)) {
        $success = $manager->process();

        if ($success) {
            // Trigger appropriate event on success.
            if ($sitelevel) {
                $params = [
                    'context' => context_system::instance(),
                    'objectid' => 0,
                ];

                $event = csv_processed_bulksession_sitelevel::create($params);
                $event->trigger();

                redirect(
                    new moodle_url('/mod/facetoface/uploadbulksessions_sitelevel.php'),
                    get_string('bulksessionsprocessed', 'mod_facetoface'),
                    null,
                    notification::NOTIFY_SUCCESS
                );

            } else {
                $params = [
                    'context'  => $modulecontext,
                    'objectid' => $f2fid,
                ];

                $event = csv_processed_bulksession_activitylevel::create($params);
                $event->add_record_snapshot('facetoface', $facetoface);
                $event->trigger();

                redirect(
                    new moodle_url('/mod/facetoface/uploadbulksessions_activitylevel.php', ['f2fid' => $f2fid]),
                    get_string('bulksessionsprocessed', 'mod_facetoface'),
                    null,
                    notification::NOTIFY_SUCCESS
                );
            }
            $event->trigger();
            // Redirect with success notification.
            redirect($successurl, get_string('bulksessionsprocessed', 'mod_facetoface'), null, notification::NOTIFY_SUCCESS);
        } else {
            // If processing failed, display accumulated errors.
            display_bulk_upload_errors($manager->get_errors(), $errorbackurl, $sitelevel);
        }
    }

    // If validation errors were present at processing time, display them.
    display_bulk_upload_errors($errors, $errorbackurl, $sitelevel);
}

// Default page view: set up form and display.
$uploadform->set_data($uploadFormDefaults);

echo $OUTPUT->header();

$uploadform->display();

echo $OUTPUT->footer();
