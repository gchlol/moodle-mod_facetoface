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

namespace mod_facetoface\util;

use completion_completion;

/**
 * External facetoface API.
 *
 * @package    mod_facetoface
 * @copyright  2020 Gold Coast Health
 * @author     Matthew Fein
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_util {

    /**
     * Recalculate course completion for a given user.
     *
     * @param int $course_id Target course ID.
     * @param int $user_id Target user ID.
     * @param int|null $time_started Optional `timestarted` to set if not already set. Defaults to now.
     * @return void
     */
    public static function recalculate_course_for_user(int $course_id, int $user_id, ?int $time_started = null): void {
        $completion = new completion_completion([
            'userid' => $user_id,
            'course' => $course_id,
        ]);

        // Unset `timecompleted` to force reaggregation as set in `mark_inprogress()`.
        $completion->timecompleted = null;
        $completion->mark_inprogress($time_started);

        // Trigger aggregation to instantly update course completion.
        aggregate_completions($completion->id);
    }
}
