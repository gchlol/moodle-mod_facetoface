<?php
// classes/form/bulk_session_upload_form_activitylevel.php
namespace mod_facetoface\form;
use moodle_url;
use html_writer;

defined('MOODLE_INTERNAL') || die();

/**
 * CSV upload form for Face-to-Face activity (course-level).
 * @package   mod_facetoface
 */
class bulk_session_upload_form_activitylevel extends bulk_session_upload_form_parent {
    protected function get_form_header(): string {
        return get_string('uploadbulksessions', 'mod_facetoface');
    }

    protected function get_example_csv(): string {
        $url = new moodle_url('/mod/facetoface/example_sessions.csv');
        // 'examplesessionscsv' points to "example_sessions.csv".
        return html_writer::link($url, get_string('examplesessionscsv', 'mod_facetoface'));
    }

    protected function get_csv_field_definitions(): array {
        return [
            ['field' => 'csvuploadhelp:fieldsessiondatetime', 'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:yesorno'],
            ['field' => 'csvuploadhelp:startdate',            'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:date'],
            ['field' => 'csvuploadhelp:starttime',            'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:time'],
            ['field' => 'csvuploadhelp:finishdate',           'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:date'],
            ['field' => 'csvuploadhelp:finishtime',           'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:time'],
            ['field' => 'csvuploadhelp:allowcancellations',   'requirement' => 'csvuploadhelp:optional',  'format' => 'csvuploadhelp:yesorno'],
            ['field' => 'csvuploadhelp:capacity',             'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:num'],
            ['field' => 'csvuploadhelp:allowoverbookings',    'requirement' => 'csvuploadhelp:optional',  'format' => 'csvuploadhelp:yesorno'],
            ['field' => 'csvuploadhelp:duration',             'requirement' => 'csvuploadhelp:required',  'format' => 'csvuploadhelp:mins'],
            ['field' => 'csvuploadhelp:cost',                 'requirement' => 'csvuploadhelp:optional',  'format' => 'csvuploadhelp:num'],
            ['field' => 'csvuploadhelp:discount',             'requirement' => 'csvuploadhelp:optional',  'format' => 'csvuploadhelp:num'],
            ['field' => 'csvuploadhelp:details',              'requirement' => 'csvuploadhelp:optional',  'format' => 'csvuploadhelp:text'],
            ['field' => 'csvuploadhelp:customfieldfacility',  'requirement' => 'csvuploadhelp:optional',  'format' => 'csvuploadhelp:text'],
            ['field' => 'csvuploadhelp:customfieldlocation',  'requirement' => 'csvuploadhelp:optional',  'format' => 'csvuploadhelp:text'],
            ['field' => 'csvuploadhelp:customfieldroom',      'requirement' => 'csvuploadhelp:optional',  'format' => 'csvuploadhelp:text']
        ];
    }
}
