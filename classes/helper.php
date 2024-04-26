<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_facetoface;

use context_user;
use file_storage;
use stored_file;
use moodle_exception;

/**
 * Helper functions for plugin.
 *
 * @package    mod_facetoface
 * @author     Andrew Madden <andrewmadden@catalyst-au.net>
 * @copyright  2023 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {

    /**
     * Check if manager approval is required for a particular activity.
     *
     * @param \stdClass $instance DB record of a facetoface activity.
     * @return bool
     *
     * @throws \coding_exception
     */
    public static function is_approval_required(\stdClass $instance): bool {
        // Check the object contains expected data.
        if (!property_exists($instance, 'id') || !property_exists($instance, 'approvalreqd')) {
            throw new \coding_exception('Expected facetoface record to contain an id and approvalreqd property');
        }

        // Approvals must be enabled at site level and activity level.
        return get_config('facetoface', 'enableapprovals') && $instance->approvalreqd;
    }

    /**
     * Returns file from file system. File must exist.
     * @param int $fileitemid Item id of file stored in the current $USER's draft file area
     * @return stored_file
     */
    public static function get_file(int $fileitemid): stored_file {
        global $USER;
        $fs = new file_storage();
        $files = $fs->get_area_files(context_user::instance($USER->id)->id, 'user', 'draft', $fileitemid, 'itemid', false);

        if (count($files) != 1) {
            throw new moodle_exception('error:cannotloadfile', 'mod_facetoface');
        }

        return current($files);
    }
}
