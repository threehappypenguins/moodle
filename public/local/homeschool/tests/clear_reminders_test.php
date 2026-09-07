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
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for clearing course timeline reminder dates.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_homeschool\local\day_scheduler::get_course_day_reminder_rows
 * @covers \local_homeschool\local\day_scheduler::clear_course_day_reminders
 */
final class clear_reminders_test extends \local_homeschool\base_testcase {

    /**
     * @param int $numsections
     * @return array{0:\stdClass,1:\stdClass} teacher and course
     */
    protected function create_teacher_course(int $numsections = 3): array {
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
     * Clearing selected days removes reminder dates and leaves other days alone.
     */
    public function test_clear_selected_days_only(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $dayone = strtotime('2026-09-01 09:00:00');
        $daytwo = strtotime('2026-09-02 09:00:00');

        $assignone = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $dayone,
        ]);
        $assigntwo = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 2,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $daytwo,
        ]);

        $result = day_scheduler::clear_course_day_reminders($course, [1]);

        $this->assertSame(1, $result->updated);
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $assignone->cmid]));
        $this->assertSame($daytwo, (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $assigntwo->cmid]));
        $this->assertSame(COMPLETION_TRACKING_MANUAL, (int) $DB->get_field('course_modules', 'completion', ['id' => $assignone->cmid]));
    }

    /**
     * Clearing does not change completion type, view requirement, or quiz min attempts.
     */
    public function test_clear_does_not_change_completion_settings(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $expected = strtotime('2026-09-01 09:00:00');

        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1,
            'completionminattemptsenabled' => 1,
            'completionminattempts' => 2,
            'completionexpected' => $expected,
        ]);

        $result = day_scheduler::clear_course_day_reminders($course, [1]);

        $this->assertSame(1, $result->updated);
        $cm = $DB->get_record('course_modules', ['id' => $quiz->cmid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $cm->completionexpected);
        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, (int) $cm->completion);
        $this->assertSame(1, (int) $cm->completionview);

        $quizrecord = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
        $this->assertSame(2, (int) $quizrecord->completionminattempts);
    }

    /**
     * Clearing reminder dates does not change whether a child has marked work complete.
     */
    public function test_clear_does_not_change_user_completion(): void {
        global $DB;

        [, $course] = $this->create_teacher_course();
        $expected = strtotime('2026-09-01 09:00:00');
        $student = $this->getDataGenerator()->create_user();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $expected,
        ]);

        $cminfo = get_fast_modinfo($course->id)->get_cm($assign->cmid);
        $completion = new \completion_info($course);
        $completion->update_state($cminfo, COMPLETION_COMPLETE, $student->id);

        day_scheduler::clear_course_day_reminders($course, [1]);

        $cminfo = get_fast_modinfo($course->id)->get_cm($assign->cmid);
        $data = $completion->get_data($cminfo, false, $student->id);
        $this->assertSame(COMPLETION_COMPLETE, (int) $data->completionstate);
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $assign->cmid]));
    }

    /**
     * General and numbered days are listed, including days with no date.
     */
    public function test_get_course_day_reminder_rows(): void {
        [, $course] = $this->create_teacher_course();
        $dayone = strtotime('2026-09-01 09:00:00');
        $daytwoa = strtotime('2026-09-02 09:00:00');
        $daytwob = strtotime('2026-09-03 09:00:00');

        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 0,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $dayone,
        ]);
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 2,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $daytwoa,
        ]);
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 2,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $daytwob,
        ]);

        $rows = day_scheduler::get_course_day_reminder_rows($course);
        $byday = [];
        foreach ($rows as $row) {
            $byday[$row->daynumber] = $row;
        }

        $this->assertArrayHasKey(0, $byday);
        $this->assertTrue($byday[0]->hasdate);
        $this->assertSame(activity_repository::format_expected_date($dayone), $byday[0]->dateformatted);

        $this->assertArrayHasKey(1, $byday);
        $this->assertFalse($byday[1]->hasdate);
        $this->assertSame(get_string('notset', 'local_homeschool'), $byday[1]->dateformatted);

        $this->assertArrayHasKey(2, $byday);
        $this->assertTrue($byday[2]->hasdate);
        $this->assertSame(get_string('multipledates', 'local_homeschool'), $byday[2]->dateformatted);
        $this->assertSame(2, $byday[2]->datedcount);
    }

    /**
     * Clearing one course does not remove reminder dates in another course.
     */
    public function test_clear_does_not_affect_other_courses(): void {
        global $DB;

        [$teacher, $courseone] = $this->create_teacher_course();
        $coursetwo = $this->getDataGenerator()->create_course([
            'format' => 'daysections',
            'enablecompletion' => 1,
            'numsections' => 2,
        ], ['createsections' => true]);
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher']);
        $this->getDataGenerator()->enrol_user($teacher->id, $coursetwo->id, $teacherrole->id);

        $dateone = strtotime('2026-09-01 09:00:00');
        $datetwo = strtotime('2026-09-02 09:00:00');

        $assignone = $this->getDataGenerator()->create_module('assign', [
            'course' => $courseone->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $dateone,
        ]);
        $assigntwo = $this->getDataGenerator()->create_module('assign', [
            'course' => $coursetwo->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $datetwo,
        ]);

        day_scheduler::clear_course_day_reminders($courseone, [0, 1, 2]);

        $this->assertSame(0, (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $assignone->cmid]));
        $this->assertSame($datetwo, (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $assigntwo->cmid]));
    }
}
