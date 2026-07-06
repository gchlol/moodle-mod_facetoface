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
use file_storage;
use lang_string;
use moodle_exception;

/**
 * Booking manager
 *
 * @package    mod_facetoface
 * @author     Kevin Pham <kevinpham@catalyst-au.net>
 * @copyright  Catalyst IT, 2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_manager {

    /** @var stored_file the file to process as a stored_file object */
    private $file;

    /** @var int The facetoface module ID. */
    private $f;

    /** @var int The course id. */
    private $course;

    /** @var context_course The course context. */
    private $coursecontext;

    /** @var int The course id. */
    private $facetoface;

    /** @var array collection of records (if loaded from memory), in an array. */
    private $records;

    /** @var bool Whether or not the bookings are loaded from a file. */
    private $usefile = true;

    /** @var bool When true, confirmation emails are not sent. */
    private $suppressemail = false;

    /** @var bool Will ignore case when matching users */
    private $caseinsensitive = false;

    /** @var string[] GCHLOL: Statuses that record attendance rather than create a plain booking. */
    private const ATTENDANCE_STATUSES = ['no_show', 'partially_attended', 'fully_attended'];

    /**
     * Constructor for the booking manager.
     * @param int $f The facetoface module ID.
     * @param array $records The records to process.
     */
    public function __construct($f, $records = []) {
        global $DB;

        if (!$facetoface = $DB->get_record('facetoface', ['id' => $f])) {
            throw new moodle_exception('error:incorrectfacetofaceid', 'facetoface');
        }
        if (!$course = $DB->get_record('course', ['id' => $facetoface->course])) {
            throw new moodle_exception('error:coursemisconfigured', 'facetoface');
        }
        $this->f = $f;
        $this->facetoface = $facetoface;
        $this->course = $course;
        $this->coursecontext = context_course::instance($course->id);
        $this->records = $records;
    }

    /**
     * Returns file from file system. File must exist.
     * @param int $fileitemid Item id of file stored in the current $USER's draft file area
     */
    public function load_from_file(int $fileitemid) {
        global $USER;
        $this->usefile = true;

        $fs = new file_storage();
        $files = $fs->get_area_files(context_user::instance($USER->id)->id, 'user', 'draft', $fileitemid, 'itemid', false);

        if (count($files) != 1) {
            throw new moodle_exception('error:cannotloadfile', 'mod_facetoface');
        }

        $this->file = current($files);
    }

    /**
     * Load in the records to process from an array
     * @param array $records
     */
    public function load_from_array(array $records) {
        $this->usefile = false;
        $this->records = $records;

        return $this;
    }

    /**
     * Get the headers for the records.
     * @return array
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
     * Get an iterator for the records.
     * @return Generator
     */
    private function get_iterator(): \Generator {
        if (!$this->usefile) {
            foreach ($this->records as $record) {
                yield $record;
            }
            return;
        }

        $handle = $this->file->get_content_file_handle();
        $maxlinelength = 1000;
        $delimiter = ',';
        $rownumber = 1; // First row is headers.
        $headers = self::get_headers();
        $numheaders = count($headers);
        //fgets($handle); // Move pointer past first line (headers).
        // Read the CSV header and detect whether a "Discount Code" column exists (case-insensitive).
        $fileheaders = fgetcsv($handle, $maxlinelength, $delimiter);
        $hasdiscount = false;
        if ($fileheaders !== false) {
            $norm = array_map(function($h) { return strtolower(trim($h)); }, $fileheaders);
            $hasdiscount = in_array('discountcode', $norm, true);
        }

        // Where "Discount Code" is expected in our canonical header list.
        $discountpos = array_search('discountcode', $headers, true);

        try {
            while (($data = fgetcsv($handle, $maxlinelength, $delimiter)) !== false) {
                $rownumber++;

                // If the uploaded CSV omitted "Discount Code", insert an empty string so counts still match.
                if ($hasdiscount === false && $discountpos !== false) {
                    array_splice($data, $discountpos, 0, '');
                }

                $numfields = count($data);
                if ($numfields !== $numheaders) {
                    throw new moodle_exception('error:bookingsuploadfileheaderfieldmismatch', 'mod_facetoface');
                }
                $record = array_combine($headers, $data);
                yield (object) $record;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Validate the records provided to ensure they can be processed without errors.
     *
     * As there are multiple dependant data points (users, sessions, capacity)
     * that are checked. They are all in this method.
     *
     * @param int $timenow The current time to use for validation.
     * @return array An array of errors.
     */
    public function validate($timenow = null): array {
        $errors = [];
        $sessioncapacitycache = [];
        $timenow ??= time();

        $usersessions = [];

        // Break into rows and validate the multiple interdependant fields together.
        foreach ($this->get_iterator() as $index => $entry) {
            $row = $index + 1;

            // Set defaults for fields with no value.
            $entry->status = $entry->status ?? '';
            $entry->notificationtype = $entry->notificationtype ?? '';
            $entry->discountcode = $entry->discountcode ?? '';

            // GCHLOL: Reset the user match so a row without a match cannot reuse the previous row's user.
            $userid = null;

            // Validate and get user.
            $userids = $this->match_users_username($entry->username, 'id');

            // Multiple matched, ambiguous which is the real one.
            if (count($userids) > 1) {
                $errors[] = [$row, new lang_string('error:multipleusersmatched', 'mod_facetoface', $entry->username)];
            }

            // None matched at all - missing.
            if (empty($userids)) {
                $errors[] = [$row, new lang_string('error:userdoesnotexist', 'mod_facetoface', $entry->username)];
            } else {
                $userid = current($userids)->id;
            }

            // Check session exists.
            $session = facetoface_get_session($entry->session);
            if (!$session) {
                $errors[] = [$row, new lang_string('error:sessiondoesnotexist', 'mod_facetoface', $entry->session)];
            }

            // Check for session overbooking, that is, if it would go over session capacity.
            if ($session) {
                // If the session supplied does not link to the face-to-face module expected, then it's invalid.
                if ($session->facetoface != $this->f) {
                    $errors[] = [
                        $row,
                        new lang_string('error:tryingtoupdatesessionfromanothermodule', 'mod_facetoface', (object) [
                            'session' => $entry->session,
                            'f' => $this->f,
                        ]),
                    ];
                }
                // Don't allow user to cancel a session that has already occurred.
                if ($entry->status === 'cancelled' && facetoface_has_session_started($session, $timenow)) {
                    $errors[] = [$row, new lang_string('error:sessionalreadystarted', 'mod_facetoface', $entry->session)];
                }

                // GCHLOL: Bookings ('' / 'booked') into sessions that have already started or finished are
                // allowed, so past sessions can be uploaded. Waitlisting only makes sense before the start.
                if ($entry->status === 'waitlisted' && facetoface_has_session_started($session, $timenow)) {
                    $errors[] = [
                        $row,
                        new lang_string('error:cannotwaitliststartedsession', 'mod_facetoface', $entry->session),
                    ];
                }

                // GCHLOL: Attendance can only be recorded once the session has started.
                if ($session->datetimeknown
                    && in_array($entry->status, self::ATTENDANCE_STATUSES, true)
                    && !facetoface_has_session_started($session, $timenow)) {
                    $errors[] = [
                        $row,
                        new lang_string('error:attendancesessionnotstarted', 'mod_facetoface', $entry->session),
                    ];
                }

                // Set the session capacity if it hasn't been set yet.
                if ($session->allowoverbook == 0 && !isset($sessioncapacitycache[$session->id])) {
                    // Total minus current capacity.
                    $sessioncapacitycache[$session->id]['capacity'] =
                        $session->capacity - facetoface_get_num_attendees($session->id, MDL_F2F_STATUS_APPROVED);
                }

                // If the status is not cancelled, then it's considered a booking and it should deduct from the session.
                // GCHLOL: Users who already hold an active signup are already counted in the attendee total,
                // so their rows (e.g. attendance updates for past sessions) must not consume capacity again.
                $hasactivesignup = isset($userid) && $this->get_active_signup_id($session->id, $userid) !== null;
                if ($session->allowoverbook == 0 && $entry->status !== 'cancelled' && !$hasactivesignup) {
                    $sessioncapacitycache[$session->id]['capacity']--;
                    $sessioncapacitycache[$session->id]['rows'][] = $row;
                }

                // Don't allow users to signup to another session if the signup type is not multiple.
                if (isset($userid) && $entry->status !== 'cancelled' && $this->facetoface->signuptype != MOD_FACETOFACE_SIGNUP_MULTIPLE) {
                    if ($currusersessions = facetoface_get_user_submissions($this->f, $userid)) {
                        foreach ($currusersessions as $currusersession) {
                            if ($currusersession->sessionid != $session->id) {
                                $errors[] = [
                                    $row,
                                    // GCHLOL: Records are keyed by username; fall back to it when no email is given.
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
                }
            }

            // Check user enrolment into the course.
            if (isset($userid) && !is_enrolled($this->coursecontext, $userid)) {
                //$errors[] = [$row, new lang_string('error:userisnotenrolledintocourse', 'mod_facetoface', $entry->username)];
                $isenrolled = facetoface_enrol_user($this->coursecontext, $this->course->id, $userid);
                if (!$isenrolled) {
                    $errors[] = [
                        $row,
                        get_string('error:enrolmentfailed', 'mod_facetoface', $entry->username)
                    ];
                }
            }

            // Check to ensure valid notification types are used if set.
            if (isset($entry->notificationtype)
                && !in_array(
                    $this->transform_notification_type($entry->notificationtype),
                    [MDL_F2F_BOTH, MDL_F2F_TEXT, MDL_F2F_ICAL]
                )) {
                $errors[] = [
                    $row,
                    new lang_string('error:invalidnotificationtypespecified', 'mod_facetoface', $entry->notificationtype),
                ];
            }

            // Check to ensure a valid status is set.
            if (isset($entry->status) && !in_array(
                $entry->status,
                array_merge(facetoface_statuses(), [
                    '',          // Defaults to booked.
                    'cancelled', // Alternative to 'user_cancelled'.
                ])
            )) {
                $errors[] = [
                    $row,
                    new lang_string('error:invalidstatusspecified', 'mod_facetoface', $entry->status),
                ];
            }

            // If the user exists and this isn't a cancellation, we need to store the distinct session for checking at the end.
            if (isset($userid) && $entry->status !== 'cancelled') {
                // Set the user sessions if it hasn't been set yet.
                if (!isset($usersessions[$userid])) {
                    $usersessions[$userid] = [
                        // GCHLOL: Records are keyed by username; fall back to it when no email is given.
                        'email' => $entry->email ?? $entry->username,
                        'rows' => [],
                        'sessions' => [],
                    ];
                }

                $usersessions[$userid]['rows'][] = $row;

                if ($session && !in_array($session->id, $usersessions[$userid]['sessions'])) {
                    $usersessions[$userid]['sessions'][] = $session->id;
                }
            }
        }

        // If the signup type is not set to multiple, we need to create errors for users being added to multiple sessions.
        if ($this->facetoface->signuptype != MOD_FACETOFACE_SIGNUP_MULTIPLE) {
            // Get all users being added to more than 1 session.
            $doublebookedusers = array_filter($usersessions, function($us) {
                return count($us['sessions']) > 1;
            });
            // Create errors for the user rows.
            foreach ($doublebookedusers as $details) {
                $errors[] = [
                    implode(', ', $details['rows']),
                    new lang_string('error:multipleusersessions', 'mod_facetoface', $details['email']),
                ];
            }
        }

        // For all sessions that went over capacity, report it.
        $overcapacitysessions = array_filter($sessioncapacitycache, function ($s) {
            return $s['capacity'] < 0;
        });
        if (!empty($overcapacitysessions)) {
            foreach ($overcapacitysessions as $sessionid => $details) {
                $errors[] = [
                    implode(', ', $details['rows']),
                    new lang_string(
                        'error:sessionoverbooked',
                        'mod_facetoface',
                        (object) ['session' => $sessionid, 'amount' => -$details['capacity']]
                    ),
                ];
            }
        }

        return $errors;
    }

    /**
     * Match users for a given email, taking into account case sensitivity.
     * @param string $email
     * @param string $fields fields to return
     * @return array of users, with specified fields
     */
    private function match_users(string $email, string $fields): array {
        global $DB;
        $equals = $DB->sql_equal('email', ':email', !$this->caseinsensitive);
        return $DB->get_records_select('user', $equals, ['email' => $email], 'id', $fields);
    }

    /**
     * Match users for a given username, taking into account case sensitivity.
     * @param string $username
     * @param string $fields fields to return
     * @return array of users, with specified fields
     */
    private function match_users_username(string $username, string $fields): array {
        global $DB;
        $equals = $DB->sql_equal('username', ':username', !$this->caseinsensitive);
        return $DB->get_records_select('user', $equals, ['username' => $username], 'id', $fields);
    }

    /**
     * GCHLOL: Get the user's active signup ID for a session, if any.
     *
     * A signup is active when its current status is approved or higher (approved, waitlisted,
     * booked or an attendance status). Cancelled and declined signups are not active.
     *
     * @param int $sessionid The session ID.
     * @param int $userid The user ID.
     * @return int|null The signup ID, or null if the user holds no active signup.
     */
    private function get_active_signup_id(int $sessionid, int $userid): ?int {
        global $DB;

        $sql = "SELECT su.id
                  FROM {facetoface_signups} su
                  JOIN {facetoface_signups_status} ss ON ss.signupid = su.id
                 WHERE su.sessionid = ?
                   AND su.userid = ?
                   AND ss.superceded = 0
                   AND ss.statuscode >= ?";
        $signupid = $DB->get_field_sql($sql, [$sessionid, $userid, MDL_F2F_STATUS_APPROVED]);

        return $signupid ? (int) $signupid : null;
    }

    /**
     * GCHLOL: Get the user's signup ID for a session regardless of its current status.
     *
     * @param int $sessionid The session ID.
     * @param int $userid The user ID.
     * @return int The signup ID.
     */
    private function get_signup_id(int $sessionid, int $userid): int {
        global $DB;

        return (int) $DB->get_field(
            'facetoface_signups',
            'id',
            ['sessionid' => $sessionid, 'userid' => $userid],
            MUST_EXIST
        );
    }

    /**
     * Transform notification type to internal representation.
     *
     * @param string $type Notification type.
     * @return int|null
     */
    private function transform_notification_type($type) {
        $mapping = [
            'email' => MDL_F2F_TEXT,
            'ical' => MDL_F2F_ICAL,
            'icalendar' => MDL_F2F_ICAL,
            'both' => MDL_F2F_BOTH,
            '' => MDL_F2F_ICAL, // Defaults to iCalendar only if nothing is specified.
        ];

        return $mapping[strtolower($type)] ?? null;
    }

    /**
     * Process the bookings in the file.
     *
     * @return bool
     * @throws moodle_exception
     */
    public function process(array $errors) {
        // Build a set of rows to skip from the error list.
        $skip = self::extract_rows_to_skip($errors);

        // Records may contain errors; we will skip them.
        $index = -1;
        foreach ($this->get_iterator() as $entry) {
            $index++;
            $row = $index + 1; // 1-based row index for comparison.

            if (isset($skip[$row])) {
                continue; // Skip rows with validation errors.
            }

            $user = current($this->match_users_username($entry->username, '*'));
            $session = facetoface_get_session($entry->session);

            // Get signup type.
            if ($entry->status === 'cancelled') {
                // Handle cancellation.
                if (facetoface_user_cancel($session, $user->id, true, $cancelerr)) {
                    // Notify the user of the cancellation if the session hasn't started yet.
                    $timenow = time();
                    if (!facetoface_has_session_started($session, $timenow) && !$this->suppressemail) {
                        facetoface_send_cancellation_notice($this->facetoface, $session, $user->id);
                    }
                } else {
                    throw new \Exception($cancelerr);
                }
            } else {
                // Map status to status code.
                $statuscode = array_search($entry->status, facetoface_statuses()) ?: MDL_F2F_STATUS_BOOKED;

                // Handle signups.
                if (in_array($statuscode, [MDL_F2F_STATUS_BOOKED, MDL_F2F_STATUS_WAITLISTED])) {
                    if ($statuscode === MDL_F2F_STATUS_BOOKED && !$session->datetimeknown) {
                        // If booked, ensures the status is waitlisted instead, if the datetime is unknown.
                        $statuscode = MDL_F2F_STATUS_WAITLISTED;
                    }

                    // Edge case: Re-enrol the user if they are unenrolled after validation.
                    // Otherwise, it's idempotent for enrolled users.
                    $coursecontext = context_course::instance($this->course->id);
                    facetoface_enrol_user($coursecontext, $this->course->id, $user->id);

                    facetoface_user_signup(
                        $session,
                        $this->facetoface,
                        $this->course,
                        $entry->discountcode ?? '',
                        $this->transform_notification_type($entry->notificationtype) ?? -1,
                        $statuscode,
                        $user->id,
                        !$this->suppressemail,
                    );

                    // GCHLOL: Log a successful CSV bulk upload booking for this session user.
                    \mod_facetoface\event\bulk_booking_created::trigger_from_bulk_upload_if_needed(
                        (bool) $this->usefile,
                        $this->facetoface,
                        $session,
                        (int) $user->id
                    );
                    // GCHLOL ends.

                    continue;
                }

                // Handle attendance.
                if (in_array($statuscode, [
                    MDL_F2F_STATUS_NO_SHOW,
                    MDL_F2F_STATUS_PARTIALLY_ATTENDED,
                    MDL_F2F_STATUS_FULLY_ATTENDED,
                ])) {
                    // GCHLOL: Users without an active signup (never booked, or previously cancelled) are
                    // booked in first, so attendance can be uploaded for sessions that have already run.
                    // Signup emails are suppressed by facetoface_user_signup() once a session has started.
                    $signupid = $this->get_active_signup_id($session->id, $user->id);
                    if ($signupid === null) {
                        facetoface_enrol_user($this->coursecontext, $this->course->id, $user->id);

                        facetoface_user_signup(
                            $session,
                            $this->facetoface,
                            $this->course,
                            $entry->discountcode ?? '',
                            $this->transform_notification_type($entry->notificationtype) ?? -1,
                            MDL_F2F_STATUS_BOOKED,
                            $user->id,
                            !$this->suppressemail,
                        );

                        // GCHLOL: Log a successful CSV bulk upload booking for this session user.
                        \mod_facetoface\event\bulk_booking_created::trigger_from_bulk_upload_if_needed(
                            (bool) $this->usefile,
                            $this->facetoface,
                            $session,
                            (int) $user->id
                        );

                        $signupid = $this->get_signup_id($session->id, $user->id);
                    }

                    $data = (object) [
                        's' => $session->id,
                        'submissionid_' . $signupid => $statuscode,
                    ];
                    facetoface_take_attendance($data);

                    continue;
                }
            }
        }

        return true;
    }

    /**
     * Stops confirmation emails from being sent
     */
    public function suppress_email() {
        $this->suppressemail = true;
    }

    /**
     * Sets case insensitive match value
     * @param bool $value
     */
    public function set_case_insensitive(bool $value) {
        $this->caseinsensitive = $value;
    }

    /**
     * Convert the error array into a set of row numbers to skip.
     *
     * @param array<int, mixed> $errors Validation errors from validate()
     * @return array<int, bool> Keys are row numbers to skip (1-based)
     * @throws moodle_exception When the error format is invalid
     */
    private static function extract_rows_to_skip(array $errors): array {
        $skip = [];

        foreach ($errors as $error) {
            if (!is_array($error)) {
                throw new moodle_exception('error:errormustbeanarray', 'mod_facetoface', '', $error);
            }

            // First element must be an integer row number (1-based from validate()), or a
            // GCHLOL: comma-separated list of row numbers for aggregated errors such as overbookings.
            $rows = is_string($error[0]) ? explode(',', $error[0]) : [$error[0]];
            foreach ($rows as $row) {
                $row = trim((string) $row);
                if (!is_numeric($row)) {
                    throw new moodle_exception(
                        'error:invalidrownumber',
                        'mod_facetoface',
                        '',
                        (object)['value' => $error[0], 'type' => gettype($error[0])]
                    );
                }

                $skip[(int)$row] = true;
            }
        }

        return $skip;
    }
}
