@mod @mod_facetoface @mod_facetoface_course_upload_matrix @_file_upload @behat_email
Feature: Course builders bulk upload every supported Face-to-Face booking status
  In order to trust bulk booking imports for every kind of session
  As a course builder
  I need the course-scoped upload to preserve booking, notification, and completion results across the complete matrix

  @javascript
  Scenario: Upload every CSV status for future, in-progress, historical, and undated sessions
    Given an email catcher server is configured
    And the following config values are set as admin:
      | setupinstallcheck              | hidemodal | theme_remui |
      | sessioncompletiondate          | 0         | facetoface  |
      | moodle_coursecompleted_disable | 1         | message     |
    And the following "users" exist:
      | username                       | firstname | lastname              | email                                      |
      | coursebuilder                  | Course    | Builder               | coursebuilder@example.com                  |
      | blank_future                   | Blank     | Future                | blank_future@example.com                   |
      | blank_in_progress              | Blank     | In progress           | blank_in_progress@example.com              |
      | blank_historical               | Blank     | Historical            | blank_historical@example.com               |
      | blank_undated                  | Blank     | Undated               | blank_undated@example.com                  |
      | booked_future                  | Booked    | Future                | booked_future@example.com                  |
      | booked_in_progress             | Booked    | In progress           | booked_in_progress@example.com             |
      | booked_historical              | Booked    | Historical            | booked_historical@example.com              |
      | booked_undated                 | Booked    | Undated               | booked_undated@example.com                 |
      | waitlisted_future              | Waitlist  | Future                | waitlisted_future@example.com              |
      | waitlisted_in_progress         | Waitlist  | In progress           | waitlisted_in_progress@example.com         |
      | waitlisted_historical          | Waitlist  | Historical            | waitlisted_historical@example.com          |
      | waitlisted_undated             | Waitlist  | Undated               | waitlisted_undated@example.com             |
      | cancelled_future               | Cancelled | Future                | cancelled_future@example.com               |
      | cancelled_in_progress          | Cancelled | In progress           | cancelled_in_progress@example.com          |
      | cancelled_historical           | Cancelled | Historical            | cancelled_historical@example.com           |
      | cancelled_undated              | Cancelled | Undated               | cancelled_undated@example.com              |
      | no_show_future                 | No show   | Future                | no_show_future@example.com                 |
      | no_show_in_progress            | No show   | In progress           | no_show_in_progress@example.com            |
      | no_show_historical             | No show   | Historical            | no_show_historical@example.com             |
      | no_show_undated                | No show   | Undated               | no_show_undated@example.com                |
      | partially_attended_future      | Partial   | Future                | partially_attended_future@example.com      |
      | partially_attended_in_progress | Partial   | In progress           | partially_attended_in_progress@example.com |
      | partially_attended_historical  | Partial   | Historical            | partially_attended_historical@example.com  |
      | partially_attended_undated     | Partial   | Undated               | partially_attended_undated@example.com     |
      | fully_attended_future          | Full      | Future                | fully_attended_future@example.com          |
      | fully_attended_in_progress     | Full      | In progress           | fully_attended_in_progress@example.com     |
      | fully_attended_historical      | Full      | Historical            | fully_attended_historical@example.com      |
      | fully_attended_undated         | Full      | Undated               | fully_attended_undated@example.com         |
    And the following "courses" exist:
      | fullname                                | shortname | enablecompletion |
      | Face-to-Face completion is sufficient   | F2FS      | 1                |
      | Face-to-Face completion is insufficient | F2FI      | 1                |
    And the following "course enrolments" exist:
      | user          | course | role           |
      | coursebuilder | F2FS   | editingteacher |
      | coursebuilder | F2FI   | editingteacher |
    And the following "activity" exists:
      | activity             | facetoface                    |
      | course               | F2FS                          |
      | name                 | Sufficient seminar            |
      | display              | 1                              |
      | completion           | 2                              |
      | completionattendance | 100                            |
      | confirmationsubject  | Booking confirmation: [facetofacename]  |
      | confirmationmessage  | Face-to-Face matrix notification        |
      | waitlistedsubject    | Waitlist notification: [facetofacename] |
      | waitlistedmessage    | Face-to-Face matrix notification        |
      | cancellationsubject  | Booking cancellation: [facetofacename]  |
      | cancellationmessage  | Face-to-Face matrix notification        |
    And the following "activity" exists:
      | activity             | facetoface                    |
      | course               | F2FI                          |
      | name                 | Insufficient seminar          |
      | display              | 1                              |
      | completion           | 2                              |
      | completionattendance | 100                            |
      | confirmationsubject  | Booking confirmation: [facetofacename]  |
      | confirmationmessage  | Face-to-Face matrix notification        |
      | waitlistedsubject    | Waitlist notification: [facetofacename] |
      | waitlistedmessage    | Face-to-Face matrix notification        |
      | cancellationsubject  | Booking cancellation: [facetofacename]  |
      | cancellationmessage  | Face-to-Face matrix notification        |
    And the following "activity" exists:
      | activity   | page                                                        |
      | course     | F2FI                                                        |
      | name       | Additional required activity                                |
      | intro      | This activity deliberately remains incomplete in this test. |
      | content    | This activity deliberately remains incomplete in this test. |
      | completion | 1                                                           |
    And the following "mod_facetoface > sessions" exist:
      | facetoface          | timing      | capacity |
      | Sufficient seminar   | future      | 100      |
      | Sufficient seminar   | in_progress | 100      |
      | Sufficient seminar   | historical  | 100      |
      | Sufficient seminar   | undated     | 100      |
      | Insufficient seminar | future      | 100      |
      | Insufficient seminar | in_progress | 100      |
      | Insufficient seminar | historical  | 100      |
      | Insufficient seminar | undated     | 100      |
    And course completion for "F2FS" requires completion of "Sufficient seminar"
    And course completion for "F2FI" requires completion of "Insufficient seminar"
    And course completion for "F2FI" requires completion of "Additional required activity"
    And the following Face-to-Face bookings exist:
      | username              | activity             | timing      |
      | cancelled_future      | Sufficient seminar   | future      |
      | cancelled_in_progress | Sufficient seminar   | in_progress |
      | cancelled_historical  | Sufficient seminar   | historical  |
      | cancelled_undated     | Sufficient seminar   | undated     |
      | cancelled_future      | Insufficient seminar | future      |
      | cancelled_in_progress | Insufficient seminar | in_progress |
      | cancelled_historical  | Insufficient seminar | historical  |
      | cancelled_undated     | Insufficient seminar | undated     |
    And I empty the email inbox
    And I am on the "Sufficient seminar" "facetoface activity" page logged in as "coursebuilder"
    Then I should see "Upload bookings"
    When I follow "Upload bookings"
    Then I should see "Bookings file"
    And "Upload and validate bookings" "button" should exist
    When I upload the following course booking CSV using the "Bookings file" filemanager:
      | username                       | activity             | timing      | status             | notification |
      | cancelled_future               | Sufficient seminar   | future      | cancelled          | email        |
      | cancelled_in_progress          | Sufficient seminar   | in_progress | cancelled          | email        |
      | cancelled_historical           | Sufficient seminar   | historical  | cancelled          | email        |
      | cancelled_undated              | Sufficient seminar   | undated     | cancelled          | email        |
      | blank_future                   | Sufficient seminar   | future      |                    | email        |
      | blank_in_progress              | Sufficient seminar   | in_progress |                    | email        |
      | blank_historical               | Sufficient seminar   | historical  |                    | email        |
      | blank_undated                  | Sufficient seminar   | undated     |                    | email        |
      | booked_future                  | Sufficient seminar   | future      | booked             | email        |
      | booked_in_progress             | Sufficient seminar   | in_progress | booked             | email        |
      | booked_historical              | Sufficient seminar   | historical  | booked             | email        |
      | booked_undated                 | Sufficient seminar   | undated     | booked             | email        |
      | waitlisted_future              | Sufficient seminar   | future      | waitlisted         | email        |
      | waitlisted_in_progress         | Sufficient seminar   | in_progress | waitlisted         | email        |
      | waitlisted_historical          | Sufficient seminar   | historical  | waitlisted         | email        |
      | waitlisted_undated             | Sufficient seminar   | undated     | waitlisted         | email        |
      | no_show_future                 | Sufficient seminar   | future      | no_show            | email        |
      | no_show_in_progress            | Sufficient seminar   | in_progress | no_show            | email        |
      | no_show_historical             | Sufficient seminar   | historical  | no_show            | email        |
      | no_show_undated                | Sufficient seminar   | undated     | no_show            | email        |
      | partially_attended_future      | Sufficient seminar   | future      | partially_attended | email        |
      | partially_attended_in_progress | Sufficient seminar   | in_progress | partially_attended | email        |
      | partially_attended_historical  | Sufficient seminar   | historical  | partially_attended | email        |
      | partially_attended_undated     | Sufficient seminar   | undated     | partially_attended | email        |
      | fully_attended_future          | Sufficient seminar   | future      | fully_attended     | email        |
      | fully_attended_in_progress     | Sufficient seminar   | in_progress | fully_attended     | email        |
      | fully_attended_historical      | Sufficient seminar   | historical  | fully_attended     | email        |
      | fully_attended_undated         | Sufficient seminar   | undated     | fully_attended     | email        |
    And I press "Upload and validate bookings"
    Then I should see "7 errors were found in the uploaded file."
    When I press "Upload only rows with no errors"
    Then I should see "Successfully processed records."
    When I am on the "Insufficient seminar" "facetoface activity" page
    Then I should see "Upload bookings"
    When I follow "Upload bookings"
    Then I should see "Bookings file"
    And "Upload and validate bookings" "button" should exist
    When I upload the following course booking CSV using the "Bookings file" filemanager:
      | username                       | activity             | timing      | status             | notification |
      | cancelled_future               | Insufficient seminar | future      | cancelled          | email        |
      | cancelled_in_progress          | Insufficient seminar | in_progress | cancelled          | email        |
      | cancelled_historical           | Insufficient seminar | historical  | cancelled          | email        |
      | cancelled_undated              | Insufficient seminar | undated     | cancelled          | email        |
      | blank_future                   | Insufficient seminar | future      |                    | email        |
      | blank_in_progress              | Insufficient seminar | in_progress |                    | email        |
      | blank_historical               | Insufficient seminar | historical  |                    | email        |
      | blank_undated                  | Insufficient seminar | undated     |                    | email        |
      | booked_future                  | Insufficient seminar | future      | booked             | email        |
      | booked_in_progress             | Insufficient seminar | in_progress | booked             | email        |
      | booked_historical              | Insufficient seminar | historical  | booked             | email        |
      | booked_undated                 | Insufficient seminar | undated     | booked             | email        |
      | waitlisted_future              | Insufficient seminar | future      | waitlisted         | email        |
      | waitlisted_in_progress         | Insufficient seminar | in_progress | waitlisted         | email        |
      | waitlisted_historical          | Insufficient seminar | historical  | waitlisted         | email        |
      | waitlisted_undated             | Insufficient seminar | undated     | waitlisted         | email        |
      | no_show_future                 | Insufficient seminar | future      | no_show            | email        |
      | no_show_in_progress            | Insufficient seminar | in_progress | no_show            | email        |
      | no_show_historical             | Insufficient seminar | historical  | no_show            | email        |
      | no_show_undated                | Insufficient seminar | undated     | no_show            | email        |
      | partially_attended_future      | Insufficient seminar | future      | partially_attended | email        |
      | partially_attended_in_progress | Insufficient seminar | in_progress | partially_attended | email        |
      | partially_attended_historical  | Insufficient seminar | historical  | partially_attended | email        |
      | partially_attended_undated     | Insufficient seminar | undated     | partially_attended | email        |
      | fully_attended_future          | Insufficient seminar | future      | fully_attended     | email        |
      | fully_attended_in_progress     | Insufficient seminar | in_progress | fully_attended     | email        |
      | fully_attended_historical      | Insufficient seminar | historical  | fully_attended     | email        |
      | fully_attended_undated         | Insufficient seminar | undated     | fully_attended     | email        |
    And I press "Upload and validate bookings"
    Then I should see "7 errors were found in the uploaded file."
    When I press "Upload only rows with no errors"
    Then I should see "Successfully processed records."
    And the booking upload outcomes for courses "F2FS" and "F2FI" should be:
      | username                       | timing      | result   | status             | history | enrolled | activity   | sufficientcourse | insufficientcourse | email        |
      | blank_future                   | future      | accepted | booked             | 1       | yes      | incomplete | incomplete       | incomplete         | confirmation |
      | blank_in_progress              | in_progress | accepted | booked             | 1       | yes      | incomplete | incomplete       | incomplete         | none         |
      | blank_historical               | historical  | accepted | booked             | 1       | yes      | incomplete | incomplete       | incomplete         | none         |
      | blank_undated                  | undated     | accepted | waitlisted         | 1       | yes      | incomplete | incomplete       | incomplete         | waitlist     |
      | booked_future                  | future      | accepted | booked             | 1       | yes      | incomplete | incomplete       | incomplete         | confirmation |
      | booked_in_progress             | in_progress | accepted | booked             | 1       | yes      | incomplete | incomplete       | incomplete         | none         |
      | booked_historical              | historical  | accepted | booked             | 1       | yes      | incomplete | incomplete       | incomplete         | none         |
      | booked_undated                 | undated     | accepted | waitlisted         | 1       | yes      | incomplete | incomplete       | incomplete         | waitlist     |
      | waitlisted_future              | future      | accepted | waitlisted         | 1       | yes      | incomplete | incomplete       | incomplete         | waitlist     |
      | waitlisted_in_progress         | in_progress | rejected | none               | 0       | no       | incomplete | incomplete       | incomplete         | none         |
      | waitlisted_historical          | historical  | rejected | none               | 0       | no       | incomplete | incomplete       | incomplete         | none         |
      | waitlisted_undated             | undated     | accepted | waitlisted         | 1       | yes      | incomplete | incomplete       | incomplete         | waitlist     |
      | cancelled_future               | future      | accepted | cancelled          | 2       | yes      | incomplete | incomplete       | incomplete         | cancellation |
      | cancelled_in_progress          | in_progress | rejected | booked             | 1       | yes      | incomplete | incomplete       | incomplete         | none         |
      | cancelled_historical           | historical  | rejected | booked             | 1       | yes      | incomplete | incomplete       | incomplete         | none         |
      | cancelled_undated              | undated     | accepted | cancelled          | 2       | yes      | incomplete | incomplete       | incomplete         | cancellation |
      | no_show_future                 | future      | rejected | none               | 0       | no       | incomplete | incomplete       | incomplete         | none         |
      | no_show_in_progress            | in_progress | accepted | no_show            | 2       | yes      | incomplete | incomplete       | incomplete         | none         |
      | no_show_historical             | historical  | accepted | no_show            | 2       | yes      | incomplete | incomplete       | incomplete         | none         |
      | no_show_undated                | undated     | accepted (known defect) | no_show            | 2       | yes      | incomplete | incomplete       | incomplete         | confirmation |
      | partially_attended_future      | future      | rejected | none               | 0       | no       | incomplete | incomplete       | incomplete         | none         |
      | partially_attended_in_progress | in_progress | accepted | partially_attended | 2       | yes      | incomplete | incomplete       | incomplete         | none         |
      | partially_attended_historical  | historical  | accepted | partially_attended | 2       | yes      | incomplete | incomplete       | incomplete         | none         |
      | partially_attended_undated     | undated     | accepted (known defect) | partially_attended | 2       | yes      | incomplete | incomplete       | incomplete         | confirmation |
      | fully_attended_future          | future      | rejected | none               | 0       | no       | incomplete | incomplete       | incomplete         | none         |
      | fully_attended_in_progress     | in_progress | accepted | fully_attended     | 2       | yes      | complete   | complete         | incomplete         | none         |
      | fully_attended_historical      | historical  | accepted | fully_attended     | 2       | yes      | complete   | complete         | incomplete         | none         |
      | fully_attended_undated         | undated     | accepted (known defect) | fully_attended     | 2       | yes      | complete   | complete         | incomplete         | confirmation |
