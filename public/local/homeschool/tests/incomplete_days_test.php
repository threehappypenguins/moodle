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
 * Tests for leftover due days per child.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_homeschool\local\incomplete_days
 * @covers \local_homeschool\output\day_page
 */
final class incomplete_days_test extends \local_homeschool\base_testcase {

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
     * @param \stdClass $student
     * @param \stdClass $course
     * @return \stdClass
     */
    protected function student_with_course(\stdClass $student, \stdClass $course): \stdClass {
        $student->courseids = [$course->id => $course->id];
        return $student;
    }

    /**
     * @return int
     */
    protected function yesterday(): int {
        return usergetmidnight(time()) - DAYSECS + HOURSECS;
    }

    /**
     * @return int
     */
    protected function today(): int {
        return usergetmidnight(time()) + HOURSECS;
    }

    /**
     * @return int
     */
    protected function tomorrow(): int {
        return usergetmidnight(time()) + DAYSECS + HOURSECS;
    }

    /**
     * Mixed leftovers on a day are counted; finished work is not.
     */
    public function test_mixed_leftovers_are_counted(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $done = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $this->yesterday(),
        ]);
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $this->yesterday(),
        ]);
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $this->yesterday(),
        ]);

        $completion = new \completion_info($course);
        $completion->update_state(
            get_fast_modinfo($course->id)->get_cm($done->cmid),
            COMPLETION_COMPLETE,
            $student->id,
        );

        $students = [$student->id => $this->student_with_course($student, $course)];
        $leftovers = incomplete_days::get_leftovers($students);

        $this->assertCount(1, $leftovers[$student->id]);
        $this->assertSame(1, $leftovers[$student->id][0]->day);
        $this->assertSame(2, $leftovers[$student->id][0]->leftovercount);
    }

    /**
     * A fully finished day is omitted.
     */
    public function test_fully_done_day_is_omitted(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 2,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $this->yesterday(),
        ]);
        $completion = new \completion_info($course);
        $completion->update_state(
            get_fast_modinfo($course->id)->get_cm($assign->cmid),
            COMPLETION_COMPLETE,
            $student->id,
        );

        $students = [$student->id => $this->student_with_course($student, $course)];
        $this->assertSame([], incomplete_days::get_leftovers($students)[$student->id]);
    }

    /**
     * Activities without a reminder date are omitted.
     */
    public function test_no_reminder_is_omitted(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        $students = [$student->id => $this->student_with_course($student, $course)];
        $this->assertSame([], incomplete_days::get_leftovers($students)[$student->id]);
    }

    /**
     * Future reminder dates are omitted.
     */
    public function test_future_reminder_is_omitted(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $this->tomorrow(),
        ]);

        $students = [$student->id => $this->student_with_course($student, $course)];
        $this->assertSame([], incomplete_days::get_leftovers($students)[$student->id]);
    }

    /**
     * Today's reminder counts as leftover if there is no qualifying work yet.
     */
    public function test_today_is_included(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 3,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $this->today(),
        ]);

        $students = [$student->id => $this->student_with_course($student, $course)];
        $leftovers = incomplete_days::get_leftovers($students);

        $this->assertCount(1, $leftovers[$student->id]);
        $this->assertSame(3, $leftovers[$student->id][0]->day);
        $this->assertSame(1, $leftovers[$student->id][0]->leftovercount);
    }

    /**
     * Failed completion still counts as leftover.
     */
    public function test_failed_completion_is_leftover(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 2,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $this->yesterday(),
        ]);
        $DB->insert_record('course_modules_completion', [
            'coursemoduleid' => $assign->cmid,
            'userid' => $student->id,
            'completionstate' => COMPLETION_COMPLETE_FAIL,
            'timemodified' => time(),
        ]);

        $students = [$student->id => $this->student_with_course($student, $course)];
        $leftovers = incomplete_days::get_leftovers($students);

        $this->assertCount(1, $leftovers[$student->id]);
        $this->assertSame(2, $leftovers[$student->id][0]->day);
    }

    /**
     * Draft assignment submissions still count as leftover.
     */
    public function test_draft_assignment_is_leftover(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 2,
            'completion' => COMPLETION_TRACKING_NONE,
        ]);
        $DB->set_field('course_modules', 'completionexpected', $this->yesterday(), ['id' => $assign->cmid]);
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
        $this->assertCount(1, incomplete_days::get_leftovers($students)[$student->id]);
    }

    /**
     * A submitted assignment is done even without completion tracking.
     */
    public function test_submitted_assignment_is_not_leftover(): void {
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
        $DB->set_field('course_modules', 'completionexpected', $this->yesterday(), ['id' => $assign->cmid]);
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
        $this->assertSame([], incomplete_days::get_leftovers($students)[$student->id]);
    }

    /**
     * Manual completion counts as done even when the assignment was never submitted.
     */
    public function test_complete_without_submission_is_not_leftover(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 3,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $this->yesterday(),
        ]);
        $completion = new \completion_info($course);
        $completion->update_state(
            get_fast_modinfo($course->id)->get_cm($assign->cmid),
            COMPLETION_COMPLETE,
            $student->id,
        );

        $this->assertFalse($DB->record_exists('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $student->id,
            'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
        ]));

        $students = [$student->id => $this->student_with_course($student, $course)];
        $this->assertSame([], incomplete_days::get_leftovers($students)[$student->id]);
    }

    /**
     * A finished quiz attempt is done.
     */
    public function test_finished_quiz_is_not_leftover(): void {
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
        $DB->set_field('course_modules', 'completionexpected', $this->yesterday(), ['id' => $quiz->cmid]);
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
        $this->assertSame([], incomplete_days::get_leftovers($students)[$student->id]);
    }

    /**
     * General (section 0) is ignored.
     */
    public function test_section_zero_is_ignored(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 0,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $this->yesterday(),
        ]);

        $students = [$student->id => $this->student_with_course($student, $course)];
        $this->assertSame([], incomplete_days::get_leftovers($students)[$student->id]);
    }

    /**
     * Resources with no completion tracking stay off the list.
     */
    public function test_untrackable_resource_is_omitted(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_NONE,
        ]);
        $DB->set_field('course_modules', 'completionexpected', $this->yesterday(), ['id' => $page->cmid]);

        $students = [$student->id => $this->student_with_course($student, $course)];
        $this->assertSame([], incomplete_days::get_leftovers($students)[$student->id]);
    }

    /**
     * Days are ordered by reminder date, not section number.
     */
    public function test_days_are_sorted_by_reminder_date(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $older = usergetmidnight(time()) - (7 * DAYSECS) + HOURSECS;
        $newer = $this->yesterday();

        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 2,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $newer,
        ]);
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 5,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $older,
        ]);

        $students = [$student->id => $this->student_with_course($student, $course)];
        $leftovers = incomplete_days::get_leftovers($students);

        $this->assertSame([5, 2], array_map(static fn($item) => $item->day, $leftovers[$student->id]));
    }

    /**
     * Leftovers in a course the child is not attached to are ignored.
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

        $this->getDataGenerator()->create_module('assign', [
            'course' => $other->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $this->yesterday(),
        ]);

        $students = [$student->id => $this->student_with_course($student, $course)];
        $this->assertSame([], incomplete_days::get_leftovers($students)[$student->id]);
    }

    /**
     * The day page lists leftover days with counts and links.
     */
    public function test_day_page_lists_leftovers_with_counts(): void {
        global $DB, $PAGE;

        [, $course] = $this->create_teacher_course(9);
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $adam = $this->getDataGenerator()->create_user(['firstname' => 'Adam', 'lastname' => 'Z']);
        $zoe = $this->getDataGenerator()->create_user(['firstname' => 'Zoe', 'lastname' => 'A']);
        $this->getDataGenerator()->enrol_user($adam->id, $course->id, $studentrole->id);
        $this->getDataGenerator()->enrol_user($zoe->id, $course->id, $studentrole->id);

        $dayfourone = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 4,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $this->yesterday(),
        ]);
        $dayfourtwo = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 4,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $this->yesterday(),
        ]);
        $dayfourthree = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 4,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $this->yesterday(),
        ]);
        $daynine = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 9,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $this->today(),
        ]);

        $completion = new \completion_info($course);
        $modinfo = get_fast_modinfo($course->id);
        foreach ([$dayfourone, $dayfourtwo, $dayfourthree, $daynine] as $activity) {
            $completion->update_state($modinfo->get_cm($activity->cmid), COMPLETION_COMPLETE, $zoe->id);
        }

        $page = new \local_homeschool\output\day_page(0, [$course], '', false, 9, 0, false);
        $export = $page->export_for_template($PAGE->get_renderer('core'));

        $this->assertTrue($export->hasincompletedays);
        $this->assertFalse($export->incompletenone);
        $this->assertCount(2, $export->incompletechildren);
        $this->assertSame('Adam', $export->incompletechildren[0]->name);
        $this->assertSame('Zoe', $export->incompletechildren[1]->name);
        $this->assertTrue($export->incompletechildren[0]->hasleftovers);
        $this->assertFalse($export->incompletechildren[1]->hasleftovers);
        $this->assertSame('Day 4 (3 left)', $export->incompletechildren[0]->days[0]->label);
        $this->assertSame('Day 9 (1 left)', $export->incompletechildren[0]->days[1]->label);
        $this->assertStringContainsString('day=4', $export->incompletechildren[0]->days[0]->url);
        $this->assertStringContainsString('day=9', $export->incompletechildren[0]->days[1]->url);
        $this->assertSame([], $export->incompletechildren[1]->days);
    }

    /**
     * With no leftovers, the day page shows a single none line.
     */
    public function test_day_page_shows_none_when_caught_up(): void {
        global $DB, $PAGE;

        [, $course] = $this->create_teacher_course();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $student = $this->getDataGenerator()->create_user(['firstname' => 'Ada']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $page = new \local_homeschool\output\day_page(0, [$course], '', true, 5, 0, true);
        $export = $page->export_for_template($PAGE->get_renderer('core'));

        $this->assertTrue($export->hasincompletedays);
        $this->assertTrue($export->incompletenone);
        $this->assertSame([], $export->incompletechildren);
    }
}
