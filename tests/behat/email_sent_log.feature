@mod @mod_facetoface @behat_email
Feature: Face-to-Face logs the confirmation and reminder emails sent to attendees
  In order to know which attendees were emailed about a session
  As a teacher
  I need one log entry per session for each batch of confirmation or reminder emails

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
      | activity            | facetoface            |
      | course              | C1                    |
      | name                | Seminar 1             |
      | confirmationsubject | Booking confirmed     |
      | confirmationmessage | You are booked.       |
      | remindersubject     | Session reminder      |
      | remindermessage     | Your session is soon. |
      | reminderperiod      | 7                     |
    And the following "mod_facetoface > sessions" exist:
      | facetoface | details   | timestart   | timefinish          |
      | Seminar 1  | Session A | ##+2 days## | ##+2 days +1 hour## |

  Scenario: Booking others on the attendees page logs one confirmation for the emailed attendees
    Given I am on the "Seminar 1" "facetoface activity" page logged in as "teacher1"
    And I follow "Attendees"
    And I follow "Add/remove attendees"
    When I set the field "addselect" to "Student 1, Student 2"
    And I press "Add"
    And I am on the "C1" "report_log > Logs" page
    And I press "Get these logs"
    Then I should see "1" occurrences of "Confirmation email sent" in the "reportlog" "table"
    And the following should exist in the "reportlog" table:
      | User full name | Event name              | Description                          |
      | Teacher 1      | Confirmation email sent | following booking others via the GUI |
    And the "Confirmation email sent" log for "Session A" should list the user ids of "student1, student2"
    And user "student1" should have 1 emails
    And user "student2" should have 1 emails

  Scenario: The cron task logs one reminder for the emailed attendees and does not log it again
    Given I am on the "Seminar 1" "facetoface activity" page logged in as "teacher1"
    And I follow "Attendees"
    And I follow "Add/remove attendees"
    # Book without confirmations, so the attendees receive only the reminders.
    And I set the field "Suppress email notification" to "1"
    And I set the field "addselect" to "Student 1, Student 2"
    And I press "Add"
    When I run the Face-to-Face cron task
    And I am on the "C1" "report_log > Logs" page
    And I press "Get these logs"
    Then I should see "1" occurrences of "Reminder email sent" in the "reportlog" "table"
    And the "Reminder email sent" log for "Session A" should list the user ids of "student1, student2"
    And user "student1" should have 1 emails
    And user "student2" should have 1 emails
    When I run the Face-to-Face cron task
    And I reload the page
    Then I should see "1" occurrences of "Reminder email sent" in the "reportlog" "table"
    And user "student1" should have 1 emails
    And user "student2" should have 1 emails

  Scenario: Signing up for a session logs a confirmation as a self sign-up
    Given I am on the "Seminar 1" "facetoface activity" page logged in as "student1"
    And I follow "Sign-up"
    When I press "Sign-up"
    And I am on the "C1" "report_log > Logs" page logged in as "teacher1"
    And I press "Get these logs"
    Then I should see "1" occurrences of "Confirmation email sent" in the "reportlog" "table"
    And the following should exist in the "reportlog" table:
      | User full name | Event name              | Description                        |
      | Student 1      | Confirmation email sent | following self sign-up via the GUI |
    And the "Confirmation email sent" log for "Session A" should list the user ids of "student1"
    And user "student1" should have 1 emails

  @javascript @_file_upload
  Scenario: Uploading bookings for an activity logs one confirmation per session
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
    When I press "Confirm and process"
    And I am on the "C1" "report_log > Logs" page
    And I press "Get these logs"
    Then I should see "2" occurrences of "Confirmation email sent" in the "reportlog" "table"
    And the following should exist in the "reportlog" table:
      | User full name | Event name              | Description          |
      | Teacher 1      | Confirmation email sent | following CSV upload |
    And the "Confirmation email sent" log for "Session A" should list the user ids of "student1, student2"
    And the "Confirmation email sent" log for "Session B" should list the user ids of "student3"
    And user "student1" should have 1 emails
    And user "student2" should have 1 emails
    And user "student3" should have 1 emails

  @javascript @_file_upload
  Scenario: Uploading bookings for any course logs one confirmation per session
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
    When I press "Confirm and process"
    And I am on the "C1" "report_log > Logs" page
    And I press "Get these logs"
    Then I should see "2" occurrences of "Confirmation email sent" in the "reportlog" "table"
    And the following should exist in the "reportlog" table:
      | User full name | Event name              | Description          |
      | Admin User     | Confirmation email sent | following CSV upload |
    And the "Confirmation email sent" log for "Session A" should list the user ids of "student1, student2"
    And the "Confirmation email sent" log for "Session B" should list the user ids of "student3"
    And user "student1" should have 1 emails
    And user "student2" should have 1 emails
    And user "student3" should have 1 emails

  Scenario: Booking others without email notifications logs no confirmation
    Given I am on the "Seminar 1" "facetoface activity" page logged in as "teacher1"
    And I follow "Attendees"
    And I follow "Add/remove attendees"
    And I set the field "Suppress email notification" to "1"
    When I set the field "addselect" to "Student 1, Student 2"
    And I press "Add"
    And I am on the "C1" "report_log > Logs" page
    And I press "Get these logs"
    Then I should see "Session signup"
    And I should not see "Confirmation email sent"
    And user "student1" should have 0 emails
    And user "student2" should have 0 emails

  @javascript @_file_upload
  Scenario: Uploading bookings without email notifications logs no confirmation
    Given I am on the "Seminar 1" "facetoface activity" page logged in as "teacher1"
    And I follow "Upload bookings"
    And I upload a Face-to-Face bookings file to the "Bookings file" filemanager:
      | username | session   | status | notificationtype |
      | student1 | Session A | booked | ical             |
      | student2 | Session A | booked | ical             |
    And I press "Upload and validate bookings"
    And I set the field "Suppress email notification" to "1"
    When I press "Confirm and process"
    And I am on the "C1" "report_log > Logs" page
    And I press "Get these logs"
    Then I should see "CSV processed"
    And I should not see "Confirmation email sent"
    And user "student1" should have 0 emails
    And user "student2" should have 0 emails

  Scenario: The cron task logs no reminder when the activity has no reminder message
    Given the following "activity" exists:
      | activity       | facetoface |
      | course         | C1         |
      | name           | Seminar 2  |
      | reminderperiod | 7          |
    And the following "mod_facetoface > sessions" exist:
      | facetoface | details   | timestart   | timefinish          |
      | Seminar 2  | Session C | ##+2 days## | ##+2 days +1 hour## |
    And I am on the "Seminar 2" "facetoface activity" page logged in as "teacher1"
    And I follow "Attendees"
    And I follow "Add/remove attendees"
    And I set the field "addselect" to "Student 1"
    And I press "Add"
    When I run the Face-to-Face cron task
    And I am on the "C1" "report_log > Logs" page
    And I press "Get these logs"
    Then I should see "Session signup"
    And I should not see "Reminder email sent"
    And user "student1" should have 0 emails
