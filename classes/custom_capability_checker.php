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

use dml_exception;
use mod_facetoface\data\user_sql;

/**
 * checks user capabilities.
 *
 * @package     mod_facetoface
 * @copyright   2021 Queensland Health <daryl.batchelor@health.qld.gov.au>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_capability_checker {

    /**
     * @var bool
     */
    public $manager_permissions;


    function __construct() {
        $this->manager_permissions = $this->getViewPermissions();
    }

    /**
     * Checks if user has view permissions
     *
     * @return bool
     * @throws dml_exception
     */
    private function getViewPermissions(): bool {
        global $USER, $DB;

        $users_join = user_sql::get_my_users_sql($USER->id);

        $sql = "
            SELECT  COUNT(u.id)
            FROM    {user} u
                    $users_join->joins
            WHERE   u.suspended = 0 AND
                    $users_join->wheres
        ";

        $potentialmemberscount = $DB->count_records_sql($sql, $users_join->params);

        return $potentialmemberscount > 0;
    }
}