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
                'sectionnum' => 1,
                'oldtimestamp' => $original,
                'newtimestamp' => $shifted,
            ],
            (object) [
                'cmid' => $assigntwo->cmid,
                'sectionnum' => 1,
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
     * Conditional apply_timestamps writes skip rows whose stored value no longer matches.
     */
    public function test_apply_timestamps_skips_stale_old_value(): void {
        global $DB;

        $original = strtotime('2026-06-01 09:00:00');
        $shifted = strtotime('2026-06-08 09:00:00');
        $edited = strtotime('2026-06-03 09:00:00');

        [$teacher, , $assign] = $this->create_teacher_assign_with_reminder($edited);
        $this->setUser($teacher);

        $result = day_scheduler::apply_timestamps(
            [$assign->cmid => $shifted],
            false,
            [$assign->cmid => $original],
        );

        $this->assertSame(0, $result->updated);
        $this->assertSame(1, $result->skippedchanged);
        $this->assertSame($edited, (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $assign->cmid]));
    }

    /**
     * A row already equal to the target date is not treated as a successful preview apply.
     */
    public function test_apply_timestamps_skips_when_value_already_matches_new(): void {
        global $DB;

        $original = strtotime('2026-06-01 09:00:00');
        $shifted = strtotime('2026-06-08 09:00:00');

        [$teacher, , $assign] = $this->create_teacher_assign_with_reminder($shifted);
        $this->setUser($teacher);

        $result = day_scheduler::apply_timestamps(
            [$assign->cmid => $shifted],
            false,
            [$assign->cmid => $original],
        );

        $this->assertSame(0, $result->updated);
        $this->assertSame(1, $result->skippedchanged);
        $this->assertSame($shifted, (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $assign->cmid]));
    }

    /**
     * Non-conditional writes skip rows that already match the target value.
     */
    public function test_apply_timestamps_skips_unchanged_without_conditional(): void {
        global $DB;

        $original = strtotime('2026-06-01 09:00:00');

        [$teacher, , $assign] = $this->create_teacher_assign_with_reminder(0);
        $this->setUser($teacher);

        $result = day_scheduler::apply_to_activities([$assign->cmid], 0);

        $this->assertSame(0, $result->updated);
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $assign->cmid]));
    }

    /**
     * Apply skips activities moved out of the previewed section before apply.
     */
    public function test_apply_shift_snapshot_skips_moved_section(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        $original = strtotime('2026-06-01 09:00:00');
        $shifted = strtotime('2026-06-08 09:00:00');

        [$teacher, $course, $assign] = $this->create_teacher_assign_with_reminder($original);
        $this->setUser($teacher);

        $sectiontwo = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 2], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('assign', $assign->cmid, 0, false, MUST_EXIST);
        moveto_module($cm, $sectiontwo);

        $snapshot = [
            (object) [
                'cmid' => $assign->cmid,
                'sectionnum' => 1,
                'oldtimestamp' => $original,
                'newtimestamp' => $shifted,
            ],
        ];

        $result = day_scheduler::apply_shift_snapshot($snapshot);

        $this->assertSame(0, $result->updated);
        $this->assertSame(1, $result->skipped);
        $this->assertSame($original, (int) $DB->get_field('course_modules', 'completionexpected', ['id' => $assign->cmid]));
    }

    /**
     * Preview snapshots expire after TTL.
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
        $token = shift_preview::save($teacher->id, $preview, $params);

        $this->assertNotNull(shift_preview::consume($token));

        $token = shift_preview::save($teacher->id, $preview, $params);
        $SESSION->{shift_preview::SESSION_KEY}['previews'][$token]->time = time() - shift_preview::TTL - 1;
        $this->assertNull(shift_preview::consume($token));
    }

    /**
     * Concurrent previews keep separate snapshots until each token is consumed.
     */
    public function test_concurrent_previews_consume_matching_token_only(): void {
        $original = strtotime('2026-06-01 09:00:00');

        [$teacher, $course, ] = $this->create_teacher_assign_with_reminder($original);
        $this->setUser($teacher);

        $previewone = day_scheduler::preview_shift([$course], 1, 1, 7, false);
        $tokenone = shift_preview::save($teacher->id, $previewone, (object) [
            'days' => 7,
            'direction' => 'forward',
        ]);

        $previewtwo = day_scheduler::preview_shift([$course], 1, 1, 14, false);
        $tokentwo = shift_preview::save($teacher->id, $previewtwo, (object) [
            'days' => 14,
            'direction' => 'forward',
        ]);

        $this->assertNotSame($tokenone, $tokentwo);

        $second = shift_preview::consume($tokentwo);
        $this->assertNotNull($second);
        $this->assertSame(14, $second->days);
        $this->assertSame(
            (int) $previewtwo->items[0]->newtimestamp,
            (int) $second->items[0]->newtimestamp,
        );

        $first = shift_preview::consume($tokenone);
        $this->assertNotNull($first);
        $this->assertSame(7, $first->days);
        $this->assertSame(
            (int) $previewone->items[0]->newtimestamp,
            (int) $first->items[0]->newtimestamp,
        );

        $this->assertNull(shift_preview::consume($tokenone));
        $this->assertNull(shift_preview::consume($tokentwo));
    }

    /**
     * save() purges expired previews before storing a new snapshot.
     */
    public function test_save_purges_expired_previews(): void {
        global $SESSION;

        $original = strtotime('2026-06-01 09:00:00');
        [$teacher, $course, ] = $this->create_teacher_assign_with_reminder($original);
        $this->setUser($teacher);

        $preview = day_scheduler::preview_shift([$course], 1, 1, 7, false);
        $params = (object) ['days' => 7, 'direction' => 'forward'];

        $expiredtoken = shift_preview::save($teacher->id, $preview, $params);
        $SESSION->{shift_preview::SESSION_KEY}['previews'][$expiredtoken]->time = time() - shift_preview::TTL - 1;

        $activetoken = shift_preview::save($teacher->id, $preview, $params);

        $this->assertArrayNotHasKey($expiredtoken, $SESSION->{shift_preview::SESSION_KEY}['previews']);
        $this->assertArrayHasKey($activetoken, $SESSION->{shift_preview::SESSION_KEY}['previews']);
        $this->assertNotNull(shift_preview::consume($activetoken));
    }

    /**
     * save() caps retained previews per user to the newest snapshots.
     */
    public function test_save_caps_previews_per_user(): void {
        global $SESSION;

        $original = strtotime('2026-06-01 09:00:00');
        [$teacher, $course, ] = $this->create_teacher_assign_with_reminder($original);
        $this->setUser($teacher);

        $preview = day_scheduler::preview_shift([$course], 1, 1, 7, false);
        $params = (object) ['days' => 7, 'direction' => 'forward'];
        $tokens = [];

        for ($i = 0; $i < shift_preview::MAX_PER_USER + 2; $i++) {
            $tokens[] = shift_preview::save($teacher->id, $preview, $params);
            $SESSION->{shift_preview::SESSION_KEY}['previews'][$tokens[$i]]->time = time() + $i;
        }

        $remaining = array_filter(
            $SESSION->{shift_preview::SESSION_KEY}['previews'],
            static fn($data) => (int) $data->userid === (int) $teacher->id,
        );

        $this->assertCount(shift_preview::MAX_PER_USER, $remaining);

        $oldestkept = $tokens[count($tokens) - shift_preview::MAX_PER_USER];
        $this->assertArrayHasKey($oldestkept, $SESSION->{shift_preview::SESSION_KEY}['previews']);
        $this->assertArrayNotHasKey($tokens[0], $SESSION->{shift_preview::SESSION_KEY}['previews']);
        $this->assertArrayNotHasKey($tokens[1], $SESSION->{shift_preview::SESSION_KEY}['previews']);
    }

    /**
     * Empty previews are not stored, so repeated previews cannot grow the session.
     */
    public function test_empty_preview_is_not_stored(): void {
        global $SESSION;

        $this->enable_completion_globally();

        $generator = $this->getDataGenerator();
        $teacher = $generator->create_user();
        $course = $generator->create_course([
            'format' => 'daysections',
            'enablecompletion' => 1,
            'numsections' => 2,
        ], ['createsections' => true]);

        $this->setUser($teacher);

        $preview = day_scheduler::preview_shift([$course], 1, 1, 7, false);
        $params = (object) ['days' => 7, 'direction' => 'forward'];

        for ($i = 0; $i < 15; $i++) {
            $this->assertSame('', shift_preview::save($teacher->id, $preview, $params));
        }

        $this->assertEmpty($SESSION->{shift_preview::SESSION_KEY}['previews'] ?? []);
    }

    /**
     * Item snapshots are stored in cache, not embedded in the session index.
     */
    public function test_snapshot_items_live_in_cache_not_session(): void {
        global $SESSION;

        $original = strtotime('2026-06-01 09:00:00');
        [$teacher, $course, ] = $this->create_teacher_assign_with_reminder($original);
        $this->setUser($teacher);

        $preview = day_scheduler::preview_shift([$course], 1, 1, 7, false);
        $token = shift_preview::save($teacher->id, $preview, (object) [
            'days' => 7,
            'direction' => 'forward',
        ]);

        $meta = $SESSION->{shift_preview::SESSION_KEY}['previews'][$token];
        $this->assertObjectNotHasProperty('items', $meta);

        $cache = \cache::make('local_homeschool', 'shiftpreviews');
        $items = $cache->get($token);
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);
        $this->assertSame(1, (int) $items[0]->sectionnum);
    }
}
