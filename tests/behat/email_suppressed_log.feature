@mod @mod_facetoface @behat_email
Feature: Face-to-Face logs the confirmation emails that bookers suppress
  In order to know which attendees were booked without the confirmation email they would otherwise have received
  As a teacher
  I need one log entry per session that lists the attendees whose confirmation email I suppressed

  Background:
    Given an email catcher server is configured
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
      | student2 | Student   | 2        | student2@example.com |
      | student3 | Student   | 3        | student3@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And the following "activity" exists:
      | activity            | facetoface        |
      | course              | C1                |
      | name                | Seminar 1         |
      | confirmationsubject | Booking confirmed |
      | confirmationmessage | You are booked.   |
    And the following "mod_facetoface > sessions" exist:
      | facetoface | details   | timestart   | timefinish          |
      | Seminar 1  | Session A | ##+2 days## | ##+2 days +1 hour## |

  Scenario: Booking others with email notifications suppressed logs one suppressed confirmation for the attendees
    Given I am on the "Seminar 1" "facetoface activity" page logged in as "teacher1"
    And I follow "Attendees"
    And I follow "Add/remove attendees"
    And I set the field "Suppress email notification" to "1"
    When I set the field "addselect" to "Student 1, Student 2"
    And I press "Add"
    And I am on the "C1" "report_log > Logs" page
    And I press "Get these logs"
    Then I should see "1" occurrences of "Confirmation email suppressed" in the "reportlog" "table"
    And the following should exist in the "reportlog" table:
      | User full name | Event name                    | Description                          |
      | Teacher 1      | Confirmation email suppressed | following booking others via the GUI |
    And the "Confirmation email suppressed" log for "Session A" should list the user ids of "student1, student2"
    And I should not see "Confirmation email sent"
    And user "student1" should have 0 emails
    And user "student2" should have 0 emails

  Scenario: Booking others with email notifications on logs no suppressed confirmation
    Given I am on the "Seminar 1" "facetoface activity" page logged in as "teacher1"
    And I follow "Attendees"
    And I follow "Add/remove attendees"
    When I set the field "addselect" to "Student 1"
    And I press "Add"
    And I am on the "C1" "report_log > Logs" page
    And I press "Get these logs"
    Then I should see "Confirmation email sent"
    And I should not see "Confirmation email suppressed"
    And user "student1" should have 1 emails

  Scenario: Booking others into a session that has started logs no suppressed confirmation because none would be sent
    Given the following "activity" exists:
      | activity            | facetoface        |
      | course              | C1                |
      | name                | Seminar 2         |
      | confirmationsubject | Booking confirmed |
      | confirmationmessage | You are booked.   |
    And the following "mod_facetoface > sessions" exist:
      | facetoface | details   | timestart   | timefinish          |
      | Seminar 2  | Session P | ##-2 days## | ##-2 days +1 hour## |
    And I am on the "Seminar 2" "facetoface activity" page logged in as "teacher1"
    And I follow "Attendees"
    And I follow "Add/remove attendees"
    And I set the field "Suppress email notification" to "1"
    When I set the field "addselect" to "Student 1"
    And I press "Add"
    And I am on the "C1" "report_log > Logs" page
    And I press "Get these logs"
    Then I should see "Session signup"
    And I should not see "Confirmation email suppressed"
    And user "student1" should have 0 emails

  @javascript @_file_upload
  Scenario: Uploading bookings for an activity with email notifications suppressed logs one suppressed confirmation per session
    Given the following "mod_facetoface > sessions" exist:
      | facetoface | details   | timestart   | timefinish          |
      | Seminar 1  | Session B | ##+3 days## | ##+3 days +1 hour## |
    And I am on the "Seminar 1" "facetoface activity" page logged in as "teacher1"
    And I follow "Upload bookings"
    And I upload a Face-to-Face bookings file to the "Bookings file" filemanager:
      | username | session   | status | notificationtype |
      | student1 | Session A | booked | ical             |
      | student2 | Session A | booked | ical             |
      | student3 | Session B | booked | ical             |
    And I press "Upload and validate bookings"
    And I set the field "Suppress email notification" to "1"
    When I press "Confirm and process"
    And I am on the "C1" "report_log > Logs" page
    And I press "Get these logs"
    Then I should see "2" occurrences of "Confirmation email suppressed" in the "reportlog" "table"
    And the following should exist in the "reportlog" table:
      | User full name | Event name                    | Description          |
      | Teacher 1      | Confirmation email suppressed | following CSV upload |
    And the "Confirmation email suppressed" log for "Session A" should list the user ids of "student1, student2"
    And the "Confirmation email suppressed" log for "Session B" should list the user ids of "student3"
    And I should not see "Confirmation email sent"
    And user "student1" should have 0 emails
    And user "student2" should have 0 emails
    And user "student3" should have 0 emails

  @javascript @_file_upload
  Scenario: Uploading bookings for any course with email notifications suppressed logs one suppressed confirmation per session
    # Stop the RemUI theme's setup wizard from covering the admin page.
    Given the following config values are set as admin:
      | setupinstallcheck | hidemodal | theme_remui |
    And the following "mod_facetoface > sessions" exist:
      | facetoface | details   | timestart   | timefinish          |
      | Seminar 1  | Session B | ##+3 days## | ##+3 days +1 hour## |
    And I log in as "admin"
    And I visit "/mod/facetoface/uploadbulkattendance.php"
    And I upload a Face-to-Face bookings file to the "Bulk Bookings File" filemanager:
      | Username | Session   | Status | Notification Type |
      | student1 | Session A | booked | ical              |
      | student2 | Session A | booked | ical              |
      | student3 | Session B | booked | ical              |
    And I press "Upload and preview bookings"
    And I set the field "Suppress email notification" to "1"
    When I press "Confirm and process"
    And I am on the "C1" "report_log > Logs" page
    And I press "Get these logs"
    Then I should see "2" occurrences of "Confirmation email suppressed" in the "reportlog" "table"
    And the following should exist in the "reportlog" table:
      | User full name | Event name                    | Description          |
      | Admin User     | Confirmation email suppressed | following CSV upload |
    And the "Confirmation email suppressed" log for "Session A" should list the user ids of "student1, student2"
    And the "Confirmation email suppressed" log for "Session B" should list the user ids of "student3"
    And I should not see "Confirmation email sent"
    And user "student1" should have 0 emails
    And user "student2" should have 0 emails
    And user "student3" should have 0 emails
