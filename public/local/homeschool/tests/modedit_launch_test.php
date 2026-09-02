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
 * Tests for modedit launch URL normalization.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_homeschool\local\modedit_launch
 */
final class modedit_launch_test extends \local_homeschool\base_testcase {

    /**
     * Chooser add links rewrite mod.php to modedit.php and keep the flow token.
     */
    public function test_mod_php_add_rewrites_to_modedit_preserving_flow_token(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'daysections', 'numsections' => 2], ['createsections' => true]);
        $section = get_fast_modinfo($course->id)->get_section_info(1);

        $token = return_context::arm(2, (int) $course->id);
        $chooserurl = new \moodle_url('/course/mod.php', [
            'id' => $course->id,
            'add' => 'label',
            'sectionid' => $section->id,
            'beforemod' => 0,
            'returnoptions' => ['pagesectionid' => $section->id],
            return_context::FLOW_PARAM => $token,
        ]);

        $launch = modedit_launch::normalize_url($chooserurl);

        $this->assertTrue($launch->compare(new \moodle_url('/course/modedit.php'), URL_MATCH_BASE));
        $this->assertSame('label', $launch->get_param('add'));
        $this->assertSame((string) $course->id, (string) $launch->get_param('course'));
        $this->assertSame((string) $section->id, (string) $launch->get_param('sectionid'));
        $this->assertSame($token, $launch->get_param(return_context::FLOW_PARAM));
    }

    /**
     * Update links rewrite mod.php to modedit.php and keep the flow token.
     */
    public function test_mod_php_update_rewrites_to_modedit_preserving_flow_token(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'daysections', 'numsections' => 2], ['createsections' => true]);
        $label = $generator->create_module('label', ['course' => $course->id]);

        $token = return_context::arm(1, (int) $course->id);
        $chooserurl = new \moodle_url('/course/mod.php', [
            'update' => $label->cmid,
            'return' => 0,
            return_context::FLOW_PARAM => $token,
        ]);

        $launch = modedit_launch::normalize_url($chooserurl);

        $this->assertTrue($launch->compare(new \moodle_url('/course/modedit.php'), URL_MATCH_BASE));
        $this->assertSame((string) $label->cmid, (string) $launch->get_param('update'));
        $this->assertSame($token, $launch->get_param(return_context::FLOW_PARAM));
    }

    /**
     * modedit.php launch URLs pass through unchanged.
     */
    public function test_modedit_url_passes_through_unchanged(): void {
        $url = new \moodle_url('/course/modedit.php', [
            'course' => 5,
            'add' => 'label',
            'section' => 1,
            return_context::FLOW_PARAM => 'abc123',
        ]);

        $launch = modedit_launch::normalize_url($url);

        $this->assertSame($url->out(false), $launch->out(false));
    }

    /**
     * Create flow: chooser mod.php URL -> modedit with token -> save redirect -> day page.
     */
    public function test_create_launch_chain_reaches_day_page(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'daysections', 'numsections' => 2], ['createsections' => true]);
        $section = get_fast_modinfo($course->id)->get_section_info(1);

        $token = return_context::arm(4, (int) $course->id, true, false);
        $chooserurl = new \moodle_url('/course/mod.php', [
            'id' => $course->id,
            'add' => 'label',
            'sectionid' => $section->id,
            return_context::FLOW_PARAM => $token,
        ]);

        $modediturl = modedit_launch::normalize_url($chooserurl);
        $this->assertSame($token, $modediturl->get_param(return_context::FLOW_PARAM));

        $fromform = (object) [
            return_context::FLOW_PARAM => $token,
            'add' => 'label',
            'frontend' => true,
            'section' => 1,
            'modulename' => 'label',
            'coursemodule' => 99999,
        ];
        $landing = course_get_url($course, 1);
        $landing->set_anchor('module-99999');
        $landing = plugin_extend_modedit_return_url($landing, $fromform, $course);

        $this->assertSame($token, $landing->get_param(return_context::FLOW_PARAM));

        $dayurl = return_context::consume_for_token($token, (int) $course->id);
        $this->assertNotNull($dayurl);
        $this->assertSame(4, (int) $dayurl->get_param('day'));
        $this->assertSame('1', (string) $dayurl->get_param('showall'));
    }

    /**
     * Edit launch URLs route through modedit_bridge with a modedit update target.
     */
    public function test_build_edit_launch_url_uses_modedit_bridge(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'daysections', 'numsections' => 2], ['createsections' => true]);
        $label = $generator->create_module('label', ['course' => $course->id]);

        $url = modedit_launch::build_edit_launch_url($label->cmid, 2, true, false);

        $this->assertStringContainsString('/local/homeschool/modedit_bridge.php', $url->out(false));
        $this->assertSame('2', (string) $url->get_param('day'));
        $this->assertSame('1', (string) $url->get_param('showall'));

        $goto = new \moodle_url($url->get_param('goto'));
        $this->assertTrue($goto->compare(new \moodle_url('/course/modedit.php'), URL_MATCH_BASE));
        $this->assertSame((string) $label->cmid, (string) $goto->get_param('update'));
    }

    /**
     * purge_expired keeps active armed flows for concurrent modedit tabs.
     */
    public function test_purge_expired_preserves_active_armed_flows(): void {
        $tokenone = return_context::arm(1, 42);
        $tokentwo = return_context::arm(2, 42);

        return_context::purge_expired();

        $this->assertTrue(return_context::has_pending());
        $this->assertNotNull(return_context::consume_for_token($tokenone, 42));
        $this->assertNotNull(return_context::consume_for_token($tokentwo, 42));
    }
}
