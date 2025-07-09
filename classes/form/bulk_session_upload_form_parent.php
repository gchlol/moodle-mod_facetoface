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
use moodleform;
use moodle_url;
use html_writer;
use html_table;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir.'/formslib.php');
require_once($CFG->dirroot.'/repository/lib.php');

/**
 * Base form for uploading bulk session CSV (common fields for activity and site).
 * @package   mod_facetoface
 */
abstract class bulk_session_upload_form_parent extends moodleform {
    /**
     * Build the form elements common to both contexts.
     */
    public function definition(): void {
        $mform = $this->_form;
        // Context-specific header title.
        $mform->addElement('header', 'bulksessionsheader', $this->get_form_header());
        // Example CSV file link (context-specific).
        $example = $this->get_example_csv();
        $mform->addElement('static', 'examplecsv', get_string('examplecsv', 'mod_facetoface'), $example);
        // File manager for CSV upload.
        $maxbytes = get_max_upload_file_size($GLOBALS['CFG']->maxbytes);
        $mform->addElement('filemanager', 'csvfile',
            get_string('uploadsessionfile', 'mod_facetoface'), null, [
                'subdirs'        => 0,
                'maxfiles'       => 1,
                'accepted_types' => ['csv'],
                'maxbytes'       => $maxbytes,
                'return_types'   => FILE_INTERNAL | FILE_EXTERNAL
            ]
        );
        $mform->setType('csvfile', PARAM_INT);
        $mform->addRule('csvfile', get_string('required'), 'required', null, 'client');
        // Help table describing CSV fields.
        $tablehtml = $this->generate_csv_help_table();
        $mform->addElement('static', 'csvuploadhelp', '', $tablehtml);
        // Hidden field to trigger validation on submission.
        $validateFlag = $this->_customdata['validate'] ?? 1;
        $mform->addElement('hidden', 'validate', $validateFlag);
        $mform->setType('validate', PARAM_INT);
        $this->add_action_buttons(true, get_string('uploadandpreviewbulk', 'mod_facetoface'));
    }

    /**
     * Builds an HTML table describing required CSV fields.
     * @return string HTML for the help table.
     */
    protected function generate_csv_help_table(): string {
        $table = new html_table();
        $table->head = [
            get_string('csvuploadhelp:field', 'mod_facetoface'),
            get_string('csvuploadhelp:requirement', 'mod_facetoface'),
            get_string('csvuploadhelp:format', 'mod_facetoface')
        ];
        // Get field definitions (context-specific implementation).
        $rows = $this->get_csv_field_definitions();
        foreach ($rows as $row) {
            $table->data[] = [
                get_string($row['field'], 'mod_facetoface'),
                get_string($row['requirement'], 'mod_facetoface'),
                get_string($row['format'], 'mod_facetoface')
            ];
        }
        return html_writer::table($table);
    }

    // Abstract methods to be provided by child classes:

    /** @return string Localised form header string. */
    abstract protected function get_form_header(): string;
    /** @return string HTML link element for the example CSV (context-specific file). */
    abstract protected function get_example_csv(): string;
    /** @return array Definition of CSV fields (each element has 'field', 'requirement', 'format' keys). */
    abstract protected function get_csv_field_definitions(): array;
}
