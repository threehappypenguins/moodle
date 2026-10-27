@core @javascript @accessibility
Feature: The exception dialogue is accessible
  In order to understand an error the site has reported to me
  As a user
  I need the exception dialogue to be readable

  Background:
    Given I log in as "admin"

  Scenario: The exception dialogue meets accessibility standards
    When I am on fixture page "/lib/tests/behat/fixtures/yui_exception_dialogue_testpage.php"
    # axe skips hidden content, so confirm a stack trace frame is on screen before asserting on the dialogue.
    # Without this the check could pass having looked at nothing.
    Then I should see "fixture_inner_call" in the "Fixture exception" "dialogue"
    # A narrowed tag set rather than the standard one. This dialogue has accessibility problems which have
    # nothing to do with the colours it draws, and those belong to the issue which fixes them rather than
    # failing this one. These three cover what the colour mode work can break: wcag131 for content and
    # relationships, wcag143 for text contrast, wcag412 for name, role and value. Non-text contrast (1.4.11)
    # is not among them because axe ships no rule for it, so the borders are measured by hand instead.
    And the "Fixture exception" "dialogue" should meet "wcag131, wcag143, wcag412" accessibility standards
