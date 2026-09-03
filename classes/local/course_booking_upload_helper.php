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

use Closure;
use context_course;
use Exception;
use Generator;
use lang_string;
use moodle_exception;
use stdClass;

/**
 * Adapts the course-level booking uploader to the shared upload workflow.
 *
 * @package    mod_facetoface
 * @copyright  2026 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_booking_upload_helper {

    /** @var stdClass Face-to-face activity record. */
    protected stdClass $facetoface;

    /** @var stdClass Course record. */
    protected stdClass $course;

    /** @var context_course Course context. */
    protected context_course $coursecontext;

    /** @var int Face-to-face activity ID. */
    protected int $facetofaceid;

    /** @var Closure Callback which returns a fresh row iterator. */
    protected Closure $recorditerator;

    /** @var Closure Callback which matches a username to user records. */
    protected Closure $usermatcher;

    /** @var Closure Callback which maps notification strings to MDL_F2F_* codes. */
    protected Closure $notificationtypetransformer;

    /** @var booking_upload_service Shared upload workflow. */
    protected booking_upload_service $uploadservice;

    /**
     * Constructor.
     *
     * @param stdClass $facetoface Face-to-face activity record.
     * @param stdClass $course Course record.
     * @param context_course $coursecontext Course context.
     * @param int $facetofaceid Face-to-face activity ID.
     * @param bool $usefile Whether the manager is processing a real file upload.
     * @param bool $suppressemail Whether confirmation emails are suppressed.
     * @param Closure $recorditerator Callback which returns a fresh row iterator.
     * @param Closure $usermatcher Callback which matches a username to user records.
     * @param Closure $notificationtypetransformer Callback which maps notification strings to MDL_F2F_* codes.
     */
    public function __construct(
        stdClass $facetoface,
        stdClass $course,
        context_course $coursecontext,
        int $facetofaceid,
        bool $usefile,
        bool $suppressemail,
        Closure $recorditerator,
        Closure $usermatcher,
        Closure $notificationtypetransformer
    ) {
        $this->facetoface = $facetoface;
        $this->course = $course;
        $this->coursecontext = $coursecontext;
        $this->facetofaceid = $facetofaceid;
        $this->recorditerator = $recorditerator;
        $this->usermatcher = $usermatcher;
        $this->notificationtypetransformer = $notificationtypetransformer;
        $this->uploadservice = new booking_upload_service($usefile, $suppressemail, true);
    }

    /**
     * Validate the records provided to ensure they can be processed without errors.
     *
     * @param int|null $timenow Current time to use for validation.
     * @return list<array{0:int|string, 1:string|lang_string}> Validation errors keyed by CSV row.
     */
    public function validate(?int $timenow = null): array {
        $errors = [];
        $validationrows = [];
        $activesignupcache = [];
        $signupexistencecache = [];
        $timenow ??= time();

        foreach ($this->get_iterator() as $index => $entry) {
            $row = $index + 1;
            $errorcountbefore = count($errors);
            $entry->status = $entry->status ?? '';
            $entry->notificationtype = $entry->notificationtype ?? '';
            $entry->discountcode = $entry->discountcode ?? '';

            $userid = null;
            $userids = $this->match_users_username($entry->username, 'id');
            if (count($userids) > 1) {
                $errors[] = [$row, new lang_string('error:multipleusersmatched', 'mod_facetoface', $entry->username)];
            }

            if (empty($userids)) {
                $errors[] = [$row, new lang_string('error:userdoesnotexist', 'mod_facetoface', $entry->username)];
            }

            if (count($userids) === 1) {
                $userid = current($userids)->id;
            }

            $session = facetoface_get_session($entry->session);
            if (!$session) {
                $errors[] = [$row, new lang_string('error:sessiondoesnotexist', 'mod_facetoface', $entry->session)];
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
                !$this->uploadservice->validate_existing_booking_upload(
                    $row,
                    $entry->username,
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
                $this->uploadservice->validate_session_status_rules(
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
                                $entry->email ?? $entry->username
                            ),
                        ];
                        break;
                    }
                }
            }

            if (isset($userid) && !is_enrolled($this->coursecontext, $userid)) {
                $isenrolled = facetoface_enrol_user($this->coursecontext, $this->course->id, $userid);
                if (!$isenrolled) {
                    $errors[] = [$row, get_string('error:enrolmentfailed', 'mod_facetoface', $entry->username)];
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

            if (!$this->uploadservice->is_processable_status($entry->status)) {
                $errors[] = [
                    $row,
                    new lang_string('error:invalidstatusspecified', 'mod_facetoface', $entry->status),
                ];
            }

            if (
                $session &&
                isset($userid) &&
                count($errors) === $errorcountbefore &&
                $this->uploadservice->is_processable_status($entry->status)
            ) {
                $this->uploadservice->cache_validation_row(
                    $row,
                    $entry->username,
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

        $this->uploadservice->validate_unique_rows_and_capacity($validationrows, $errors);

        return $errors;
    }

    /**
     * Process all rows without validation errors.
     *
     * @param list<array{0:int|string, 1:string|lang_string}> $errors Validation errors returned by validate().
     * @return bool True after all processable rows have been handled.
     * @throws moodle_exception When attendance cannot be applied.
     * @throws Exception When cancellation fails.
     */
    public function process(array $errors): bool {
        $skip = booking_upload_service::extract_rows_to_skip($errors);

        foreach ($this->get_iterator() as $index => $entry) {
            $row = $index + 1;
            if (isset($skip[$row])) {
                continue;
            }

            $entry->status = $entry->status ?? '';
            $entry->discountcode = $entry->discountcode ?? '';
            $entry->notificationtype = $entry->notificationtype ?? '';
            $user = current($this->match_users_username($entry->username, '*'));
            $session = facetoface_get_session($entry->session);

            $this->uploadservice->process_row(
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
     * Return a fresh iterator for the configured records.
     *
     * @return Generator CSV row iterator.
     */
    private function get_iterator(): Generator {
        $recorditerator = $this->recorditerator;

        return $recorditerator();
    }

    /**
     * Match users by username.
     *
     * @param string $username Username to search.
     * @param string $fields Fields to return.
     * @return array<int, stdClass> Matching users keyed by user ID.
     */
    private function match_users_username(string $username, string $fields): array {
        $usermatcher = $this->usermatcher;

        return $usermatcher($username, $fields);
    }

    /**
     * Map a notification string to its internal code.
     *
     * @param string $type Notification type.
     * @return int|null Notification code, or null when invalid.
     */
    private function transform_notification_type(string $type): ?int {
        $notificationtypetransformer = $this->notificationtypetransformer;

        return $notificationtypetransformer($type);
    }

    /**
     * Validate that a single-signup activity does not contain one user in multiple uploaded sessions.
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
