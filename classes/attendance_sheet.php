<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

namespace mod_facetoface;

/**
 * Attendance sheet class.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attendance_sheet {

    /**
     * Full name column.
     */
    const COLUMN_NAME = 0;

    /**
     * Username column.
     */
    const COLUMN_USERNAME = 1;

    /**
     * Email column.
     */
    const COLUMN_EMAIL = 2;

    /**
     * Org unit column.
     */
    const COLUMN_UNIT = 3;

    /**
     * Position column.
     */
    const COLUMN_POSITION = 4;

    /**
     * Stream column.
     */
    const COLUMN_STREAM = 5;

    /**
     * Paypoint column.
     */
    const COLUMN_PAYPOINT = 6;

    /**
     * Get list of columns.
     *
     * @return int[]
     */
    public static function get_column_list(): array {
        return [
            self::COLUMN_NAME,
            self::COLUMN_USERNAME,
            self::COLUMN_EMAIL,
            self::COLUMN_UNIT,
            self::COLUMN_POSITION,
            self::COLUMN_STREAM,
            self::COLUMN_PAYPOINT,
        ];
    }

    /**
     * Get list of columns with names.
     *
     * @return array
     */
    public static function get_columns(): array {
        $columns = self::get_column_list();

        $result = [];
        foreach ($columns as $column) {
            $result[$column] = self::get_column_name($column);
        }

        return $result;
    }

    /**
     * Get column language string.
     *
     * @param int $column
     * @return string
     */
    public static function get_column_name(int $column): string {
        return get_string('attendancecolumn:' . $column, 'facetoface');
    }
}
