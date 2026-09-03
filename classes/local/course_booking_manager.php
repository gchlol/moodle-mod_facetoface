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

namespace mod_facetoface\local;

use context_course;
use context_user;
use Exception;
use file_storage;
use Generator;
use lang_string;
use moodle_exception;
use stdClass;
use stored_file;

/**
 * Course-level CSV booking upload facade.
 *
 * This plugin-owned facade keeps CSV loading and course-specific orchestration
 * outside the third-party booking manager. Shared booking and attendance rules
 * are delegated to booking_upload_service.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_booking_manager {

    /** @var stored_file|null Uploaded CSV file. */
    protected ?stored_file $file = null;

    /** @var int Face-to-face activity ID. */
    protected int $facetofaceid;

    /** @var stdClass Face-to-face activity record. */
    protected stdClass $facetoface;

    /** @var stdClass Course record. */
    protected stdClass $course;

    /** @var context_course Course context. */
    protected context_course $coursecontext;

    /** @var list<stdClass> In-memory upload rows. */
    protected array $records;

    /** @var bool Whether rows are loaded from a stored file. */
    protected bool $usefile = true;

    /** @var bool Whether confirmation emails are suppressed. */
    protected bool $suppressemail = false;

    /** @var bool Whether username matching ignores case. */
    protected bool $caseinsensitive = false;

    /**
     * Constructor.
     *
     * @param int $facetofaceid Face-to-face activity ID.
     * @param list<stdClass> $records Initial in-memory upload rows.
     */
    public function __construct(int $facetofaceid, array $records = []) {
        $this->facetofaceid = $facetofaceid;
        $this->records = $records;
    }

    /**
     * Load upload rows from a draft-area file.
     *
     * @param int $fileitemid Draft file area item ID.
     * @return void
     * @throws moodle_exception When the draft area does not contain exactly one file.
     */
    public function load_from_file(int $fileitemid): void {
        global $USER;

        $filesystem = new file_storage();
        $files = $filesystem->get_area_files(
            context_user::instance($USER->id)->id,
            'user',
            'draft',
            $fileitemid,
            'itemid',
            false
        );

        if (count($files) !== 1) {
            throw new moodle_exception('error:cannotloadfile', 'mod_facetoface');
        }

        $this->usefile = true;
        $this->file = current($files);
    }

    /**
     * Load upload rows from memory.
     *
     * @param list<stdClass> $records Upload rows. Email-only rows remain supported for legacy callers.
     * @return self This manager.
     */
    public function load_from_array(array $records): self {
        $this->usefile = false;
        $this->records = $records;

        return $this;
    }

    /**
     * Return the canonical CSV headers.
     *
     * @return list<string> Header names in file order.
     */
    public static function get_headers(): array {
        return [
            'username',
            'session',
            'status',
            'discountcode',
            'notificationtype',
        ];
    }

    /**
     * Validate all configured upload rows.
     *
     * @param int|null $timenow Timestamp to use for validation.
     * @return list<array{0:int|string, 1:string|lang_string}> Validation errors keyed by CSV row.
     */
    public function validate(?int $timenow = null): array {
        $this->ensure_activity_loaded();

        $errors = [];
        $validationrows = [];
        $activesignupcache = [];
        $signupexistencecache = [];
        $timenow ??= time();
        $uploadservice = $this->get_upload_service();

        foreach ($this->get_iterator() as $index => $entry) {
            $row = $index + 1;
            $errorcountbefore = count($errors);
            $entry->status = $entry->status ?? '';
            $entry->notificationtype = $entry->notificationtype ?? '';
            $entry->discountcode = $entry->discountcode ?? '';
            $useridentifier = $this->get_user_identifier($entry);

            $userid = null;
            $userids = $this->match_entry_user($entry, 'id');
            if (count($userids) > 1) {
                $errors[] = [
                    $row,
                    new lang_string('error:multipleusersmatched', 'mod_facetoface', $useridentifier),
                ];
            }

            if (empty($userids)) {
                $errors[] = [
                    $row,
                    new lang_string('error:userdoesnotexist', 'mod_facetoface', $useridentifier),
                ];
            }

            if (count($userids) === 1) {
                $userid = current($userids)->id;
            }

            $session = facetoface_get_session($entry->session);
            if (!$session) {
                $errors[] = [
                    $row,
                    new lang_string('error:sessiondoesnotexist', 'mod_facetoface', $entry->session),
                ];
            }

            if ($session && $session->facetoface != $this->facetofaceid) {
                $errors[] = [
                    $row,
                    new lang_string('error:tryingtoupdatesessionfromanothermodule', 'mod_facetoface', (object)[
                        'session' => $entry->session,
                        'f' => $this->facetofaceid,
                    ]),
                ];
            }

            if (
                $session &&
                $session->facetoface == $this->facetofaceid &&
                isset($userid) &&
                !$uploadservice->validate_existing_booking_upload(
                    $row,
                    $useridentifier,
                    $session,
                    $entry->status,
                    (int)$userid,
                    $errors,
                    $signupexistencecache,
                    $activesignupcache
                )
            ) {
                continue;
            }

            if ($session) {
                $uploadservice->validate_session_status_rules(
                    $row,
                    $entry->session,
                    $entry->status,
                    $session,
                    $timenow,
                    $errors
                );

                if (
                    isset($userid) &&
                    $entry->status !== 'cancelled' &&
                    $this->facetoface->signuptype != MOD_FACETOFACE_SIGNUP_MULTIPLE &&
                    ($currusersessions = facetoface_get_user_submissions($this->facetofaceid, $userid))
                ) {
                    foreach ($currusersessions as $currusersession) {
                        if ($currusersession->sessionid == $session->id) {
                            continue;
                        }

                        $errors[] = [
                            $row,
                            new lang_string(
                                'error:addalreadysignedupattendee',
                                'mod_facetoface',
                                $useridentifier
                            ),
                        ];
                        break;
                    }
                }
            }

            if (isset($userid) && !is_enrolled($this->coursecontext, $userid)) {
                $isenrolled = facetoface_enrol_user($this->coursecontext, $this->course->id, $userid);
                if (!$isenrolled) {
                    $errors[] = [
                        $row,
                        get_string('error:enrolmentfailed', 'mod_facetoface', $useridentifier),
                    ];
                }
            }

            if (!in_array(
                $this->transform_notification_type($entry->notificationtype),
                [MDL_F2F_BOTH, MDL_F2F_TEXT, MDL_F2F_ICAL],
                true
            )) {
                $errors[] = [
                    $row,
                    new lang_string(
                        'error:invalidnotificationtypespecified',
                        'mod_facetoface',
                        $entry->notificationtype
                    ),
                ];
            }

            if (!$uploadservice->is_processable_status($entry->status)) {
                $errors[] = [
                    $row,
                    new lang_string('error:invalidstatusspecified', 'mod_facetoface', $entry->status),
                ];
            }

            if (
                $session &&
                isset($userid) &&
                count($errors) === $errorcountbefore &&
                $uploadservice->is_processable_status($entry->status)
            ) {
                $uploadservice->cache_validation_row(
                    $row,
                    $useridentifier,
                    $entry->status,
                    $session,
                    (int)$userid,
                    $validationrows,
                    $activesignupcache
                );
            }
        }

        if ($this->facetoface->signuptype != MOD_FACETOFACE_SIGNUP_MULTIPLE) {
            $this->validate_multiple_user_sessions($validationrows, $errors);
        }

        $uploadservice->validate_unique_rows_and_capacity($validationrows, $errors);

        return $errors;
    }

    /**
     * Process all rows without validation errors.
     *
     * @param list<array{0:int|string, 1:string|lang_string}> $errors Validation errors returned by validate(), or none.
     * @return bool True after all processable rows have been handled.
     * @throws moodle_exception When attendance cannot be applied.
     * @throws Exception When cancellation fails.
     */
    public function process(array $errors = []): bool {
        $this->ensure_activity_loaded();

        $skip = booking_upload_service::extract_rows_to_skip($errors);
        $uploadservice = $this->get_upload_service();

        foreach ($this->get_iterator() as $index => $entry) {
            $row = $index + 1;
            if (isset($skip[$row])) {
                continue;
            }

            $entry->status = $entry->status ?? '';
            $entry->discountcode = $entry->discountcode ?? '';
            $entry->notificationtype = $entry->notificationtype ?? '';
            $user = current($this->match_entry_user($entry, '*'));
            $session = facetoface_get_session($entry->session);

            $uploadservice->process_row(
                $entry,
                $session,
                $this->facetoface,
                $this->course,
                $this->coursecontext,
                $user,
                $row,
                $this->transform_notification_type($entry->notificationtype)
            );
        }

        return true;
    }

    /**
     * Suppress confirmation emails for subsequent processing.
     *
     * @return void
     */
    public function suppress_email(): void {
        $this->suppressemail = true;
    }

    /**
     * Configure case-insensitive username matching.
     *
     * @param bool $value Whether username matching should ignore case.
     * @return void
     */
    public function set_case_insensitive(bool $value): void {
        $this->caseinsensitive = $value;
    }

    /**
     * Load the activity and course context on first use.
     *
     * @return void
     * @throws moodle_exception When the configured activity or course does not exist.
     */
    private function ensure_activity_loaded(): void {
        global $DB;

        if (isset($this->facetoface)) {
            return;
        }

        $facetoface = $DB->get_record('facetoface', ['id' => $this->facetofaceid]);
        if (!$facetoface) {
            throw new moodle_exception('error:incorrectfacetofaceid', 'facetoface');
        }

        $course = $DB->get_record('course', ['id' => $facetoface->course]);
        if (!$course) {
            throw new moodle_exception('error:coursemisconfigured', 'facetoface');
        }

        $this->facetoface = $facetoface;
        $this->course = $course;
        $this->coursecontext = context_course::instance($course->id);
    }

    /**
     * Build the shared workflow service for the current manager options.
     *
     * @return booking_upload_service Shared upload workflow.
     */
    private function get_upload_service(): booking_upload_service {
        return new booking_upload_service($this->usefile, $this->suppressemail, true);
    }

    /**
     * Return a fresh iterator for the configured records.
     *
     * @return Generator CSV row iterator.
     * @throws moodle_exception When a file row has the wrong field count.
     */
    private function get_iterator(): Generator {
        if (!$this->usefile) {
            foreach ($this->records as $record) {
                yield $record;
            }

            return;
        }

        if ($this->file === null) {
            throw new moodle_exception('error:cannotloadfile', 'mod_facetoface');
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
            $hasdiscount = in_array('discountcode', $normalisedheaders, true);
        }

        $discountposition = array_search('discountcode', $headers, true);

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
     * Match a user from either the current username row shape or the legacy email row shape.
     *
     * @param stdClass $entry Upload row.
     * @param string $fields Fields to return.
     * @return array<int, stdClass> Matching users keyed by user ID.
     */
    private function match_entry_user(stdClass $entry, string $fields): array {
        global $DB;

        $field = property_exists($entry, 'username') ? 'username' : 'email';
        $identifier = $this->get_user_identifier($entry);
        $equals = $DB->sql_equal($field, ':identifier', !$this->caseinsensitive);

        return $DB->get_records_select('user', $equals, ['identifier' => $identifier], 'id', $fields);
    }

    /**
     * Return the learner identifier displayed for an upload row.
     *
     * @param stdClass $entry Upload row.
     * @return string Username, or the legacy email value when username is absent.
     */
    private function get_user_identifier(stdClass $entry): string {
        if (property_exists($entry, 'username')) {
            return (string)($entry->username ?? '');
        }

        return (string)($entry->email ?? '');
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
     * Validate that one user is not uploaded to multiple sessions in single-signup mode.
     *
     * @param array<int, array{session:stdClass, userid:int, username:string, status:string, hasactivesignup:bool}>
     *     $validationrows Resolved, processable CSV rows keyed by row number.
     * @param list<array{0:int|string, 1:string|lang_string}> $errors Validation errors, updated in place.
     * @return void
     */
    private function validate_multiple_user_sessions(array $validationrows, array &$errors): void {
        $skip = booking_upload_service::extract_rows_to_skip($errors);
        $usersessions = [];

        foreach ($validationrows as $row => $details) {
            if ($details['status'] === 'cancelled' || isset($skip[$row])) {
                continue;
            }

            $userid = $details['userid'];
            if (!isset($usersessions[$userid])) {
                $usersessions[$userid] = [
                    'username' => $details['username'],
                    'rows' => [],
                    'sessions' => [],
                ];
            }

            $usersessions[$userid]['rows'][] = $row;
            if (!in_array($details['session']->id, $usersessions[$userid]['sessions'], true)) {
                $usersessions[$userid]['sessions'][] = $details['session']->id;
            }
        }

        $doublebookedusers = array_filter(
            $usersessions,
            static function(array $usersession): bool {
                return count($usersession['sessions']) > 1;
            }
        );

        foreach ($doublebookedusers as $details) {
            $errors[] = [
                implode(', ', $details['rows']),
                new lang_string('error:multipleusersessions', 'mod_facetoface', $details['username']),
            ];
        }
    }
}
