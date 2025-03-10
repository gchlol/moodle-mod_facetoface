<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package   mod_facetoface
 * @copyright 2025, Gold Coast Health
 * @author    Jonas Sajonas
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/tablelib.php');

use core\output\notification;

require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('modfacetoface_uploadf2fselect');

$PAGE->set_url(new moodle_url('/mod/facetoface/uploadf2fselect.php'));
$PAGE->set_title(get_string('pickfacetofaceinstance', 'mod_facetoface'));
$PAGE->set_heading(get_string('pluginname', 'mod_facetoface'));

// Search and paging parameters.
$search  = optional_param('search', '', PARAM_RAW);
$page    = optional_param('page', 0, PARAM_INT);
$perpage = 10;

// Search form.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pickfacetofaceinstance', 'mod_facetoface'));
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url]);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'search', 'value' => s($search)]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('search')]);
echo html_writer::end_tag('form');

// Searching facetoface by name.
$params = [];
$wheresql = '';
if (!empty($search)) {
    $wheresql = "WHERE name LIKE :search";
    $params['search'] = '%'.$search.'%';
}

// Count total records for paging.
$countsql = "SELECT COUNT(*) FROM {facetoface} $wheresql";
$totalcount = $DB->count_records_sql($countsql, $params);

// Fetch the current page of records.
$start = $page * $perpage;
$sql   = "SELECT * FROM {facetoface} $wheresql ORDER BY name ASC";
$facetofaces = $DB->get_records_sql($sql, $params, $start, $perpage);

// Paging bar to navigate results.
$baseurl = new moodle_url('/mod/facetoface/uploadf2fselect.php', ['search' => $search]);
echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $baseurl);

// Display table.
if (empty($facetofaces)) {
    echo $OUTPUT->notification(get_string('nofacetofaceinstances', 'mod_facetoface'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('facetofacename', 'mod_facetoface'),
        get_string('course'),
        get_string('action'),
    ];

    foreach ($facetofaces as $f2f) {
        $course = $DB->get_record('course', ['id' => $f2f->course], 'fullname', IGNORE_MISSING);
        $coursename = $course ? format_string($course->fullname) : get_string('unknowncourse', 'mod_facetoface');

        $uploadurl = new moodle_url('/mod/facetoface/upload.php', [
            'f' => $f2f->id,
            'fromf2fselect' => 1
        ]);

        $uploadlink = html_writer::link($uploadurl, get_string('uploadbookings', 'mod_facetoface'));

        $table->data[] = [
            format_string($f2f->name),
            $coursename,
            $uploadlink
        ];
    }

    echo html_writer::table($table);
}

// Paging bar at bottom.
echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $baseurl);

echo $OUTPUT->footer();
