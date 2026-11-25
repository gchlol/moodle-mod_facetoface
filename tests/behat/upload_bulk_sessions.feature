@mod @mod_facetoface @_file_upload @javascript
Feature: Preview a bulk session upload
  In order to check sessions before they are created
  As an administrator
  I need to see a preview of a valid sessions CSV without errors

  Background:
    Given the following config values are set as admin:
      | setupinstallcheck | hidemodal | theme_remui |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "activity" exists:
      | activity | facetoface |
      | course   | C1         |
      | name     | Seminar 1  |
    And I log in as "admin"

  Scenario: Preview a valid sessions CSV uploaded from a Face-to-Face activity
    Given I am on the "Seminar 1" "facetoface activity" page
    And I follow "Upload sessions"
    When I upload "mod/facetoface/tests/fixtures/bulk_sessions_activitylevel.csv" file to "Bulk Upload Session CSV File" filemanager
    And I press "Upload and preview sessions"
    Then I should see "Bulk Upload Preview"
    And I should see "Activity level preview session" in the ".f2fconfirmuploadlist" "css_element"
    And "Confirm and process" "button" should exist

  Scenario: Preview a valid sessions CSV uploaded from site administration
    Given I visit "/mod/facetoface/uploadbulksessions_sitelevel.php"
    When I upload "mod/facetoface/tests/fixtures/bulk_sessions_sitelevel.csv" file to "Bulk Upload Session CSV File" filemanager
    And I press "Upload and preview sessions"
    Then I should see "Bulk Upload Preview"
    And I should see "Site level preview session" in the ".f2fconfirmuploadlist" "css_element"
    And "Confirm and process" "button" should exist
