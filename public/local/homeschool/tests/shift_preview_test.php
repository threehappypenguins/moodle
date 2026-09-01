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
 * Tests for shift preview snapshots and apply-from-preview behaviour.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_homeschool\local\shift_preview
 * @covers \local_homeschool\local\day_scheduler::apply_shift_snapshot
 */
final class shift_preview_test extends \local_homeschool\base_testcase {

    /**
     * @param int $completionexpected
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass}
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
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $completionexpected,
        ]);

        return [$teacher, $course, $assign];
    }

    /**
     * Apply uses preview timestamps and skips rows changed since preview.
     */
    public function test_apply_shift_snapshot_skips_changed_rows(): void {
        global $DB;

        $original = strtotime('2026-06-01 09:00:00');
        $shifted = strtotime('2026-06-08 09:00:00');
        $otheroriginal = strtotime('2026-06-02 09:00:00');
        $othershifted = strtotime('2026-06-09 09:00:00');

        [$teacher, $course, $assignone] = $this->create_teacher_assign_with_reminder($original);
        $assigntwo = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionexpected' => $otheroriginal,
        ]);

        $this->setUser($teacher);

        $snapshot = [
            (object) [
                'cmid' => $assignone->cmid,
                'oldtimestamp' => $original,
                'newtimestamp' => $shifted,
            ],
            (object) [
                'cmid' => $assigntwo->cmid,
                'oldtimestamp' => $otheroriginal,
                'newtimestamp' => $othershifted,
            ],
        ];

        // Another edit changed the second reminder before apply.
        $DB->set_field('course_modules', 'completionexpected', $otheroriginal + DAYSECS, ['id' => $assigntwo->cmid]);

        $result = day_scheduler::apply_shift_snapshot($snapshot);

        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(1, $result->skippedchanged);
        $this->assertSame($shifted, (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $assignone->cmid]));
        $this->assertSame($otheroriginal + DAYSECS, (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $assigntwo->cmid]));
    }

    /**
     * Preview snapshot is stored in session and expires after TTL.
     */
    public function test_shift_preview_session_ttl(): void {
        global $SESSION;

        $original = strtotime('2026-06-01 09:00:00');
        [$teacher, $course, ] = $this->create_teacher_assign_with_reminder($original);
        $this->setUser($teacher);

        $preview = day_scheduler::preview_shift([$course], 1, 1, 7, false);
        $params = (object) [
            'days' => 7,
            'direction' => 'forward',
        ];
        shift_preview::save($teacher->id, $preview, $params);

        $this->assertNotNull(shift_preview::get_available());

        $SESSION->{shift_preview::SESSION_KEY}->time = time() - shift_preview::TTL - 1;
        $this->assertNull(shift_preview::get_available());
    }
}
