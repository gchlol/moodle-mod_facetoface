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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;

/**
 * Step definitions for mod_facetoface.
 *
 * @package    mod_facetoface
 * @category   test
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_facetoface extends behat_base {
    /**
     * Run the Face-to-Face cron task and save the events that it logs.
     *
     * The task runs in the Behat process, where the standard log store keeps events in a buffer until the
     * process ends. Reloading the log manager writes them, so the log report shows them.
     *
     * @When I run the Face-to-Face cron task
     * @return void
     */
    public function i_run_the_facetoface_cron_task(): void {
        $this->execute('behat_general::i_run_the_scheduled_task', [ '\mod_facetoface\task\cron_task' ]);
        get_log_manager(true);
    }

    /**
     * Upload a bookings CSV file built from a table whose first row holds the headers.
     *
     * Session IDs are unknown before the scenario runs, so the session column holds each session's
     * details text, which this step replaces with the session ID.
     *
     * @When I upload a Face-to-Face bookings file to the :filemanager filemanager:
     * @param string $filemanager The label of the file manager field.
     * @param TableNode $table The CSV rows, starting with the headers.
     * @return void
     */
    public function i_upload_a_facetoface_bookings_file(string $filemanager, TableNode $table): void {
        $rows = $table->getRows();
        $sessioncolumn = array_search('session', array_map('strtolower', $rows[0]), true);

        $filepath = make_request_directory() . '/bookings.csv';
        $handle = fopen($filepath, 'w');
        foreach ($rows as $index => $row) {
            if ($index > 0 && $sessioncolumn !== false) {
                $row[$sessioncolumn] = static::get_session_id($row[$sessioncolumn]);
            }
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);

        $this->execute('behat_repository_upload::i_upload_file_to_filemanager', [ $filepath, $filemanager ]);
    }

    /**
     * Check that the log report has an entry for a session that lists exactly the given users' IDs.
     *
     * User and session IDs are unknown before the scenario runs, so this step looks them up.
     *
     * @Then the :eventname log for :session should list the user ids of :usernames
     * @param string $eventname The event name in the log report.
     * @param string $session The session's details text.
     * @param string $usernames The usernames, separated by commas.
     * @return void
     * @throws ExpectationException If no log entry matches.
     */
    public function the_log_for_session_should_list_the_user_ids_of(string $eventname, string $session, string $usernames): void {
        $userids = [];
        foreach (explode(',', $usernames) as $username) {
            $userids[] = $this->get_user_id_by_identifier(trim($username))
                ?? throw new ExpectationException("The user '$username' does not exist.", $this->getSession());
        }
        sort($userids, SORT_NUMERIC);

        $sessionid = static::get_session_id($session);
        $texts = [ $eventname, "user ids '" . implode(', ', $userids) . "'", "session with id '$sessionid'" ];
        $xpath = "//table[contains(concat(' ', normalize-space(@class), ' '), ' reportlog ')]/tbody/tr";
        foreach ($texts as $text) {
            $xpath .= '[contains(normalize-space(.), ' . behat_context_helper::escape($text) . ')]';
        }

        $this->find('xpath', $xpath, new ExpectationException(
            "No '$eventname' log for '$session' lists the user ids of '$usernames'.",
            $this->getSession()
        ));
    }

    /**
     * Get the ID of the session with the given details text.
     *
     * @param string $details The session's details text.
     * @return int The session ID.
     */
    protected static function get_session_id(string $details): int {
        global $DB;

        $select = $DB->sql_compare_text('details') . ' = ' . $DB->sql_compare_text(':details');

        return (int)$DB->get_field_select('facetoface_sessions', 'id', $select, [ 'details' => $details ], MUST_EXIST);
    }
}
