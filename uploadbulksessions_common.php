<?php

use core\output\notification;

/**
 * Handles bulk session uploads for the Face-to-Face module.
 * Manages CSV validation, preview, and session creation.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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

