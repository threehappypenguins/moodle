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

require_once($CFG->dirroot . '/course/modlib.php');
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
     * Save and display discards the flow and leaves core's activity URL unchanged.
     */
    public function test_extend_modedit_return_url_discards_save_and_display(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'daysections', 'numsections' => 2], ['createsections' => true]);
        $label = $generator->create_module('label', ['course' => $course->id]);

        $token = return_context::arm(1, $course->id);
        $coreurl = new \moodle_url('/mod/label/view.php', ['id' => $label->cmid]);
        $fromform = (object) [
            return_context::FLOW_PARAM => $token,
            'frontend' => true,
            'section' => 1,
            'coursemodule' => $label->cmid,
            'modulename' => 'label',
            'submitbutton' => 'Save and display',
        ];

        $url = return_context::extend_modedit_return_url($coreurl, $fromform, $course);

        $this->assertNull($url->get_param(return_context::FLOW_PARAM));
        $this->assertFalse(return_context::has_pending());
    }

    /**
     * Core's post-save redirect carries the flow token through plugin_extend_modedit_return_url.
     */
    public function test_plugin_extend_modedit_return_url_attaches_homeschool_token(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'daysections', 'numsections' => 2], ['createsections' => true]);
        $label = $generator->create_module('label', ['course' => $course->id]);

        $token = return_context::arm(2, $course->id);
        $coreurl = course_get_url($course, 1);
        $coreurl->set_anchor('module-' . $label->cmid);
        $fromform = (object) [
            return_context::FLOW_PARAM => $token,
            'frontend' => true,
            'section' => 1,
            'coursemodule' => $label->cmid,
            'modulename' => 'label',
        ];

        $landing = plugin_extend_modedit_return_url($coreurl, $fromform, $course);

        $this->assertSame($token, $landing->get_param(return_context::FLOW_PARAM));
    }

    /**
     * Update save: modedit redirect URL -> course landing -> Homeschool day page.
     */
    public function test_modedit_update_save_return_chain_reaches_day_page(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'daysections', 'numsections' => 2], ['createsections' => true]);
        $label = $generator->create_module('label', ['course' => $course->id]);

        $token = return_context::arm(2, $course->id);
        $fromform = (object) [
            return_context::FLOW_PARAM => $token,
            'frontend' => true,
            'section' => 1,
            'coursemodule' => $label->cmid,
            'modulename' => 'label',
        ];

        $landing = $this->simulate_modedit_save_return($fromform, $course);
        $this->assertSame($token, $landing->get_param(return_context::FLOW_PARAM));

        $dayurl = $this->simulate_course_landing($landing, (int) $course->id);
        $this->assertNotNull($dayurl);
        $this->assertSame(2, (int) $dayurl->get_param('day'));
        $this->assertFalse(return_context::has_pending());
    }

    /**
     * Create save: modedit redirect URL -> course landing -> Homeschool day page.
     */
    public function test_modedit_create_save_return_chain_reaches_day_page(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'daysections', 'numsections' => 2], ['createsections' => true]);

        $token = return_context::arm(3, $course->id, true, true);
        $fromform = (object) [
            return_context::FLOW_PARAM => $token,
            'add' => 'label',
            'frontend' => true,
            'section' => 1,
            'modulename' => 'label',
            'coursemodule' => 12345,
        ];

        $landing = $this->simulate_modedit_save_return($fromform, $course);
        $this->assertSame($token, $landing->get_param(return_context::FLOW_PARAM));

        $dayurl = $this->simulate_course_landing($landing, (int) $course->id);
        $this->assertNotNull($dayurl);
        $this->assertSame(3, (int) $dayurl->get_param('day'));
        $this->assertSame('1', (string) $dayurl->get_param('showall'));
        $this->assertSame('1', (string) $dayurl->get_param('showhidden'));
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
            'coursemodule' => 1001,
        ];
        $datatwo = (object) [
            return_context::FLOW_PARAM => $tokentwo,
            'add' => 'label',
            'frontend' => true,
            'section' => 1,
            'modulename' => 'label',
            'coursemodule' => 1002,
        ];

        $urlone = $this->simulate_modedit_save_return($dataone, $course);
        $urltwo = $this->simulate_modedit_save_return($datatwo, $course);

        $this->assertSame($tokenone, $urlone->get_param(return_context::FLOW_PARAM));
        $this->assertSame($tokentwo, $urltwo->get_param(return_context::FLOW_PARAM));

        $dayone = $this->simulate_course_landing($urlone, (int) $course->id);
        $daytwo = $this->simulate_course_landing($urltwo, (int) $course->id);

        $this->assertNotNull($dayone);
        $this->assertNotNull($daytwo);
        $this->assertSame(1, (int) $dayone->get_param('day'));
        $this->assertSame(2, (int) $daytwo->get_param('day'));
    }

    /**
     * Build the URL core modedit redirects to after save, with plugin extensions applied.
     *
     * @param \stdClass $fromform
     * @param \stdClass $course
     * @return \moodle_url
     */
    private function simulate_modedit_save_return(\stdClass $fromform, \stdClass $course): \moodle_url {
        $url = course_get_url($course, $fromform->section ?? 1);
        if (!empty($fromform->coursemodule)) {
            $url->set_anchor('module-' . $fromform->coursemodule);
        }

        return plugin_extend_modedit_return_url($url, $fromform, $course);
    }

    /**
     * Simulate course/view.php landing and consumption of the Homeschool flow token.
     *
     * @param \moodle_url $landingurl
     * @param int $courseid
     * @return \moodle_url|null
     */
    private function simulate_course_landing(\moodle_url $landingurl, int $courseid): ?\moodle_url {
        $token = $landingurl->get_param(return_context::FLOW_PARAM);
        if ($token === null || $token === '') {
            return null;
        }

        return return_context::consume_for_token((string) $token, $courseid);
    }
}
