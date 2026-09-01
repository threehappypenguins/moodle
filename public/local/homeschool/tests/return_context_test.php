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

/**
 * Tests for per-flow modedit return context.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_homeschool\local\return_context
 */
final class return_context_test extends \local_homeschool\base_testcase {

    /**
     * Concurrent arms for the same course get distinct tokens and FIFO consume order.
     */
    public function test_concurrent_flows_consume_in_fifo_order(): void {
        $tokenone = return_context::arm(1, 42);
        $tokentwo = return_context::arm(2, 42);

        $this->assertNotSame('', $tokenone);
        $this->assertNotSame('', $tokentwo);
        $this->assertNotSame($tokenone, $tokentwo);

        $first = return_context::consume_for_course(42);
        $this->assertNotNull($first);
        $this->assertSame(1, (int) $first->get_param('day'));

        $second = return_context::consume_for_course(42);
        $this->assertNotNull($second);
        $this->assertSame(2, (int) $second->get_param('day'));

        $this->assertNull(return_context::consume_for_course(42));
    }

    /**
     * Course consume only drains flows armed for that course.
     */
    public function test_consume_for_course_does_not_steal_other_courses(): void {
        return_context::arm(1, 10);
        return_context::arm(3, 20);

        $url = return_context::consume_for_course(20);
        $this->assertNotNull($url);
        $this->assertSame(3, (int) $url->get_param('day'));

        $remaining = return_context::consume_for_course(10);
        $this->assertNotNull($remaining);
        $this->assertSame(1, (int) $remaining->get_param('day'));
    }

    /**
     * touch_flow marks the correct flow active so mod view clears only that flow.
     */
    public function test_clear_active_for_module_clears_touched_flow_only(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $course->id]);

        $tokenone = return_context::arm(1, $course->id);
        return_context::arm(2, $course->id);

        return_context::touch_flow($tokenone);

        return_context::clear_active_for_module($assign->cmid);

        $url = return_context::consume_for_course($course->id);
        $this->assertNotNull($url);
        $this->assertSame(2, (int) $url->get_param('day'));

        $this->assertNull(return_context::consume_for_course($course->id));
        $this->assertFalse(return_context::has_pending());
    }

    /**
     * Expired flows are purged and no longer consumed.
     */
    public function test_purge_expired_removes_stale_flows(): void {
        global $SESSION;

        $token = return_context::arm(5, 99);
        $this->assertTrue(return_context::has_pending());

        $SESSION->{return_context::SESSION_KEY}['flows'][$token]['time'] = time() - return_context::TTL - 1;

        return_context::purge_expired();

        $this->assertFalse(return_context::has_pending());
        $this->assertNull(return_context::consume_for_course(99));
    }

    /**
     * showall and showhidden flags are preserved in the return URL.
     */
    public function test_arm_preserves_day_page_flags(): void {
        return_context::arm(4, 7, true, true);

        $url = return_context::consume_for_course(7);
        $this->assertNotNull($url);
        $this->assertSame(4, (int) $url->get_param('day'));
        $this->assertSame('1', (string) $url->get_param('showall'));
        $this->assertSame('1', (string) $url->get_param('showhidden'));
    }
}
