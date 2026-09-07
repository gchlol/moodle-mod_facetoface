@mod @mod_facetoface @mod_facetoface_upload_no_show @_file_upload
Feature: Bulk upload Face-to-Face booking attendance
  In order to record attendance for historical sessions
  As an administrator
  I need to bulk upload a person's booking with a no-show outcome

  @javascript
  Scenario: Upload one no-show booking for a past session
    Given the following config values are set as admin:
      | setupinstallcheck | hidemodal | theme_remui |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activity" exists:
      | activity             | facetoface   |
      | course               | C1           |
      | name                 | Past seminar |
      | display              | 1            |
      | completion           | 2            |
      | completionattendance | 100          |
    And the following "mod_facetoface > sessions" exist:
      | facetoface   | timestart     | timefinish             |
      | Past seminar | ##yesterday## | ##yesterday + 1 hour## |
    And course completion for "C1" requires completion of "Past seminar"
    And I log in as "admin"
    And I wait "2" seconds
    And I am on the "Past seminar" "facetoface activity" page
    And I wait "2" seconds
    And I click on "//table[contains(@class, 'f2fsessionlist')]//a[@title='Edit session']" "xpath_element"
    Then I should see "Editing session in Past seminar"
    And I should see "Session visibility"
    And I should see "Session date/time known"
    And I should see "Start time"
    And I should see "Finish time"
    And I should see "Capacity"
    And I wait "5" seconds
    When I press "Cancel"
    And I wait "2" seconds
    And I visit "/mod/facetoface/uploadbulkattendance.php"
    And I wait "3" seconds
    When I upload a "no_show" booking CSV for "student1" to "Past seminar" using the "Bulk Bookings File" filemanager
    And I wait "2" seconds
    And I press "Upload and preview bookings"
    And I wait "4" seconds
    Then I should see "Bulk Upload Preview"
    And I should see "student1" in the ".f2fconfirmuploadlist" "css_element"
    And I should see "no_show" in the ".f2fconfirmuploadlist" "css_element"
    When I press "Confirm and process"
    And I wait "4" seconds
    Then I should see "Bulk bookings have been successfully processed."
    When I am on the "Past seminar" "facetoface activity" page
    And I wait "2" seconds
    And I follow "Attendees"
    And I wait "2" seconds
    And I follow "Take attendance"
    And I wait "5" seconds
    Then I should see "Student One"
    And the field with xpath "//*[contains(concat(' ', normalize-space(@class), ' '), ' menusubmissionid_')]" matches value "No show"
    When I am on the "Course 1" "Course" page
    And I wait "2" seconds
    And I navigate to "Reports > Activity completion" in current page administration
    And I wait "5" seconds
    Then I should see "Activity completion" in the "tertiary-navigation" "region"
    And "Student One, Past seminar: Not completed" "icon" should exist in the "Student One" "table_row"
    When I navigate to "Reports > Course completion" in current page administration
    And I wait "10" seconds
    Then I should see "Course completion" in the "tertiary-navigation" "region"
    And "Student One, Past seminar: Not completed" "icon" should exist in the "Student One" "table_row"
    And "Student One, Course complete: Not completed" "icon" should exist in the "Student One" "table_row"
