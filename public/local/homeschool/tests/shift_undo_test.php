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

/**
 * Tests for shift undo TTL, invalidation, and restore behaviour.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_homeschool\local\shift_undo
 */
final class shift_undo_test extends \local_homeschool\base_testcase {

    /**
     * Create a teacher, course with completion enabled, and an assign activity with a reminder date.
     *
     * @param int $completionexpected
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass} teacher, course, assign module info
     */
    protected function create_teacher_assign_with_reminder(int $completionexpected): array {
        global $DB;

        $this->enable_completion_globally();

        $generator = $this->getDataGenerator();
        $teacher = $generator->create_user();
        $course = $generator->create_course([
            'format' => 'daysections',
            'enablecompletion' => 1,
            'numsections' => 2,
        ], ['createsections' => true]);

        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher']);
        $generator->enrol_user($teacher->id, $course->id, $teacherrole->id);

        $assign = $generator->create_module('assign', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $completionexpected,
        ]);

        return [$teacher, $course, $assign];
    }

    /**
     * @param int $cmid
     * @param int $original Pre-shift completionexpected
     * @param int $shifted Post-shift completionexpected stored in DB
     * @return \stdClass
     */
    protected function make_snapshot(int $cmid, int $original, int $shifted): \stdClass {
        return (object) [
            'cmid' => $cmid,
            'timestamp' => $original,
            'shifted' => $shifted,
        ];
    }

    /**
     * @param int $userid
     * @param \stdClass[] $snapshots
     * @return void
     */
    protected function save_undo(int $userid, array $snapshots): void {
        shift_undo::save($userid, $snapshots, 'Test shift');
    }

    /**
     * @param int $secondsago How many seconds ago the undo was stored
     * @return void
     */
    protected function age_undo(int $secondsago): void {
        global $SESSION;

        $this->assertNotEmpty($SESSION->{shift_undo::SESSION_KEY});
        $SESSION->{shift_undo::SESSION_KEY}->time = time() - $secondsago;
    }

    /**
     * Undo restores pre-shift reminder dates when snapshots still match.
     */
    public function test_apply_restores_original_reminder_dates(): void {
        global $DB;

        $original = strtotime('2026-06-01 09:00:00');
        $shifted = strtotime('2026-06-08 09:00:00');

        [$teacher, , $assign] = $this->create_teacher_assign_with_reminder($shifted);
        $this->setUser($teacher);

        $this->save_undo($teacher->id, [
            $this->make_snapshot($assign->cmid, $original, $shifted),
        ]);

        $result = shift_undo::apply();

        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $result->skipped);
        $this->assertSame($original, (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $assign->cmid]));
        $this->assertNull(shift_undo::get_available());
    }

    /**
     * Undo remains available until TTL has fully elapsed.
     */
    public function test_get_available_respects_ttl_boundary(): void {
        $original = strtotime('2026-06-01 09:00:00');
        $shifted = strtotime('2026-06-08 09:00:00');

        [$teacher, , $assign] = $this->create_teacher_assign_with_reminder($shifted);
        $this->setUser($teacher);

        $this->save_undo($teacher->id, [
            $this->make_snapshot($assign->cmid, $original, $shifted),
        ]);

        // One second inside TTL so a later time() in get_available() cannot cross the boundary.
        $this->age_undo(shift_undo::TTL - 1);
        $this->assertNotNull(shift_undo::get_available());

        $this->age_undo(shift_undo::TTL + 1);
        $this->assertNull(shift_undo::get_available());
    }

    /**
     * Manual reminder edits outside Homeschool invalidate undo.
     */
    public function test_get_available_invalidates_when_shifted_value_changed(): void {
        global $DB;

        $original = strtotime('2026-06-01 09:00:00');
        $shifted = strtotime('2026-06-08 09:00:00');

        [$teacher, , $assign] = $this->create_teacher_assign_with_reminder($shifted);
        $this->setUser($teacher);

        $this->save_undo($teacher->id, [
            $this->make_snapshot($assign->cmid, $original, $shifted),
        ]);

        $DB->set_field('course_modules', 'completionexpected', $shifted + DAYSECS, ['id' => $assign->cmid]);

        $this->assertNull(shift_undo::get_available());
    }

    /**
     * Deleted activities invalidate undo and cannot be restored.
     */
    public function test_get_available_invalidates_when_activity_deleted(): void {
        $original = strtotime('2026-06-01 09:00:00');
        $shifted = strtotime('2026-06-08 09:00:00');

        [$teacher, $course, $assign] = $this->create_teacher_assign_with_reminder($shifted);
        $this->setUser($teacher);

        $this->save_undo($teacher->id, [
            $this->make_snapshot($assign->cmid, $original, $shifted),
        ]);

        \core_courseformat\formatactions::cm($course->id)->delete($assign->cmid);

        $this->assertNull(shift_undo::get_available());

        $result = shift_undo::apply();
        $this->assertSame(0, $result->updated);
        $this->assertSame(0, $result->skipped);
    }

    /**
     * Undo skips activities the user can no longer manage.
     */
    public function test_apply_skips_activities_without_manage_permission(): void {
        global $DB;

        $this->enable_completion_globally();

        $generator = $this->getDataGenerator();
        $teacher = $generator->create_user();
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher']);

        $courseone = $generator->create_course(['format' => 'daysections', 'enablecompletion' => 1]);
        $coursetwo = $generator->create_course(['format' => 'daysections', 'enablecompletion' => 1]);
        $generator->enrol_user($teacher->id, $courseone->id, $teacherrole->id);
        $generator->enrol_user($teacher->id, $coursetwo->id, $teacherrole->id);

        $originalone = strtotime('2026-06-01 09:00:00');
        $shiftedone = strtotime('2026-06-08 09:00:00');
        $originaltwo = strtotime('2026-06-02 09:00:00');
        $shiftedtwo = strtotime('2026-06-09 09:00:00');

        $assignone = $generator->create_module('assign', [
            'course' => $courseone->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $shiftedone,
        ]);
        $assigntwo = $generator->create_module('assign', [
            'course' => $coursetwo->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $shiftedtwo,
        ]);

        $this->setUser($teacher);
        $this->save_undo($teacher->id, [
            $this->make_snapshot($assignone->cmid, $originalone, $shiftedone),
            $this->make_snapshot($assigntwo->cmid, $originaltwo, $shiftedtwo),
        ]);

        $manual = enrol_get_plugin('manual');
        $enrolinstance = $DB->get_record('enrol', [
            'courseid' => $coursetwo->id,
            'enrol' => 'manual',
        ], '*', MUST_EXIST);
        $manual->unenrol_user($enrolinstance, $teacher->id);

        $result = shift_undo::apply();

        $this->assertSame(1, $result->updated);
        $this->assertSame(1, $result->skipped);
        $this->assertSame($originalone, (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $assignone->cmid]));
        $this->assertSame($shiftedtwo, (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $assigntwo->cmid]));
    }

    /**
     * Undo skips activities when Homeschool manage is revoked but manageactivities remains.
     */
    public function test_apply_skips_activities_without_homeschool_manage(): void {
        global $DB;

        $this->enable_completion_globally();

        $generator = $this->getDataGenerator();
        $teacher = $generator->create_user();
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher']);

        $courseone = $generator->create_course(['format' => 'daysections', 'enablecompletion' => 1]);
        $coursetwo = $generator->create_course(['format' => 'daysections', 'enablecompletion' => 1]);
        $generator->enrol_user($teacher->id, $courseone->id, $teacherrole->id);
        $generator->enrol_user($teacher->id, $coursetwo->id, $teacherrole->id);

        assign_capability(
            'local/homeschool:manage',
            CAP_PROHIBIT,
            $teacherrole->id,
            \context_course::instance($coursetwo->id),
            true,
        );

        $originalone = strtotime('2026-06-01 09:00:00');
        $shiftedone = strtotime('2026-06-08 09:00:00');
        $originaltwo = strtotime('2026-06-02 09:00:00');
        $shiftedtwo = strtotime('2026-06-09 09:00:00');

        $assignone = $generator->create_module('assign', [
            'course' => $courseone->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $shiftedone,
        ]);
        $assigntwo = $generator->create_module('assign', [
            'course' => $coursetwo->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $shiftedtwo,
        ]);

        $this->setUser($teacher);
        $this->save_undo($teacher->id, [
            $this->make_snapshot($assignone->cmid, $originalone, $shiftedone),
            $this->make_snapshot($assigntwo->cmid, $originaltwo, $shiftedtwo),
        ]);

        $result = shift_undo::apply();

        $this->assertSame(1, $result->updated);
        $this->assertSame(1, $result->skipped);
        $this->assertSame($originalone, (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $assignone->cmid]));
        $this->assertSame($shiftedtwo, (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $assigntwo->cmid]));
    }

    /**
     * Touching a snapshotted activity clears undo before the next apply().
     */
    public function test_invalidate_for_cmids_clears_matching_undo(): void {
        $original = strtotime('2026-06-01 09:00:00');
        $shifted = strtotime('2026-06-08 09:00:00');

        [$teacher, , $assign] = $this->create_teacher_assign_with_reminder($shifted);
        $this->setUser($teacher);

        $this->save_undo($teacher->id, [
            $this->make_snapshot($assign->cmid, $original, $shifted),
        ]);

        shift_undo::invalidate_for_cmids([$assign->cmid]);

        $this->assertNull(shift_undo::get_available());
    }
}
