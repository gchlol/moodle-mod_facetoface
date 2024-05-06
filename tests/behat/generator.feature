@mod @mod_facetoface
Feature: An activity module facetoface can be created with generator

  Background:
    Given the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1 | weeks |

  Scenario: Create facetoface activity with generator
    When the following "activity" exists:
      | activity | facetoface |
      | course   | C1         |
      | name     | Seminar 1  |
      | display  | 1          |
    And I am on the "C1" "Course" page logged in as "admin"
    Then I should see "Seminar 1"
    And I follow "View all sessions"
    And I should see "No upcoming sessions"

  Scenario: Create facetoface session with generator
    Given the following "activity" exists:
      | activity | facetoface |
      | course   | C1         |
      | name     | Seminar 1  |
      | display  | 1          |
    When the following "mod_facetoface > sessions" exist:
      | facetoface | timestart                   | timefinish                           |
      | Seminar 1  | ##first day of next month## | ##first day of next month + 1 hour## |
    And I am on the "C1" "Course" page logged in as "admin"
    Then I should see "Seminar 1"
    And I should see "Sign-up for an available upcoming session"
