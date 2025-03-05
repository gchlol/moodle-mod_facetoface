<?php
/**
 * Enumeration class for attendance sheet columns.
 *
 * This file defines the attendance_sheet_column class, which extends the
 * attendance_column class. It provides additional constants that are specific
 * to the attendance sheet columns used in the mod_facetoface module.
 *
 * @package    mod_facetoface
 * @copyright  2024-5 Gold Coast Health
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_facetoface\enum;

class attendance_sheet_column extends attendance_column {
    // Start from 10, so that attendance_column may add more columns in the future
    const HEADER_ONLY = 10;
    const HEADER_AND_ROWS = 11;
}