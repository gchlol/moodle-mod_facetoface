<?php
/**
 * Manages bulk session creation for Face-to-Face module sitewide.
 * Handles CSV parsing, validation, and session creation.
 * Supports file uploads.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Jonas Sajonas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_facetoface;

use DateTime;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Bulk session manager for site-wide context (site-level bulk upload).
 *
 * @package   mod_facetoface
 */
class bulk_session_manager_sitelevel extends bulk_session_manager_parent {
    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(0);
    }

    /**
     * Validates the loaded CSV records for required fields, types, etc.
     *
     * @return array A list of validation errors.
     */
    public function validate(): array {
        // If using file, load records from iterator.
        if ($this->usefile) {
            $this->records = iterator_to_array($this->get_iterator());
        }

        foreach ($this->records as $index => $record) {
            foreach ($record as $key => $value) {
                $record[$key] = trim($value);
            }

            $shortname = $record['Course Shortname'] ?? '';
            $f2fname   = $record['Face-to-Face Activity Name'] ?? '';

            if (empty($shortname)) {
                $this->errors[] = [
                    $index,
                    get_string('error:missingcourseshortname', 'facetoface')
                ];

                continue;
            }

            if (empty($f2fname)) {
                $this->errors[] = [
                    $index,
                    get_string('error:missingf2fname', 'facetoface')
                ];

                continue;
            }

            $matched = $this->match_records($shortname, $f2fname);
            $course = $matched['course'];
            $f2frecord = $matched['facetoface'];

            if (!$course) {
                $this->errors[] = [
                    $index,
                    get_string('error:coursenotfound', 'facetoface', $shortname)
                ];

                continue;
            }

            if (!$f2frecord) {
                $this->errors[] = [
                    $index,
                    get_string(
                        'error:f2fnotfound',
                        'facetoface',
                        (object)[
                            'shortname' => $shortname,
                            'f2fname'   => $f2fname
                        ]
                    )
                ];

                continue;
            }

            // Start Date + Time.
            if (
                empty($record['Start Date']) ||
                empty($record['Start Time'])
            ) {
                $this->errors[] = [
                    $index,
                    get_string('error:missingstarttime', 'facetoface')
                ];

                continue;
            }

            $startdt = DateTime::createFromFormat('d/m/Y H:i', $record['Start Date'] . ' ' . $record['Start Time']);
            if (!$startdt) {
                $params = (object)[
                    'date' => $record['Start Date'],
                    'time' => $record['Start Time'],
                ];

                $this->errors[] = [
                    $index,
                    get_string('error:invalidstarttime', 'facetoface', $params),
                ];

                continue;
            }

            // If valid, store the combined date/time back into $record.
            $record['Start Date and Time'] = $startdt->format('Y-m-d H:i');

            // Finish Date + Time.
            if (
                empty($record['Finish Date']) ||
                empty($record['Finish Time'])
            ) {
                $this->errors[] = [
                    $index,
                    get_string('error:missingfinishtime', 'facetoface')
                ];

                continue;
            }

            $finishdt = DateTime::createFromFormat('d/m/Y H:i', $record['Finish Date'].' '.$record['Finish Time']);
            if (!$finishdt) {
                $params = (object)[
                    'date' => $record['Finish Date'],
                    'time' => $record['Finish Time'],
                ];

                $this->errors[] = [
                    $index,
                    get_string('error:invalidfinishtime', 'facetoface', $params),
                ];

                continue;
            }

            $record['Finish Date and Time'] = $finishdt->format('Y-m-d H:i');

            // Ensure Start < Finish.
            $starttime = strtotime($record['Start Date and Time']);
            $finishtime = strtotime($record['Finish Date and Time']);

            if (
                $starttime &&
                $finishtime &&
                $starttime >= $finishtime
            ) {
                $this->errors[] = [
                    $index,
                    get_string('error:starttimeafterfinish', 'facetoface')
                ];

                continue;
            }

            // Capacity (required).
            if (
                !isset($record['Capacity']) ||
                !is_numeric($record['Capacity']) ||
                (int)$record['Capacity'] <= 0
            ) {
                $this->errors[] = [
                    $index,
                    get_string('error:invalidcapacity', 'facetoface')
                ];

                continue;
            }

            // Duration (required).
            if (
                !isset($record['Duration']) ||
                !is_numeric($record['Duration']) ||
                (int)$record['Duration'] <= 0
            ) {
                $this->errors[] = [
                    $index,
                    get_string('error:invalidduration', 'facetoface')
                ];

                continue;
            }

            // Normal Cost (optional).
            if (
                !empty($record['Normal Cost']) &&
                !is_numeric($record['Normal Cost'])
            ) {
                $this->errors[] = [
                    $index,
                    get_string('error:invalidnormalcost', 'facetoface')
                ];

                continue;
            }

            // Discount Cost (optional).
            if (
                !empty($record['Discount Cost']) &&
                !is_numeric($record['Discount Cost'])
            ) {
                $this->errors[] = [
                    $index,
                    get_string('error:invaliddiscountcost', 'facetoface')
                ];

                continue;
            }

            // Allow Cancellations (required, "yes" or "no").
            $allowcancel = strtolower($record['Allow Cancellations'] ?? '');
            if (!in_array($allowcancel, ['yes', 'no'], true)) {
                $this->errors[] = [
                    $index,
                    get_string('error:invalidallowcancel', 'facetoface')
                ];

                continue;
            }

            // Allow Overbookings (required, "yes" or "no").
            $allowover = strtolower($record['Allow Overbookings'] ?? '');
            if (!in_array($allowover, ['yes', 'no'], true)) {
                $this->errors[] = [
                    $index,
                    get_string('error:invalidallowoverbook', 'facetoface')
                ];

                continue;
            }
        }
        return $this->errors;
    }

    /**
     * Process valid records to create sessions.
     * Assumes validate() has already been called to check correctness.
     *
     * @return bool true on success, false if any errors occurred
     */
    public function process(): bool {
        global $DB;

        $allcustomfields = facetoface_get_session_customfields();
        $customfieldsbyshortname = [];

        foreach ($allcustomfields as $field) {
            $customfieldsbyshortname[strtolower($field->shortname)] = $field;
        }

        foreach ($this->records as $index => $record) {
            $session = new stdClass();

            $shortname = trim($record['Course Shortname']);
            $f2fname = trim($record['Face-to-Face Activity Name']);
            $matched = $this->match_records($shortname, $f2fname);
            $course = $matched['course'];
            $f2frecord = $matched['facetoface'];

            if (!$course) {
                $this->errors[] = [
                    $index,
                    get_string('error:coursenotfound', 'facetoface', $shortname)];

                continue;
            }

            if (!$f2frecord) {
                $this->errors[] = [
                    $index,
                    get_string(
                        'error:f2fnotfound',
                        'facetoface',
                        (object)['shortname' => $shortname,
                            'f2fname'   => $f2fname]
                    )
                ];

                continue;
            }

            $session->facetoface = $f2frecord->id;

            $session->datetimeknown = 1;
            if (
                isset($record['Session Date/Time Known']) &&
                $record['Session Date/Time Known'] === 'no'
            ) {
                $session->datetimeknown = 0;
            }

            $session->starttime = null;
            if (
                !empty($record['Start Date']) &&
                !empty($record['Start Time'])
            ) {
                $session->starttime = strtotime(str_replace('/', '-', $record['Start Date'].' '.$record['Start Time']));
            }

            $session->finishtime = null;
            if (
                !empty($record['Finish Date']) &&
                !empty($record['Finish Time'])
            ) {
                $session->finishtime = strtotime(str_replace('/', '-', $record['Finish Date'].' '.$record['Finish Time']));
            }

            if (
                $session->datetimeknown &&
                (empty($session->starttime) ||
                    empty($session->finishtime))
            ) {
                $this->errors[] = [
                    $index,
                    get_string('error:invaliddatetimedata', 'facetoface')
                ];

                continue;
            }

            $session->allowcancellations = 1;
            if (
                isset($record['Allow Cancellations']) &&
                $record['Allow Cancellations'] === 'no'
            ) {
                $session->allowcancellations = 0;
            }

            $session->capacity = 10;
            if (
                isset($record['Capacity']) &&
                is_numeric($record['Capacity'])
            ) {
                $session->capacity = (int)$record['Capacity'];
            }

            $session->allowoverbook = 1;
            if (
                isset($record['Allow Overbookings']) &&
                $record['Allow Overbookings'] === 'no'
            ) {
                $session->allowoverbook = 0;
            }

            $session->duration = 0;
            if (
                isset($record['Duration']) &&
                is_numeric($record['Duration'])
            ) {
                $session->duration = (int)$record['Duration'];
            }

            $session->normalcost = 0;
            if (
                isset($record['Normal Cost']) &&
                is_numeric($record['Normal Cost'])
            ) {
                $session->normalcost = $record['Normal Cost'];
            }

            $session->discountcost = 0;
            if (
                isset($record['Discount Cost']) &&
                is_numeric($record['Discount Cost'])
            ) {
                $session->discountcost = $record['Discount Cost'];
            }

            $session->details = '';
            if (
                isset($record['Details']) &&
                is_string($record['Details'])
            ) {
                $session->details = $record['Details'];
            }

            $session->timecreated = time();
            $session->timemodified = time();

            $sessionid = $DB->insert_record('facetoface_sessions', $session);
            if (!$sessionid) {
                $this->errors[] = [
                    $index,
                    get_string('error:failedtocreatesession', 'facetoface')
                ];

                continue;
            }

            // Insert session dates.
            $sessionsdate = new stdClass();
            $sessionsdate->sessionid = $sessionid;
            $sessionsdate->timestart = $session->starttime;
            $sessionsdate->timefinish = $session->finishtime;
            $sessionsdateid = $DB->insert_record('facetoface_sessions_dates', $sessionsdate);

            if (!$sessionsdateid) {
                $this->errors[] = [
                    $index,
                    get_string('error:failedtocreatedates', 'facetoface', $sessionid)
                ];
            }

            foreach ($record as $column => $value) {
                // If the column does not start with "Customfield_", skip it.
                if (strpos($column, 'Customfield_') !== 0) {

                    continue;

                }

                $shortname = strtolower(substr($column, strlen('Customfield_')));

                // If we don’t have a matching custom field for $shortname, skip it.
                if (!isset($customfieldsbyshortname[$shortname])) {
                    $this->errors[] = [
                        $index,
                        get_string('error:unknowncustomfieldshort', 'facetoface', $shortname)
                    ];

                    continue;
                }

                // Otherwise, save the custom field.
                $field = $customfieldsbyshortname[$shortname];
                if (!facetoface_save_customfield_value($field->id, $value, $sessionid, 'session')) {
                    $this->errors[] = [
                        $index,
                        get_string('error:couldnotsavecustomfieldshort', 'facetoface', $shortname)
                    ];
                }
            }
        }

        return empty($this->errors);
    }

    /**
     * Finds a course and face-to-face activity by shortname and activity name.
     *
     * @param string $courseshortname The shortname of the course.
     * @param string $activityname The name of the Face-to-Face activity.
     * @return array An array with keys 'course' and 'facetoface' (or nulls if not found).
     * @throws \dml_exception
     */
    private function match_records(string $courseshortname, string $activityname): array {
        global $DB;

        $shortnamecondition = $DB->sql_equal(
            'shortname',
            ':shortname',
            false
        );

        $course = $DB->get_record_select(
            'course',
            $shortnamecondition,
            ['shortname' => $courseshortname]
        );

        if (!$course) {
            return [
                'course' => null,
                'facetoface' => null];
        }

        $namecondition = $DB->sql_equal(
            'name',
            ':f2fname',
            false
        );

        $where = $namecondition . ' AND course = :courseid';
        $params = [
            'f2fname' => $activityname,
            'courseid' => $course->id
        ];

        $facetoface = $DB->get_record_select(
            'facetoface',
            $where,
            $params
        );

        return [
            'course' => $course,
            'facetoface' => $facetoface
        ];
    }
}
