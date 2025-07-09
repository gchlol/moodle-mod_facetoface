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
defined('MOODLE_INTERNAL') || die();

/**
 * Confirmation form for bulk session processing (activity context).
 * @package   mod_facetoface
 */
class bulk_session_confirm_form_activitylevel extends bulk_session_confirm_form_parent {
    // No additional fields needed; uses parent definition.
}
