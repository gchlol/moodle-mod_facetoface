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
 * Upload form for csv file to handle enrolment of bookings in bulk.
 *
 * @author     Jonas Sajonas
 * @copyright  GCHLOL, 2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');

use core\output\notification;
use mod_facetoface\form\site_bulk_session_upload_form;
use mod_facetoface\form\site_bulk_session_confirm_form;
use mod_facetoface\site_bulk_manager;


// Setup the admin page; must match the admin_externalpage ID from settings.php:
admin_externalpage_setup('modfacetoface_sitebulkupload');

// Optional: ensure user has site config capability (though admin_externalpage_setup often does):
require_capability('moodle/site:config', context_system::instance());

// Prepare a return URL to go back to the new Face-to-Face settings page.
$returnurl = new moodle_url('/admin/settings.php', ['section' => 'modfacetoface_settings']);

// Instantiate your site-level bulk-upload form:
$mform = new \mod_facetoface\form\site_bulk_session_upload_form();

// If form is cancelled:
if ($mform->is_cancelled()) {
    redirect($returnurl);
}

// If form is submitted:
if ($data = $mform->get_data()) {
    // Handle the file manager / parse CSV / do “bulk session” logic at the site level...
    // Then redirect with success or error message.

    redirect($returnurl, get_string('f2fbulksessionsdone', 'mod_facetoface'));
}

// Otherwise display the page:
$PAGE->set_url('/mod/facetoface/sitebulkupload.php');
$PAGE->set_title(get_string('f2fbulksessions', 'mod_facetoface'));
$PAGE->set_heading(get_string('pluginname', 'mod_facetoface'));

echo $OUTPUT->header();
$mform->display();
echo $OUTPUT->footer();
