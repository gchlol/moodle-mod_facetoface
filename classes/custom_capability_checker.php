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

use tool_organisation\api;

/**
 * checks user capabilities.
 *
 * @package     mod_facetoface
 * @copyright   2021 Queensland Health <daryl.batchelor@health.qld.gov.au>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */



class custom_capability_checker{

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
     */
    private function getViewPermissions(){
        global $USER, $DB;

        $joins = $wheres = $params = [];

        [
            'joins' => $myusersjoins,
            'where' => $myuserswhere,
            'params' => $myusersparams
        ] = api::get_myusers_sql($USER->id, true);
        if (!empty($myusersjoins)) {
            $joins[] = $myusersjoins;
        }
        if (!empty($myuserswhere)) {
            $wheres[] = $myuserswhere;
        }
        if (!empty($myusersparams)) {
            $params = $myusersparams;
        }

        // Prepare final values.
        $joinsstring = implode("\n", $joins);
        $where = implode(' AND ', $wheres);


        $countfields = 'SELECT COUNT(u.id)';

        $sql = "
				  FROM {user} u
				  $joinsstring
				 
				WHERE u.suspended=0 AND $where
				";

        $potentialmemberscount = $DB->count_records_sql($countfields . $sql, $params);

        return $potentialmemberscount > 0;



    }
}