<?php

namespace mod_facetoface\data;

use core\dml\sql_join;
use dml_exception;
use tool_organisation\api;
use tool_organisation\local\type\role_permission;

class user_sql {

    /**
     * Get SQL join data for the users reporting to a given user.
     *
     * The SQL will pull data from both the hierarchy structure and profile field assignments.
     *
     * @param int $user_id
     * @return sql_join
     * @throws dml_exception
     */
    public static function get_my_users_sql(int $user_id): sql_join {
        $hierarchy_join = self::get_my_hierarchy_users_sql($user_id);
        $profile_field_join = self::get_my_profile_field_users_sql($user_id);

        $join = $hierarchy_join->joins . "\n" . $profile_field_join->joins;

        $where = "(
            ( $hierarchy_join->wheres ) or
            ( $profile_field_join->wheres )
        )";

        $params = array_merge(
            $hierarchy_join->params,
            $profile_field_join->params
        );

        return new sql_join($join, $where, $params);
    }

    /**
     * Get SQL join data for reporting users based on the hierarchy.
     *
     * @param int $user_id ID of user to get reporting users for.
     * @return sql_join SQL join data.
     */
    private static function get_my_hierarchy_users_sql(int $user_id): sql_join {
        [
            'joins' => $join,
            'where' => $where,
            'params' => $params,
        ] = api::get_myusers_sql(
            $user_id,
            true,
            [
                role_permission::MANAGER,
                role_permission::MANAGE_USERS,
            ]
        );

        // Replace the first full join with a left join to prevent restricting results.
        $join = substr_replace($join, 'left join', 0, 4);

        return new sql_join($join, $where, $params);
    }

    /**
     * Get SQL join data for reporting users based on profile fields.
     *
     * @param int $user_id ID of user to get reporting users for.
     * @return sql_join SQL join data.
     * @throws dml_exception
     */
    private static function get_my_profile_field_users_sql(int $user_id): sql_join {
        global $DB;

        $posid = $DB->get_record('user_info_field', [ 'shortname' => 'posid' ]);
        $repdel = $DB->get_record('user_info_field', [ 'shortname' => 'repdel' ]);
        $reportsto = $DB->get_record('user_info_field', [ 'shortname' => 'reportsto' ]);

        $join = "
            left join {user_info_data} ab on
                ab.userid = :pfu_user1 and
                ab.fieldid = :pfu_posid

            left join {user_info_data} ae on
                ae.userid = :pfu_user2 and
                ae.fieldid = :pfu_repdel

            left join {user_info_data} aa on
                aa.userid = u.id and
                aa.fieldid = :pfu_reportsto
        ";

        $where = "
            (
                ab.data > 1 and
                aa.data = ab.data
            ) or
            (
                ae.data > 1 and
                aa.data = ae.data
            )
        ";

        $params = [
            'pfu_user1' => $user_id,
            'pfu_user2' => $user_id,
            'pfu_posid' => $posid->id,
            'pfu_repdel' => $repdel->id,
            'pfu_reportsto' => $reportsto->id,
        ];

        return new sql_join($join, $where, $params);
    }
}