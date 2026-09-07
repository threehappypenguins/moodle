@editor @editor_tiny @tiny_transparent
Feature: Tiny transparent text
    In order to mark text as transparent in TinyMCE
    As a User
    I need to be able to apply the transparent text format from the toolbar and the Format menu

  @javascript
  Scenario: Add and remove transparent text from the toolbar
    Given I log in as "admin"
    And I open my profile in edit mode
    And I set the field "Description" to "Some text"
    And I select the "p" element in position "0" of the "Description" TinyMCE editor
    When I click on the "Transparent text" button for the "Description" TinyMCE editor
    Then the field "Description" matches value "<p><span class='tiny-transparent-text'>Some text</span></p>"
    And I select the "span" element in position "0" of the "Description" TinyMCE editor
    And I click on the "Transparent text" button for the "Description" TinyMCE editor
    And the field "Description" matches value "<p>Some text</p>"

  @javascript
  Scenario: Transparent text is available in the Format menu
    Given I log in as "admin"
    And I open my profile in edit mode
    When I click on the "Format" menu item for the "Description" TinyMCE editor
    Then I should see "Transparent text"

  @javascript
  Scenario: Permissions can be configured to control access to transparent text
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | teacher2 | Teacher   | 2        | teacher2@example.com |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "roles" exist:
      | name           | shortname | description         | archetype      |
      | Custom teacher | custom1   | Limited permissions | editingteacher |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher2 | C1     | custom1        |
    And the following "activity" exists:
      | activity | assign          |
      | course   | C1              |
      | name     | Test assignment |
    And the following "permission overrides" exist:
      | capability            | permission | role    | contextlevel | reference |
      | tiny/transparent:use  | Prohibit   | custom1 | Course       | C1        |
    # Check plugin access as a role with prohibited permissions.
    And I log in as "teacher2"
    And I am on the "Test assignment" Activity page
    And I navigate to "Settings" in current page administration
    When I click on the "Format" menu item for the "Activity instructions" TinyMCE editor
    Then I should not see "Transparent text"
    # Check plugin access as a role with allowed permissions.
    And I log in as "teacher1"
    And I am on the "Test assignment" Activity page
    And I navigate to "Settings" in current page administration
    And I click on the "Format" menu item for the "Activity instructions" TinyMCE editor
    And I should see "Transparent text"
