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
     * Concurrent arms for the same course get distinct tokens.
     */
    public function test_concurrent_flows_get_distinct_tokens(): void {
        $tokenone = return_context::arm(1, 42);
        $tokentwo = return_context::arm(2, 42);

        $this->assertNotSame('', $tokenone);
        $this->assertNotSame('', $tokentwo);
        $this->assertNotSame($tokenone, $tokentwo);
        $this->assertTrue(return_context::has_pending());
    }

    /**
     * Consumption requires the exact flow token, not just a matching course id.
     */
    public function test_consume_for_token_requires_exact_flow(): void {
        $tokenone = return_context::arm(1, 42);
        $tokentwo = return_context::arm(2, 42);

        $second = return_context::consume_for_token($tokentwo, 42);
        $this->assertNotNull($second);
        $this->assertSame(2, (int) $second->get_param('day'));

        $first = return_context::consume_for_token($tokenone, 42);
        $this->assertNotNull($first);
        $this->assertSame(1, (int) $first->get_param('day'));

        $this->assertNull(return_context::consume_for_token($tokenone, 42));
        $this->assertFalse(return_context::has_pending());
    }

    /**
     * Token consumption rejects a course id mismatch.
     */
    public function test_consume_for_token_rejects_course_mismatch(): void {
        $token = return_context::arm(3, 20);

        $this->assertNull(return_context::consume_for_token($token, 10));
        $this->assertNotNull(return_context::consume_for_token($token, 20));
    }

    /**
     * Save and display discards the flow without redirecting to the day page later.
     */
    public function test_discard_flow_removes_pending_return(): void {
        $token = return_context::arm(1, 42);

        return_context::discard_flow($token);

        $this->assertNull(return_context::consume_for_token($token, 42));
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
        $this->assertNull(return_context::consume_for_token($token, 99));
    }

    /**
     * showall and showhidden flags are preserved in the return URL.
     */
    public function test_arm_preserves_day_page_flags(): void {
        $token = return_context::arm(4, 7, true, true);

        $url = return_context::consume_for_token($token, 7);
        $this->assertNotNull($url);
        $this->assertSame(4, (int) $url->get_param('day'));
        $this->assertSame('1', (string) $url->get_param('showall'));
        $this->assertSame('1', (string) $url->get_param('showhidden'));
    }

    /**
     * prepare_modedit_course_return defers update redirects instead of exiting early.
     */
    public function test_prepare_modedit_course_return_defers_update_redirect(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'daysections', 'numsections' => 2], ['createsections' => true]);
        $label = $generator->create_module('label', ['course' => $course->id]);

        $token = return_context::arm(2, $course->id);
        $data = (object) [
            return_context::FLOW_PARAM => $token,
            'frontend' => true,
            'section' => 1,
            'coursemodule' => $label->cmid,
            'modulename' => 'label',
        ];

        $result = return_context::prepare_modedit_course_return($data, $course);

        $this->assertSame($data, $result);
        $this->assertTrue(return_context::has_pending_update_redirect($label->cmid));
        $this->assertFalse(return_context::has_pending_update_redirect($label->cmid + 1));
    }

    /**
     * Save and display does not queue a deferred redirect.
     */
    public function test_prepare_modedit_course_return_discards_save_and_display(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'daysections', 'numsections' => 2], ['createsections' => true]);
        $label = $generator->create_module('label', ['course' => $course->id]);

        $token = return_context::arm(1, $course->id);
        $data = (object) [
            return_context::FLOW_PARAM => $token,
            'frontend' => true,
            'section' => 1,
            'coursemodule' => $label->cmid,
            'modulename' => 'label',
            'submitbutton' => 'Save and display',
        ];

        return_context::prepare_modedit_course_return($data, $course);

        $this->assertFalse(return_context::has_pending_update_redirect($label->cmid));
        $this->assertFalse(return_context::has_pending());
    }

    /**
     * Update redirects are marked ready by the observer and issued on course landing.
     */
    public function test_mark_update_redirect_ready_defers_navigation_to_course_landing(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'daysections', 'numsections' => 2], ['createsections' => true]);
        $label = $generator->create_module('label', ['course' => $course->id]);

        $token = return_context::arm(2, $course->id);
        $data = (object) [
            return_context::FLOW_PARAM => $token,
            'frontend' => true,
            'section' => 1,
            'coursemodule' => $label->cmid,
            'modulename' => 'label',
        ];

        return_context::prepare_modedit_course_return($data, $course);
        $this->assertTrue(return_context::has_pending_update_redirect($label->cmid));
        $this->assertFalse(return_context::has_ready_update_redirect($label->cmid));

        return_context::mark_update_redirect_ready($label->cmid);

        $this->assertFalse(return_context::has_pending_update_redirect($label->cmid));
        $this->assertTrue(return_context::has_ready_update_redirect($label->cmid));
    }

    /**
     * New-activity saves redirect through the flow token on the course return URL.
     */
    public function test_create_return_url_carries_flow_token(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'daysections', 'numsections' => 2], ['createsections' => true]);

        $token = return_context::arm(1, $course->id);
        $data = (object) [
            return_context::FLOW_PARAM => $token,
            'add' => 'label',
            'frontend' => true,
            'section' => 1,
            'modulename' => 'label',
        ];

        $url = return_context::get_create_return_url($data, $course);

        $this->assertNotNull($url);
        $this->assertSame($token, $url->get_param(return_context::FLOW_PARAM));
    }

    /**
     * Concurrent create flows for the same course keep distinct return targets.
     */
    public function test_concurrent_create_returns_use_distinct_flow_tokens(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'daysections', 'numsections' => 2], ['createsections' => true]);

        $tokenone = return_context::arm(1, $course->id);
        $tokentwo = return_context::arm(2, $course->id);

        $dataone = (object) [
            return_context::FLOW_PARAM => $tokenone,
            'add' => 'label',
            'frontend' => true,
            'section' => 1,
            'modulename' => 'label',
        ];
        $datatwo = (object) [
            return_context::FLOW_PARAM => $tokentwo,
            'add' => 'label',
            'frontend' => true,
            'section' => 1,
            'modulename' => 'label',
        ];

        $urlone = return_context::get_create_return_url($dataone, $course);
        $urltwo = return_context::get_create_return_url($datatwo, $course);

        $this->assertNotNull($urlone);
        $this->assertNotNull($urltwo);
        $this->assertSame($tokenone, $urlone->get_param(return_context::FLOW_PARAM));
        $this->assertSame($tokentwo, $urltwo->get_param(return_context::FLOW_PARAM));

        $dayone = return_context::consume_for_token($tokenone, (int) $course->id);
        $daytwo = return_context::consume_for_token($tokentwo, (int) $course->id);

        $this->assertNotNull($dayone);
        $this->assertNotNull($daytwo);
        $this->assertSame(1, (int) $dayone->get_param('day'));
        $this->assertSame(2, (int) $daytwo->get_param('day'));
    }
}
