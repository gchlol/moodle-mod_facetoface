<?php
/**
 * Form for uploading bulk session CSV files in Face-to-Face module.
 * Provides file selection, validation, and preview before processing.
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
 * CSV upload form for Face-to-Face activity (course-level).
 *
 * @package   mod_facetoface
 */
class bulk_session_upload_form_activitylevel extends bulk_session_upload_form_parent {
    protected string $formelementkey = 'f2fid';
    protected string $headername = 'settingsheader';
    public function __construct() {
        parent::__construct();

        $this->headerstring = get_string('uploadbulksessions', 'mod_facetoface');
    }

    protected function get_form_header(): string {
        return get_string('uploadbulksessions', 'mod_facetoface');
    }

    protected function get_example_csv(): string {
        $url = new moodle_url('/mod/facetoface/example_sessions.csv');
        // 'examplesessionscsv' points to "example_sessions.csv".
        return html_writer::link($url, get_string('examplesessionscsv', 'mod_facetoface'));
    }

    protected function get_csv_field_definitions(): array {
        return parent::get_csv_field_definitions();
    }
}
