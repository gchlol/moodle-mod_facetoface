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

use context_course;
use context_user;
use Exception;
use file_storage;
use Generator;
use lang_string;
use mod_facetoface\local\booking_upload_service;
use moodle_exception;
use stdClass;

/**
 * Bulk version of booking_manager.
 *
 * Located in site administration.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_manager_bulk_attendance {

    /** @var stored_file File to process. */
    private $file;

    /** @var stdClass[] Collection of records loaded from memory. */
    private $records = [];

    /** @var bool Whether bookings are loaded from a file. */
    private $usefile = true;

    /** @var bool Whether confirmation emails are suppressed. */
    private $suppressemail = false;

    /** @var bool Whether username matching ignores case. */
    private $caseinsensitive = false;

    /**
     * Constructor.
     *
     * @param stdClass[] $records Records to process.
     */
    public function __construct($records = []) {
        $this->records = $records;
    }

    /**
     * Load CSV data from a draft file area.
     *
     * @param int $fileitemid Draft file-area item ID.
     * @return void
     * @throws moodle_exception When exactly one upload file cannot be found.
     */
    public function load_from_file(int $fileitemid): void {
        global $USER;

        $this->usefile = true;
        $fs = new file_storage();
        $files = $fs->get_area_files(
            context_user::instance($USER->id)->id,
            'user',
            'draft',
            $fileitemid,
            'itemid',
            false
        );

        if (count($files) != 1) {
            throw new moodle_exception('error:cannotloadfile', 'mod_facetoface');
        }

        $this->file = current($files);
    }

    /**
     * Load records from memory.
     *
     * @param stdClass[] $records Record objects.
     * @return self
     */
    public function load_from_array(array $records): self {
        $this->usefile = false;
        $this->records = $records;

        return $this;
    }

    /**
     * Return the site-wide CSV headers.
     *
     * @return string[] Headers in file order.
     */
    public static function get_headers(): array {
        return [
            'Username',
            'Session',
            'Status',
            'Discount Code',
            'Notification Type',
        ];
    }

    /**
     * Return a fresh iterator over file or in-memory records.
     *
     * @return Generator CSV rows.
     * @throws moodle_exception When a file row has the wrong number of fields.
     */
    private function get_iterator(): Generator {
        if (!$this->usefile) {
            foreach ($this->records as $record) {
                yield $record;
            }

            return;
        }

        $handle = $this->file->get_content_file_handle();
        $maxlinelength = 1000;
        $delimiter = ',';
        $headers = static::get_headers();
        $numheaders = count($headers);
        $fileheaders = fgetcsv($handle, $maxlinelength, $delimiter);
        $hasdiscount = false;

        if ($fileheaders !== false) {
            $normalisedheaders = array_map(
                static function(string $header): string {
                    return strtolower(trim($header));
                },
                $fileheaders
            );
            $hasdiscount = in_array('discount code', $normalisedheaders, true);
        }

        $discountposition = array_search('Discount Code', $headers, true);

        try {
            while (($data = fgetcsv($handle, $maxlinelength, $delimiter)) !== false) {
                if (!$hasdiscount && $discountposition !== false) {
                    array_splice($data, $discountposition, 0, '');
                }

                if (count($data) !== $numheaders) {
                    throw new moodle_exception(
                        'error:bookingsuploadfileheaderfieldmismatch',
                        'mod_facetoface'
                    );
                }

                yield (object)array_combine($headers, $data);
            }

        } finally {
            fclose($handle);
        }
    }

    /**
     * Validate records while preserving the site-wide uploader's error order and early exits.
     *
     * @param int|null $timenow Current time to use for validation.
     * @return list<array{0:int|string, 1:string|lang_string}> Validation errors keyed by CSV row.
     */
    public function validate($timenow = null): array {
        global $DB;

        $errors = [];
        $validationrows = [];
        $activesignupcache = [];
        $signupexistencecache = [];
        $timenow ??= time();
        $uploadservice = $this->get_upload_service();

        foreach ($this->get_iterator() as $index => $entry) {
            $row = $index + 1;
            $errorcountbefore = count($errors);
            [$username, $sessionref, $status, $discountcode, $notifytype] = $this->extract_row_fields($entry);

            $session = facetoface_get_session($sessionref);
            if (!$session) {
                $errors[] = [$row, get_string('error:sessiondoesnotexist', 'mod_facetoface', $sessionref)];
                continue;
            }

            $facetoface = $DB->get_record('facetoface', ['id' => $session->facetoface]);
            if (!$facetoface) {
                $errors[] = [
                    $row,
                    get_string('error:activitydoesnotexist', 'facetoface', $session->facetoface),
                ];
                continue;
            }

            $course = $DB->get_record('course', ['id' => $facetoface->course]);
            if (!$course) {
                $errors[] = [
                    $row,
                    get_string('error:coursemisconfigured', 'facetoface', $facetoface->course),
                ];
                continue;
            }

            $userids = $this->match_users($username, 'id');
            if (count($userids) > 1) {
                $errors[] = [$row, get_string('error:multipleusersmatched', 'mod_facetoface', $username)];
                continue;
            }

            if (empty($userids)) {
                $errors[] = [$row, get_string('error:userdoesnotexist', 'mod_facetoface', $username)];
                continue;
            }

            $userid = current($userids)->id;
            if (!$uploadservice->validate_existing_booking_upload(
                $row,
                $username,
                $session,
                $status,
                (int)$userid,
                $errors,
                $signupexistencecache,
                $activesignupcache
            )) {
                continue;
            }

            if (!$uploadservice->validate_session_status_rules(
                $row,
                $sessionref,
                $status,
                $session,
                (int)$timenow,
                $errors
            )) {
                continue;
            }

            $coursecontext = context_course::instance($course->id);
            if (!is_enrolled($coursecontext, $userid)) {
                $isenrolled = facetoface_enrol_user($coursecontext, $course->id, $userid);
                if (!$isenrolled) {
                    $errors[] = [$row, get_string('error:enrolmentfailed', 'mod_facetoface', $username)];
                }
            }

            $mappednotification = $this->transform_notification_type($notifytype);
            if ($mappednotification === null) {
                $errors[] = [
                    $row,
                    get_string('error:invalidnotificationtypespecified', 'mod_facetoface', $notifytype),
                ];
            }

            if (!$uploadservice->is_processable_status($status)) {
                $errors[] = [$row, get_string('error:invalidstatusspecified', 'mod_facetoface', $status)];
            }

            if (
                count($errors) === $errorcountbefore &&
                $uploadservice->is_processable_status($status)
            ) {
                $uploadservice->cache_validation_row(
                    $row,
                    $username,
                    $status,
                    $session,
                    (int)$userid,
                    $validationrows,
                    $activesignupcache
                );
            }
        }

        $uploadservice->validate_unique_rows_and_capacity($validationrows, $errors);

        return $errors;
    }

    /**
     * Process all rows without validation errors.
     *
     * Attendance rows can create or reactivate a signup before applying attendance.
     *
     * @param list<array{0:int|string, 1:string|lang_string}> $errors Validation errors from validate().
     * @return bool True after all processable rows have been handled.
     * @throws moodle_exception When attendance cannot be applied.
     * @throws Exception When cancellation fails.
     */
    public function process($errors): bool {
        $skip = booking_upload_service::extract_rows_to_skip($errors);
        $uploadservice = $this->get_upload_service();

        foreach ($this->get_iterator() as $index => $entry) {
            $row = $index + 1;
            if (isset($skip[$row])) {
                continue;
            }

            [$username, $sessionref, $status, $discountcode, $notifytype] = $this->extract_row_fields($entry);
            $user = current($this->match_users($username, '*'));
            $session = facetoface_get_session($sessionref);
            [$facetoface, $course] = $this->get_session_activity_context($session);
            $normalisedentry = (object)[
                'username' => $username,
                'status' => $status,
                'discountcode' => $discountcode,
            ];

            $uploadservice->process_row(
                $normalisedentry,
                $session,
                $facetoface,
                $course,
                context_course::instance($course->id),
                $user,
                $row,
                $this->transform_notification_type($notifytype)
            );
        }

        return true;
    }

    /**
     * Return the shared workflow configured with the manager's current options.
     *
     * @return booking_upload_service Shared upload workflow.
     */
    private function get_upload_service(): booking_upload_service {
        return new booking_upload_service($this->usefile, $this->suppressemail, false);
    }

    /**
     * Return normalised values from a site-wide row.
     *
     * @param stdClass $entry Raw CSV row.
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string} Username, session, status,
     *     discount code, and notification type.
     */
    private function extract_row_fields(stdClass $entry): array {
        return [
            trim($entry->Username),
            trim($entry->Session),
            trim($entry->Status ?? ''),
            trim($entry->{'Discount Code'} ?? ''),
            trim($entry->{'Notification Type'} ?? ''),
        ];
    }

    /**
     * Load the activity and course for a session during processing.
     *
     * @param stdClass $session Session record.
     * @return array{0:stdClass, 1:stdClass} Activity and course records.
     */
    private function get_session_activity_context(stdClass $session): array {
        global $DB;

        $facetoface = $DB->get_record(
            'facetoface',
            ['id' => $session->facetoface],
            '*',
            MUST_EXIST
        );
        $course = $DB->get_record(
            'course',
            ['id' => $facetoface->course],
            '*',
            MUST_EXIST
        );

        return [$facetoface, $course];
    }

    /**
     * Match users by username.
     *
     * @param string $username Username to search.
     * @param string $fields Fields to return.
     * @return array<int, stdClass> Matching users keyed by user ID.
     */
    private function match_users(string $username, string $fields): array {
        global $DB;

        $equals = $DB->sql_equal('username', ':username', !$this->caseinsensitive);

        return $DB->get_records_select(
            'user',
            $equals,
            ['username' => $username],
            'id',
            $fields
        );
    }

    /**
     * Map a notification string to its internal code.
     *
     * @param string $type Notification type.
     * @return int|null Notification code, or null when invalid.
     */
    private function transform_notification_type(string $type): ?int {
        $mapping = [
            'email' => MDL_F2F_TEXT,
            'ical' => MDL_F2F_ICAL,
            'icalendar' => MDL_F2F_ICAL,
            'both' => MDL_F2F_BOTH,
            '' => MDL_F2F_ICAL,
        ];

        return $mapping[strtolower($type)] ?? null;
    }

    /**
     * Suppress confirmation emails.
     *
     * @return void
     */
    public function suppress_email(): void {
        $this->suppressemail = true;
    }

    /**
     * Configure case-insensitive username matching.
     *
     * @param bool $value True to ignore case.
     * @return void
     */
    public function set_case_insensitive(bool $value): void {
        $this->caseinsensitive = $value;
    }

    /**
     * Return all raw CSV rows for preview.
     *
     * @return stdClass[] CSV rows.
     */
    public function get_records(): array {
        return iterator_to_array($this->get_iterator());
    }
}
