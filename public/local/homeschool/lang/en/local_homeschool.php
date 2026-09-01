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

$string['pluginname'] = 'Homeschool dashboard';
$string['privacy:metadata'] = 'The Homeschool dashboard plugin does not store any personal data.';
$string['dashboard'] = 'Dashboard';
$string['openday'] = 'Open a day';
$string['homeschool:view'] = 'View the homeschool dashboard';
$string['homeschool:manage'] = 'Manage homeschool dashboard settings and schedules';
$string['missingdaysections'] = 'The Day sections course format plugin (format_daysections) must be installed and enabled before using the homeschool dashboard.';
$string['daysectionsrequired'] = 'Requires Day sections format';
$string['navigationlink'] = 'Homeschool';

// Dashboard.
$string['children'] = 'Children';
$string['courses'] = 'Courses';
$string['upcoming'] = 'Upcoming timeline reminders';
$string['nodatahelp'] = 'No Day sections courses found that you can manage. Create courses using the Day sections format and ensure you have permission to manage activities.';
$string['nostudents'] = 'No enrolled students found in your Day sections courses.';
$string['noupcoming'] = 'No timeline reminders scheduled in the next two weeks.';
$string['opendaylink'] = 'Open day';
$string['showhiddencourses'] = 'Show hidden courses ({$a})';
$string['showotherformats'] = 'Show courses not using Day sections ({$a})';
$string['otherformatshelp'] = 'No Day sections courses are visible yet. Enable “Show courses not using Day sections” to find courses to convert under Course settings → Course format.';
$string['convertformatlink'] = 'Change format';
$string['hidden'] = 'Hidden';

// Day hub.
$string['daytitle'] = 'Day {$a}';
$string['chooseday'] = 'Choose a day…';
$string['daynumber'] = 'Day number';
$string['daynumber_help'] = 'Section number (1 = Day 1, 2 = Day 2, etc.). Section 0 (General) is not used.';
$string['scheduledate'] = 'Timeline reminder date';
$string['scheduledate_help'] = 'Applied to all selected activities. This does not change assignment due dates or quiz close dates.';
$string['selectall'] = 'Select all';
$string['deselectall'] = 'Deselect all';
$string['noactivitiesselected'] = 'Select at least one activity first.';
$string['applydate'] = 'Apply date to selected';
$string['cleardateselected'] = 'Clear date on selected';
$string['cleardate'] = 'Clear timeline reminder date';
$string['invaliddaynumber'] = 'Enter a valid day number (1 or greater).';
$string['datesapplied'] = 'Timeline reminder set on {$a->updated} activities for Day {$a->day}.';
$string['datescleared'] = 'Timeline reminder cleared on {$a} activities.';
$string['backtodashboard'] = 'Back to dashboard';
$string['addtocourse'] = 'Add to course';
$string['choosecourse'] = 'Choose a course…';
$string['addactivity'] = 'Add activity or resource';
$string['sectionmissingforadd'] = 'This course does not have Day {$a} yet. Open the course to add more days first.';
$string['sectionmissingshort'] = 'day missing';
$string['opencourse'] = 'Open course';
$string['multiselecthint'] = 'Multiple activities selected — completion and submission settings are locked on those rows. Use the date controls above (or each row’s date) to set or clear timeline reminders.';
$string['showalllist'] = 'Show all courses/activities in one list';
$string['showallactivities'] = 'Show all courses/activities in one list';
$string['sharedchildrenheading'] = 'Shared: {$a}';
$string['sharedcoursebadge'] = 'Shared';
$string['nochildrenforcourse'] = 'No enrolled children';
$string['nochildrenbadge'] = 'No children';
$string['notset'] = 'Not set';
$string['reminderdate'] = 'Timeline reminder';
$string['changedate'] = 'Change timeline reminder date';
$string['datenotavailable'] = 'Set a completion condition before choosing a timeline reminder date.';
$string['invalidreminderdate'] = 'Enter a valid reminder date.';
$string['reminderdateupdated'] = 'Timeline reminder date saved.';
$string['reminderdatecleared'] = 'Timeline reminder date cleared.';
$string['completion'] = 'Completion';
$string['submissions'] = 'Submission types';
$string['activitysettings'] = 'Settings';
$string['noactivitiesforday'] = 'No activities found for Day {$a}.';
$string['activityupdated'] = 'Saved.';
$string['nochanges'] = 'No changes were made.';
$string['cannoteditcompletion'] = 'This activity does not support completion tracking.';
$string['invalidcompletion'] = 'Invalid completion setting.';
$string['completionlocked'] = 'Completion settings are locked because someone has already completed this activity.';
$string['completionlockedhint'] = 'Locked — unlock from the activity settings if you need to change this.';
$string['invalidactivity'] = 'That activity is not part of this day section.';
$string['deleteactivity'] = 'Delete {$a}';
$string['deleteselected'] = 'Delete selected';
$string['activitydeleted'] = 'Deleted {$a}.';
$string['activitiesdeleted'] = 'Deleted {$a} activities.';

// Settings.
$string['settingsintro'] = 'Configure how the homeschool dashboard identifies students and aggregates courses.';
$string['studentrole'] = 'Student role shortname';
$string['studentrole_desc'] = 'Users with this role in your Day sections courses appear as children on the dashboard. Default: student';
$string['showchildsurname'] = 'Show surname in child names';
$string['showchildsurname_desc'] = 'When enabled, children are shown with first name and surname (Moodle\'s last name field), using the site full name format. When disabled, only the first name is shown on the dashboard and day page.';
