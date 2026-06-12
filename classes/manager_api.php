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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_facetoface;

use core\dml\sql_join;
use dml_exception;
use tool_organisation\api;
use tool_organisation\local\type\role_permission;

/**
 * Line manager API.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager_api {

    /**
     * Get SQL join data for the users reporting to a given user.
     *
     * The SQL will pull data from both the hierarchy structure and profile field assignments.
     *
     * @param int $userid
     * @return sql_join
     * @throws dml_exception
     */
    public static function get_my_users_sql(int $userid): sql_join {
        $hjoin = self::get_my_hierarchy_users_sql($userid);
        $pjoin = self::get_my_profile_field_users_sql($userid);

        $join = $hjoin->joins . "\n" . $pjoin->joins;

        $where = "(
            ( $hjoin->wheres ) or
            ( $pjoin->wheres )
        )";

        $params = array_merge(
            $hjoin->params,
            $pjoin->params
        );

        return new sql_join($join, $where, $params);
    }

    /**
     * Is the user a line manager?
     *
     * @param int $userid
     * @return bool
     */
    public static function is_manager(int $userid): bool {
        global $DB;

        $join = self::get_my_hierarchy_users_sql($userid);

        $sql = "SELECT COUNT(DISTINCT u.id)
                FROM {user} u ".
                $join->joins ."
                WHERE u.deleted = 0
                    AND u.suspended = 0
                    AND ". $join->wheres;

        return (bool) $DB->count_records_sql($sql, $join->params);
    }

    // GCHLOL: Use local variable naming for hierarchy user lookups.
    /**
     * Get SQL join data for reporting users based on the hierarchy.
     *
     * @param int $userid ID of user to get reporting users for.
     * @return sql_join SQL join data.
     */
    private static function get_my_hierarchy_users_sql(int $userid): sql_join {
        [
            'joins' => $join,
            'where' => $where,
            'params' => $params,
        ] = api::get_myusers_sql(
            $userid,
            true,
            [
                role_permission::MANAGER,
                role_permission::MANAGE_USERS,
                role_permission::VIEW_REPORTS,
            ]
        );

        // Replace the first full join with a left join to prevent restricting results.
        $join = substr_replace($join, 'LEFT JOIN', 0, 4);

        return new sql_join($join, $where, $params);
    }
    // GCHLOL ends.

    /**
     * Get SQL join data for reporting users based on profile fields.
     *
     * @param int $userid ID of user to get reporting users for.
     * @return sql_join SQL join data.
     * @throws dml_exception
     */
    private static function get_my_profile_field_users_sql(int $userid): sql_join {
        global $DB;

        $posid = $DB->get_record('user_info_field', [ 'shortname' => 'posid' ]);
        $repdel = $DB->get_record('user_info_field', [ 'shortname' => 'repdel' ]);
        $reportsto = $DB->get_record('user_info_field', [ 'shortname' => 'reportsto' ]);

        $join = "
            LEFT JOIN {user_info_data} posid ON
                posid.userid = :pfu_user1 AND
                posid.fieldid = :pfu_posid

            LEFT JOIN {user_info_data} repdel ON
                repdel.userid = :pfu_user2 AND
                repdel.fieldid = :pfu_repdel

            LEFT JOIN {user_info_data} reportsto ON
                reportsto.userid = u.id AND
                reportsto.fieldid = :pfu_reportsto
        ";

        $where = "
            (
                posid.data > 1 AND
                reportsto.data = posid.data
            ) OR
            (
                repdel.data > 1 AND
                reportsto.data = repdel.data
            )
        ";

        $params = [
            'pfu_user1' => $userid,
            'pfu_user2' => $userid,
            'pfu_posid' => $posid->id,
            'pfu_repdel' => $repdel->id,
            'pfu_reportsto' => $reportsto->id,
        ];

        return new sql_join($join, $where, $params);
    }
}
