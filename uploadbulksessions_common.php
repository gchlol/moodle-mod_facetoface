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

use core\event\base;
use core\output\notification;
use mod_facetoface\bulk_session_manager_parent;
use mod_facetoface\form\bulk_session_confirm_form_parent;
use mod_facetoface\form\bulk_session_upload_form_parent;

/**
 * Retrieves optional parameters.
 * Default to 0 if not in the URL.
 *
 * @return array An array containing the optional parameters: file ID, validation flag, and processing flag.
 * @throws coding_exception
 */
function get_optional_params(): array {
    $fileid   = optional_param('fileid', 0, PARAM_INT);
    $validate = optional_param('validate', 0, PARAM_INT);
    $process  = optional_param('process', 0, PARAM_INT);

    return [$fileid, $validate, $process];
}

/**
 * Displays bulk-upload errors and ends execution.
 *
 * @param array $errors An array of errors to display.
 * @return html_table
 * @throws coding_exception
 */
function handle_bulk_upload_errors(array $errors): html_table {
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
        get_string('status', 'mod_facetoface'),
    ];

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

    return $table;
}

/**
 * Validates and handles the bulk session upload form submission, displaying preview or errors.
 *
 * This function performs the following tasks:
 * 1. Processes the upload form data
 * 2. Handles form cancellation
 * 3. Validates uploaded sessions
 * 4. Displays error messages if validation fails
 * 5. Shows a preview table of the CSV data if validation passes
 *
 * @param bool $validate Whether to perform validation
 * @param string $cancelurl URL to redirect to when form is cancelled
 * @param bulk_session_upload_form_parent $uploadform The upload form instance
 * @param Closure $makeconfirmform Callback that creates confirmation form instance
 * @param bulk_session_manager_parent $manager The session manager instance
 * @param Closure $bulkuploaderrorhandler Callback function to handle errors during bulk upload.
 *
 * @return void <!> IMPORTANT: The caller script shall explicitly `exit` after running this function.
 */
function handle_bulk_session_validation(
    string $cancelurl,
    bulk_session_upload_form_parent $uploadform,
    Closure $makeconfirmform,
    bulk_session_manager_parent $manager,
    Closure $bulkuploaderrorhandler
): bool {
    global $OUTPUT;

    $uploaddata = $uploadform->get_data();

    if ($uploadform->is_cancelled()) {
        redirect($cancelurl);

        exit;
    }

    // Never updated outside of this function due to `exit`
    $fileid = $uploaddata->csvfile ?: 0;

    $confirmform = $makeconfirmform($fileid);

    $manager->load_from_file($fileid);
    $errors = $manager->validate();

    // If there are errors, handle them and exit.
    if (!empty($errors)) {
        $bulkuploaderrorhandler($errors);
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

    //exit;
}

/**
 * Processes bulk session upload confirmation and handles the validation, processing, and redirect logic.
 *
 * This function manages the bulk session upload workflow including:
 * - Loading session data from file
 * - Handling form cancellation
 * - Validating session data
 * - Processing valid sessions
 * - Triggering relevant events
 * - Managing error states and redirects
 *
 * @param int $f2fid The Face-to-Face activity ID
 * @param int $fileid The uploaded file ID
 * @param string $successurl URL to redirect to when confirmation completes
 * @param string $cancelurl URL to redirect to when form is cancelled
 * @param Closure $bulkuploaderrorhandler Callback function to handle errors during bulk upload.
 * @param stdClass|null $facetoface If activity-level, the Face-to-Face instance record. If site-level, null.
 *
 * @return void <!> IMPORTANT: The caller script will `exit` after running this function.
 * @throws moodle_exception When redirect occurs
 */
function process_bulk_session_confirmation(
    int $fileid,
    string $successurl,
    string $cancelurl,
    bulk_session_confirm_form_parent $confirmform,
    bulk_session_manager_parent $manager,
    base $event,
    Closure $bulkuploaderrorhandler,
    ?stdClass $facetoface
): void {
    $manager->load_from_file($fileid);

    if ($confirmform->is_cancelled()) {
        redirect($cancelurl);

        exit;
    }

    $errors = $manager->validate();

    if (empty($errors)) {
        $success = $manager->process();

        if ($success) {
            $event->add_record_snapshot('facetoface', $facetoface);
            $event->trigger();

            redirect(
                $successurl,
                get_string('bulksessionsprocessed', 'mod_facetoface'),
                null,
                notification::NOTIFY_SUCCESS
            );
        } else {
            $bulkuploaderrorhandler($manager->get_errors());
        }
    }

    $bulkuploaderrorhandler($errors);
}
