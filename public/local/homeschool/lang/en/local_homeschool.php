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

/**
 * Language strings for local_homeschool.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['activitiesdeleted'] = 'Deleted {$a} activities.';
$string['activitydeleted'] = 'Deleted {$a}.';
$string['activitysettings'] = 'Settings';
$string['activityupdated'] = 'Saved.';
$string['addactivity'] = 'Add activity or resource';
$string['addtocourse'] = 'Add to course';
$string['applydate'] = 'Apply date to selected';
$string['backtodashboard'] = 'Back to dashboard';
$string['cachedef_shiftpreviews'] = 'Shift date preview data';
$string['cannoteditcompletion'] = 'This activity does not support completion tracking.';
$string['changedate'] = 'Change timeline reminder date';
$string['children'] = 'Children';
$string['choosecourse'] = 'Choose a course…';
$string['chooseday'] = 'Choose a day…';
$string['cleardate'] = 'Clear timeline reminder date';
$string['cleardateselected'] = 'Clear date on selected';
$string['clearremindersconfirm'] = 'Clear timeline reminder dates from {$a->count} activities in {$a->course}? Completion settings will not change.';
$string['clearremindersconfirmbutton'] = 'Clear dates';
$string['clearremindersdone'] = 'Cleared timeline reminders on {$a} activities.';
$string['clearremindersintro'] = 'Choose a course, then select the days whose timeline reminder dates you want to remove. This does not change completion tracking, requirements, or whether work is marked as done. Use this when you reuse a course at the start of a new year.';
$string['clearremindersinvalidcourse'] = 'Select a valid course.';
$string['clearreminderslink'] = 'Clear reminder dates';
$string['clearremindersnodates'] = 'No timeline reminder dates are set in this course.';
$string['clearremindersnone'] = 'This course has no activities you can manage.';
$string['clearremindersnoneselected'] = 'Select at least one day with a reminder date.';
$string['clearreminderstitle'] = 'Clear reminder dates';
$string['clearselecteddates'] = 'Clear selected dates';
$string['completion'] = 'Completion';
$string['completionlocked'] = 'Completion settings are locked because someone has already completed this activity.';
$string['completionlockedhint'] = 'Locked — unlock from the activity settings if you need to change this.';
$string['convertformatlink'] = 'Change format';
$string['courses'] = 'Courses';
$string['currentdaynone'] = 'No completed work yet';
$string['currentlyonheading'] = 'Currently on';
$string['dashboard'] = 'Dashboard';
$string['datenotavailable'] = 'Set a completion condition before choosing a timeline reminder date.';
$string['datesapplied'] = 'Timeline reminder set on {$a->updated} activities for Day {$a->day}.';
$string['datescleared'] = 'Timeline reminder cleared on {$a} activities.';
$string['daynumber'] = 'Day number';
$string['daynumber_help'] = 'Section number (1 = Day 1, 2 = Day 2, etc.). Section 0 (General) is not used.';
$string['daysectionsrequired'] = 'Requires Day sections format';
$string['daytitle'] = 'Day {$a}';
$string['deleteactivity'] = 'Delete {$a}';
$string['deleteselected'] = 'Delete selected';
$string['deselectall'] = 'Deselect all';
$string['enablereminderdefault'] = 'Set a default reminder time';
$string['enablereminderdefault_desc'] = 'When enabled, new timeline reminders (bulk date form and per-activity date picker) use the hour and minute below. Existing reminders keep their current time of day.';
$string['hidden'] = 'Hidden';
$string['homeschool:manage'] = 'Manage homeschool dashboard settings and schedules';
$string['homeschool:view'] = 'View the homeschool dashboard';
$string['incompletedayitem'] = '{$a->day} ({$a->count} left)';
$string['incompletedaysheading'] = 'Incomplete days';
$string['incompletedaysnone'] = 'None';
$string['invalidactivity'] = 'That activity is not part of this day section.';
$string['invalidcompletion'] = 'Invalid completion setting.';
$string['invalidcompletioncondition'] = '{$a}';
$string['invalidcompletionconditionmax'] = 'Value must be {$a} or lower.';
$string['invaliddaynumber'] = 'Enter a valid day number (1 or greater).';
$string['invalidreminderdate'] = 'Enter a valid reminder date.';
$string['missingdaysections'] = 'The Day sections course format plugin (format_daysections) must be installed and enabled before using the homeschool dashboard.';
$string['multipledates'] = 'Multiple dates';
$string['multiselecthint'] = 'Multiple activities selected — completion and submission settings are locked on those rows. Use the date controls above (or each row’s date) to set or clear timeline reminders.';
$string['navigationlink'] = 'Homeschool';
$string['noactivitiesforday'] = 'No activities found for Day {$a}.';
$string['noactivitiesselected'] = 'Select at least one activity first.';
$string['nochanges'] = 'No changes were made.';
$string['nochildrenbadge'] = 'No children';
$string['nochildrenforcourse'] = 'No enrolled children';
$string['nodatahelp'] = 'No Day sections courses found that you can manage. Create courses using the Day sections format and ensure you have permission to manage activities.';
$string['nostudents'] = 'No enrolled students found in your Day sections courses.';
$string['notset'] = 'Not set';
$string['opencourse'] = 'Open course';
$string['openday'] = 'Open a day';
$string['otherformatshelp'] = 'No Day sections courses are visible yet. Enable “Show courses not using Day sections” to find courses to convert under Course settings → Course format.';
$string['pluginname'] = 'Homeschool dashboard';
$string['privacy:metadata'] = 'The Homeschool dashboard plugin does not store any personal data.';
$string['progressattempted'] = '{$a} attempt(s)';
$string['progresscomplete'] = 'Complete';
$string['progressdraft'] = 'Draft in progress';
$string['progressfailed'] = 'Failed';
$string['progressincomplete'] = 'Incomplete';
$string['progressnotsubmitted'] = 'Not submitted';
$string['progresssubmitted'] = 'Submitted';
$string['reminderdate'] = 'Timeline reminder';
$string['reminderdatecleared'] = 'Timeline reminder date cleared.';
$string['reminderdateupdated'] = 'Timeline reminder date saved.';
$string['reminderdefaultrequired'] = 'Select a default hour and minute, or turn off “Set a default reminder time”.';
$string['reminderdefaults'] = 'Default reminder time';
$string['reminderdefaults_desc'] = 'Optionally override the time of day used for new timeline reminders. Off by default — Moodle\'s normal date/time picker behaviour is used until you enable this and choose a time.';
$string['reminderhour'] = 'Default hour';
$string['reminderhour_desc'] = 'Hour of day (0–23) for new timeline reminders.';
$string['reminderminute'] = 'Default minute';
$string['reminderminute_desc'] = 'Minute (0–59) for new timeline reminders.';
$string['scheduledate'] = 'Timeline reminder date';
$string['scheduledate_help'] = 'Applied to all selected activities. This does not change assignment due dates or quiz close dates.';
$string['sectionmissingforadd'] = 'This course does not have Day {$a} yet. Open the course to add more days first.';
$string['sectionmissingshort'] = 'day missing';
$string['selectall'] = 'Select all';
$string['settingsintro'] = 'Configure how the homeschool dashboard identifies students and aggregates courses.';
$string['sharedchildrenheading'] = 'Shared: {$a}';
$string['sharedcoursebadge'] = 'Shared';
$string['shiftalldays'] = 'All days';
$string['shiftalldays_help'] = 'When checked, every day section is included. Otherwise choose a from/to day range.';
$string['shiftappliedskippedchanged'] = '{$a} skipped (date changed since preview).';
$string['shiftappliedskippedother'] = '{$a} skipped (no longer available).';
$string['shiftappliedsummary'] = 'Shifted {$a->count} timeline reminders {$a->direction} by {$a->days} days.';
$string['shiftapply'] = 'Apply shift';
$string['shiftapplyallchanged'] = 'No reminders were shifted — {$a} changed since preview.';
$string['shiftapplyfailed'] = 'No timeline reminders were shifted.';
$string['shiftbackward'] = 'Backward';
$string['shiftcurrentdate'] = 'Current date';
$string['shiftdaycount'] = 'Number of days';
$string['shiftdaycount_help'] = 'How many calendar days to add or subtract from each timeline reminder in the selected range.';
$string['shiftdaycountinvalid'] = 'Enter at least 1 day.';
$string['shiftdaycountmax'] = 'Enter at most 365 days.';
$string['shiftdirection'] = 'Direction';
$string['shiftdirectionbackward'] = 'backward';
$string['shiftdirectionforward'] = 'forward';
$string['shiftforward'] = 'Forward';
$string['shiftfromday'] = 'From day';
$string['shiftintro'] = 'Move timeline reminder dates forward or backward across one or more day sections. This changes completion expected dates only — not assignment due dates, quiz close times, or which day section an activity belongs to. Undo is available for 30 minutes, or until you manually change a shifted reminder date.';
$string['shiftinvalidparams'] = 'Invalid shift settings.';
$string['shiftnewdate'] = 'New date';
$string['shiftnothingtoapply'] = 'No timeline reminders in that range can be shifted.';
$string['shiftoffset'] = 'Shift amount';
$string['shiftpreview'] = 'Preview shift';
$string['shiftpreviewexpired'] = 'Preview expired. Run preview again before applying.';
$string['shiftpreviewheading'] = 'Preview';
$string['shiftpreviewsummary'] = '{$a->count} timeline reminders will shift {$a->direction} by {$a->days} days.';
$string['shiftpreviewtruncated'] = '…and {$a} more not shown.';
$string['shiftrange'] = 'Day range';
$string['shiftrangeinvalid'] = '“To day” must be the same as or after “From day”.';
$string['shiftschedulelink'] = 'Shift dates';
$string['shiftskippeddeleted'] = '{$a} skipped (being deleted).';
$string['shiftskippednocompletion'] = '{$a} skipped (completion not enabled).';
$string['shiftskippednodate'] = '{$a} skipped (no reminder date).';
$string['shiftskippedpermission'] = '{$a} skipped (no permission).';
$string['shifttitle'] = 'Shift dates';
$string['shifttoday'] = 'To day';
$string['shiftundo'] = 'Undo last shift';
$string['shiftundoallskipped'] = 'No reminder dates were restored — {$a} could not be undone (no longer available).';
$string['shiftundoavailable'] = 'Last shift: {$a} (undo available for 30 minutes)';
$string['shiftundofailed'] = 'Nothing to undo.';
$string['shiftundone'] = 'Restored {$a} timeline reminder dates.';
$string['showallactivities'] = 'Show all courses/activities in one list';
$string['showalllist'] = 'Show all courses/activities in one list';
$string['showchildsurname'] = 'Show surname in child names';
$string['showchildsurname_desc'] = 'When enabled, children are shown with first name and surname (Moodle\'s last name field), using the site full name format. When disabled, only the first name is shown on the dashboard and day page.';
$string['showhiddencourses'] = 'Show hidden courses ({$a})';
$string['showotherformats'] = 'Show courses not using Day sections ({$a})';
$string['studentrole'] = 'Student role shortname';
$string['studentrole_desc'] = 'Users with this role in your Day sections courses appear as children on the dashboard. Default: student';
$string['submissions'] = 'Submission types';
