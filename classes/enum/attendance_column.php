<?php
/**
 * Convert between attendance column names and their enums.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Nicholas Lambell, Yucheng Zhu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_facetoface\enum;

class attendance_column extends enum_base {
    const NAME      = 0;
    const USERNAME  = 1; // Moodle's native `username` field is used to store the payroll number.
    const EMAIL     = 2;
    const UNIT      = 3;
    const POSITION  = 4;
    const STREAM    = 5;
    const PAYPOINT  = 6;

    /**
     * Map the column header names in attendance_sheet.php to the enum values.
     * @var array<string, int>|null
     */
    private static $attendancecolumnsmap = null;

    /**
     * Inverse mapping for display purposes.
     * Computed on first access to avoid duplicating the mapping.
     * @var array<int, string>|null
     */
    private static $inverseattendancecolumnsmap = null;

    /**
     * Constructor initializes the attendance columns map.
     *
     * @throws \coding_exception
     */
    public function __construct() {
        if (is_null(self::$attendancecolumnsmap)) {
            self::$attendancecolumnsmap = [
                get_string('attendancecolumn:0', 'facetoface') => self::NAME,
                get_string('attendancecolumn:1', 'facetoface') => self::USERNAME,
                get_string('attendancecolumn:2', 'facetoface') => self::EMAIL,
                get_string('attendancecolumn:3', 'facetoface') => self::UNIT,
                get_string('attendancecolumn:4', 'facetoface') => self::POSITION,
                get_string('attendancecolumn:5', 'facetoface') => self::STREAM,
                get_string('attendancecolumn:6', 'facetoface') => self::PAYPOINT,
            ];
        }
    }

    /**
     * Map the attendance_sheet table GUI header names to their corresponding enum values.
     *
     * @return array<string, int>
     */
    public static function map_attendance_columns_names_to_enums(): array {
        if (is_null(self::$attendancecolumnsmap)) {
            // Ensure the constructor runs to initialize the map.
            new self();
        }
        return self::$attendancecolumnsmap;
    }

    /**
     * Map the attendance_sheet table enum values to their corresponding GUI header names.
     *
     * @return array<int, string>
     */
    public static function map_attendance_columns_enums_to_names(): array {
        if (is_null(self::$attendancecolumnsmap)) {
            // Ensure the constructor runs to initialize the map.
            new self();
        }
        if (is_null(self::$inverseattendancecolumnsmap)) {
            self::$inverseattendancecolumnsmap = array_flip(self::$attendancecolumnsmap);
        }
        return self::$inverseattendancecolumnsmap;
    }
}
