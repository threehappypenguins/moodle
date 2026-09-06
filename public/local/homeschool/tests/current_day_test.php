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

namespace local_homeschool\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/local/homeschool/tests/base_testcase.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Tests for furthest day with qualifying child work.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_homeschool\local\current_day
 * @covers \local_homeschool\local\assign_work
 * @covers \local_homeschool\output\day_page
 * @covers \local_homeschool\output\dashboard
 */
final class current_day_test extends \local_homeschool\base_testcase {

    /**
     * @return array{0:\stdClass,1:\stdClass} teacher and course
     */
    protected function create_teacher_course(int $numsections = 5): array {
        global $DB;

        $this->enable_completion_globally();

        $generator = $this->getDataGenerator();
        $teacher = $generator->create_user();
        $course = $generator->create_course([
            'format' => 'daysections',
            'enablecompletion' => 1,
            'numsections' => $numsections,
        ], ['createsections' => true]);

        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher']);
        $generator->enrol_user($teacher->id, $course->id, $teacherrole->id);
        $this->setUser($teacher);

        return [$teacher, $course];
    }

    /**
     * @param \stdClass $course
     * @param \stdClass $student
     * @return \stdClass
     */
    protected function student_with_course(\stdClass $student, \stdClass $course): \stdClass {
        $student->courseids = [$course->id => $course->id];
        return $student;
    }

    /**
     * Completion on a later day wins over later completion on an earlier day.
     */
    public function test_furthest_day_uses_highest_section_not_latest_timestamp(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user(['firstname' => 'Ada']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $daytwo = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 2,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $dayone = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        $completion = new \completion_info($course);
        $modinfo = get_fast_modinfo($course->id);
        $completion->update_state($modinfo->get_cm($daytwo->cmid), COMPLETION_COMPLETE, $student->id);
        $completion->update_state($modinfo->get_cm($dayone->cmid), COMPLETION_COMPLETE, $student->id);

        $students = [$student->id => $this->student_with_course($student, $course)];
        $days = current_day::get_furthest_days($students);

        $this->assertSame(2, $days[$student->id]);
    }

    /**
     * General (section 0) does not count as a day.
     */
    public function test_section_zero_is_ignored(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $general = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 0,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $completion = new \completion_info($course);
        $completion->update_state(
            get_fast_modinfo($course->id)->get_cm($general->cmid),
            COMPLETION_COMPLETE,
            $student->id,
        );

        $students = [$student->id => $this->student_with_course($student, $course)];
        $days = current_day::get_furthest_days($students);

        $this->assertSame(0, $days[$student->id]);
    }

    /**
     * A submitted assignment counts even without completion tracking.
     */
    public function test_submitted_assignment_counts(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 4,
            'completion' => COMPLETION_TRACKING_NONE,
        ]);

        $DB->insert_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $student->id,
            'timecreated' => time(),
            'timemodified' => time(),
            'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
            'groupid' => 0,
            'attemptnumber' => 0,
            'latest' => 1,
        ]);

        $students = [$student->id => $this->student_with_course($student, $course)];
        $this->assertSame(4, current_day::get_furthest_days($students)[$student->id]);
    }

    /**
     * Draft submissions do not count.
     */
    public function test_draft_assignment_does_not_count(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 3,
            'completion' => COMPLETION_TRACKING_NONE,
        ]);

        $DB->insert_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $student->id,
            'timecreated' => time(),
            'timemodified' => time(),
            'status' => ASSIGN_SUBMISSION_STATUS_DRAFT,
            'groupid' => 0,
            'attemptnumber' => 0,
            'latest' => 1,
        ]);

        $students = [$student->id => $this->student_with_course($student, $course)];
        $this->assertSame(0, current_day::get_furthest_days($students)[$student->id]);
    }

    /**
     * An older submitted attempt does not count once a later attempt is the current draft.
     */
    public function test_reopened_draft_assignment_does_not_count(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 4,
            'completion' => COMPLETION_TRACKING_NONE,
        ]);

        $DB->insert_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $student->id,
            'timecreated' => time() - 120,
            'timemodified' => time() - 120,
            'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
            'groupid' => 0,
            'attemptnumber' => 0,
            'latest' => 0,
        ]);
        $DB->insert_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $student->id,
            'timecreated' => time(),
            'timemodified' => time(),
            'status' => ASSIGN_SUBMISSION_STATUS_DRAFT,
            'groupid' => 0,
            'attemptnumber' => 1,
            'latest' => 1,
        ]);

        $students = [$student->id => $this->student_with_course($student, $course)];
        $this->assertSame(0, current_day::get_furthest_days($students)[$student->id]);
    }

    /**
     * A team submission advances every group member, not only the submitting user.
     */
    public function test_team_submission_counts_for_every_member(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($alice->id, $course->id, $studentrole->id);
        $this->getDataGenerator()->enrol_user($bob->id, $course->id, $studentrole->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 3,
            'teamsubmission' => 1,
            'requireallteammemberssubmit' => 0,
            'completion' => COMPLETION_TRACKING_NONE,
        ]);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $alice->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $bob->id]);

        $DB->insert_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
            'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
            'groupid' => $group->id,
            'attemptnumber' => 0,
            'latest' => 1,
        ]);

        $students = [
            $alice->id => $this->student_with_course($alice, $course),
            $bob->id => $this->student_with_course($bob, $course),
        ];
        $days = current_day::get_furthest_days($students);

        $this->assertSame(3, $days[$alice->id]);
        $this->assertSame(3, $days[$bob->id]);
    }

    /**
     * A finished quiz attempt counts.
     */
    public function test_finished_quiz_attempt_counts(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'section' => 5,
            'completion' => COMPLETION_TRACKING_NONE,
        ]);

        $cm = get_fast_modinfo($course->id)->get_cm($quiz->cmid);
        $usageid = $DB->insert_record('question_usages', [
            'contextid' => \context_module::instance($cm->id)->id,
            'component' => 'mod_quiz',
            'preferredbehaviour' => 'deferredfeedback',
        ]);
        $DB->insert_record('quiz_attempts', [
            'quiz' => $quiz->id,
            'userid' => $student->id,
            'attempt' => 1,
            'uniqueid' => $usageid,
            'layout' => '',
            'currentpage' => 0,
            'preview' => 0,
            'state' => 'finished',
            'timestart' => time() - 60,
            'timefinish' => time(),
            'timemodified' => time(),
            'timemodifiedoffline' => 0,
        ]);

        $students = [$student->id => $this->student_with_course($student, $course)];
        $this->assertSame(5, current_day::get_furthest_days($students)[$student->id]);
    }

    /**
     * Preview quiz attempts do not count.
     */
    public function test_preview_quiz_attempt_does_not_count(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'section' => 5,
        ]);
        $cm = get_fast_modinfo($course->id)->get_cm($quiz->cmid);
        $usageid = $DB->insert_record('question_usages', [
            'contextid' => \context_module::instance($cm->id)->id,
            'component' => 'mod_quiz',
            'preferredbehaviour' => 'deferredfeedback',
        ]);
        $DB->insert_record('quiz_attempts', [
            'quiz' => $quiz->id,
            'userid' => $student->id,
            'attempt' => 1,
            'uniqueid' => $usageid,
            'layout' => '',
            'currentpage' => 0,
            'preview' => 1,
            'state' => 'finished',
            'timestart' => time() - 60,
            'timefinish' => time(),
            'timemodified' => time(),
            'timemodifiedoffline' => 0,
        ]);

        $students = [$student->id => $this->student_with_course($student, $course)];
        $this->assertSame(0, current_day::get_furthest_days($students)[$student->id]);
    }

    /**
     * Failed completion does not count as qualifying work.
     */
    public function test_failed_completion_does_not_count(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 2,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $DB->insert_record('course_modules_completion', [
            'coursemoduleid' => $assign->cmid,
            'userid' => $student->id,
            'completionstate' => COMPLETION_COMPLETE_FAIL,
            'timemodified' => time(),
        ]);

        $students = [$student->id => $this->student_with_course($student, $course)];
        $this->assertSame(0, current_day::get_furthest_days($students)[$student->id]);
    }

    /**
     * Work is ignored when the student is not attached to that course.
     */
    public function test_work_outside_student_courses_is_ignored(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $other = $this->getDataGenerator()->create_course([
            'format' => 'daysections',
            'enablecompletion' => 1,
            'numsections' => 2,
        ], ['createsections' => true]);
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);
        $this->getDataGenerator()->enrol_user($student->id, $other->id, $studentrole->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $other->id,
            'section' => 2,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $completion = new \completion_info($other);
        $completion->update_state(
            get_fast_modinfo($other->id)->get_cm($assign->cmid),
            COMPLETION_COMPLETE,
            $student->id,
        );

        $students = [$student->id => $this->student_with_course($student, $course)];
        $this->assertSame(0, current_day::get_furthest_days($students)[$student->id]);
    }

    /**
     * The day page lists each child when they are on different days.
     */
    public function test_day_page_lists_children_on_different_days(): void {
        global $DB, $PAGE;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $adam = $this->getDataGenerator()->create_user(['firstname' => 'Adam', 'lastname' => 'Z']);
        $zoe = $this->getDataGenerator()->create_user(['firstname' => 'Zoe', 'lastname' => 'A']);
        $this->getDataGenerator()->enrol_user($adam->id, $course->id, $studentrole->id);
        $this->getDataGenerator()->enrol_user($zoe->id, $course->id, $studentrole->id);

        $aday = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 3,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $zday = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $completion = new \completion_info($course);
        $modinfo = get_fast_modinfo($course->id);
        $completion->update_state($modinfo->get_cm($aday->cmid), COMPLETION_COMPLETE, $adam->id);
        $completion->update_state($modinfo->get_cm($zday->cmid), COMPLETION_COMPLETE, $zoe->id);

        $page = new \local_homeschool\output\day_page(0, [$course], '', false, 5, 0, false);
        $export = $page->export_for_template($PAGE->get_renderer('core'));

        $this->assertTrue($export->hascurrentdays);
        $this->assertFalse($export->currentsameday);
        $this->assertFalse($export->currentnone);
        $names = array_column($export->currentchildren, 'name');
        $this->assertSame(['Adam', 'Zoe'], $names);
        $this->assertSame('Day 3', $export->currentchildren[0]->daylabel);
        $this->assertSame('Day 1', $export->currentchildren[1]->daylabel);
        $this->assertStringContainsString('day=3', $export->currentchildren[0]->url);
    }

    /**
     * Children on the same furthest day collapse to one link.
     */
    public function test_day_page_collapses_shared_current_day(): void {
        global $DB, $PAGE;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $one = $this->getDataGenerator()->create_user(['firstname' => 'Ann']);
        $two = $this->getDataGenerator()->create_user(['firstname' => 'Ben']);
        $this->getDataGenerator()->enrol_user($one->id, $course->id, $studentrole->id);
        $this->getDataGenerator()->enrol_user($two->id, $course->id, $studentrole->id);

        $activity = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 2,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $completion = new \completion_info($course);
        $cm = get_fast_modinfo($course->id)->get_cm($activity->cmid);
        $completion->update_state($cm, COMPLETION_COMPLETE, $one->id);
        $completion->update_state($cm, COMPLETION_COMPLETE, $two->id);

        $page = new \local_homeschool\output\day_page(0, [$course], '', false, 5, 0, false);
        $export = $page->export_for_template($PAGE->get_renderer('core'));

        $this->assertTrue($export->hascurrentdays);
        $this->assertTrue($export->currentsameday);
        $this->assertSame('Day 2', $export->samedaylabel);
        $this->assertStringContainsString('day=2', $export->samedayurl);
        $this->assertSame([], $export->currentchildren);
    }

    /**
     * The dashboard lists each child when they are on different days.
     */
    public function test_dashboard_lists_children_on_different_days(): void {
        global $DB, $PAGE;

        [$teacher, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $adam = $this->getDataGenerator()->create_user(['firstname' => 'Adam', 'lastname' => 'Z']);
        $zoe = $this->getDataGenerator()->create_user(['firstname' => 'Zoe', 'lastname' => 'A']);
        $this->getDataGenerator()->enrol_user($adam->id, $course->id, $studentrole->id);
        $this->getDataGenerator()->enrol_user($zoe->id, $course->id, $studentrole->id);

        $aday = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 3,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $zday = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $completion = new \completion_info($course);
        $modinfo = get_fast_modinfo($course->id);
        $completion->update_state($modinfo->get_cm($aday->cmid), COMPLETION_COMPLETE, $adam->id);
        $completion->update_state($modinfo->get_cm($zday->cmid), COMPLETION_COMPLETE, $zoe->id);

        $dashboard = new \local_homeschool\output\dashboard($teacher->id);
        $export = $dashboard->export_for_template($PAGE->get_renderer('core'));

        $this->assertTrue($export->hasdaypicker);
        $this->assertTrue($export->hascurrentdays);
        $this->assertFalse($export->currentsameday);
        $this->assertFalse($export->currentnone);
        $names = array_column($export->currentchildren, 'name');
        $this->assertSame(['Adam', 'Zoe'], $names);
        $this->assertSame('Day 3', $export->currentchildren[0]->daylabel);
        $this->assertSame('Day 1', $export->currentchildren[1]->daylabel);
        $this->assertStringContainsString('day=3', $export->currentchildren[0]->url);
        $this->assertStringContainsString('/local/homeschool/day.php', $export->currentchildren[0]->url);
    }

    /**
     * The dashboard collapses children who share the same furthest day.
     */
    public function test_dashboard_collapses_shared_current_day(): void {
        global $DB, $PAGE;

        [$teacher, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $one = $this->getDataGenerator()->create_user(['firstname' => 'Ann']);
        $two = $this->getDataGenerator()->create_user(['firstname' => 'Ben']);
        $this->getDataGenerator()->enrol_user($one->id, $course->id, $studentrole->id);
        $this->getDataGenerator()->enrol_user($two->id, $course->id, $studentrole->id);

        $activity = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 2,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $completion = new \completion_info($course);
        $cm = get_fast_modinfo($course->id)->get_cm($activity->cmid);
        $completion->update_state($cm, COMPLETION_COMPLETE, $one->id);
        $completion->update_state($cm, COMPLETION_COMPLETE, $two->id);

        $dashboard = new \local_homeschool\output\dashboard($teacher->id);
        $export = $dashboard->export_for_template($PAGE->get_renderer('core'));

        $this->assertTrue($export->hascurrentdays);
        $this->assertTrue($export->currentsameday);
        $this->assertSame('Day 2', $export->samedaylabel);
        $this->assertStringContainsString('day=2', $export->samedayurl);
        $this->assertSame([], $export->currentchildren);
    }
}
