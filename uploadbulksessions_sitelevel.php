<?php
/**
 * Handles bulk session uploads for the Face-to-Face module.
 * Manages CSV validation, preview, and session creation.
 *
 * Child script for site admin context
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');

use mod_facetoface\form\bulk_session_upload_form_sitelevel;
use mod_facetoface\form\bulk_session_confirm_form_sitelevel;
use mod_facetoface\_bulk_session_manager_admin;

admin_externalpage_setup('modfacetoface_sitebulkupload');
// Require site configuration capability.
require_capability('moodle/site:config', context_system::instance());

// Page setup for site context.
$PAGE->set_url('/mod/facetoface/uploadbulksessions_sitelevel.php');
$PAGE->set_title(get_string('facetoface:sitebulksessions', 'mod_facetoface'));
$PAGE->set_heading(get_string('pluginname', 'mod_facetoface'));

// Prepare variables for parent script.
$sitelevel           = true;
$uploadFormClassName = bulk_session_upload_form_sitelevel::class;
$uploadFormOptions   = [];  // no custom data needed for site form
$uploadFormDefaults  = ['validate' => 1];
$confirmFormClassName = bulk_session_confirm_form_sitelevel::class;
$confirmFormOptions   = ['fileid' => 0, 'process' => 1];
$bulkManager          = new _bulk_session_manager_admin();
// URLs for redirects and error handling.
$cancelurl    = new moodle_url('/admin/search.php', ['']) . '#linkmodules';
$successurl   = new moodle_url('/mod/facetoface/uploadbulksessions_sitelevel.php');
$errorbackurl = new moodle_url('/mod/facetoface/uploadbulksessions_sitelevel.php');

// Include shared logic.
require_once($CFG->dirroot . '/mod/facetoface/uploadbulksessions_parent.php');
