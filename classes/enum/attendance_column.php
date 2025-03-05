<?php

namespace mod_facetoface\enum;

class attendance_column extends enum_base {

    const NAME      = 0;
    const USERNAME  = 1; // Moodle's native `username` field is used to store the payroll number.
    const EMAIL     = 2;
    const UNIT      = 3;
    const POSITION  = 4;
    const STREAM    = 5;
    const PAYPOINT  = 6;
    const SIGNATURE = 7;

    /**
     * Map the column header names on attendance_sheet.php to the enum values.
     * @var array<string, int>
     */
    private static $attendancecolumnsmap = [
        'Name'      => self::NAME,
        'Payroll'   => self::USERNAME,
        'Email'     => self::EMAIL,
        'Org Unit'  => self::UNIT,
        'Position'  => self::POSITION,
        'Stream'    => self::STREAM,
        'Paypoint'  => self::PAYPOINT,
        'Signature' => self::SIGNATURE,
    ];

    /**
     * Inverse mapping for display purposes.
     * Computed on first access to avoid duplicating the mapping.
     * @var array<int, string>|null
     */
    private static $inverseattendancecolumnsmap = null;

    /**
     * Map the attendance_sheet table GUI header names to their corresponding enum values.
     *
     * @return array<string, int>
     */
    public static function map_attendance_columns_names_to_enums(): array {
        return self::$attendancecolumnsmap;
    }

    /**
     * Map the attendance_sheet table enum values to their corresponding GUI header names.
     *
     * @return array<int, string>
     */
    public static function map_attendance_columns_enums_to_names(): array {
        if (self::$inverseattendancecolumnsmap === null) {
            self::$inverseattendancecolumnsmap = array_flip(self::$attendancecolumnsmap);
        }
        return self::$inverseattendancecolumnsmap;
    }
}