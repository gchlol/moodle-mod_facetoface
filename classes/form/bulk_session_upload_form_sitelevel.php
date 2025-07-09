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

namespace mod_facetoface\form;
use moodle_url;
use html_writer;

defined('MOODLE_INTERNAL') || die();

/**
 * CSV upload form for Face-to-Face site-wide (admin context).
 * @package   mod_facetoface
 */
class bulk_session_upload_form_sitelevel extends bulk_session_upload_form_parent {
    protected function get_form_header(): string {
        return get_string('sitebulkuploadheader', 'mod_facetoface'); // "Upload Sessions for Any Courses"
    }

    protected function get_example_csv(): string {
        $url = new moodle_url('/mod/facetoface/example_bulksessions.csv');
        // 'examplebulksessionscsv' points to "example_bulksessions.csv".
        return html_writer::link($url, get_string('examplebulksessionscsv', 'mod_facetoface'));
    }

    protected function get_csv_field_definitions(): array {
        $fields = [
            ['field' => 'csvuploadhelp:courseshortname',    'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:text'],
            ['field' => 'csvuploadhelp:activityname',       'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:text']
        ];
        // Append the standard session fields defined in the activity form.
        $fields = array_merge($fields, (new bulk_session_upload_form_activitylevel())->get_csv_field_definitions());
        return $fields;
    }
}
