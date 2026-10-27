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

namespace format_daysections;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Day sections course format related unit tests.
 *
 * @package    format_daysections
 * @copyright  2026 Sarah
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_daysections::class)]
final class format_daysections_test extends \advanced_testcase {

    /**
     * Tests for format_daysections::get_default_section_name.
     */
    public function test_get_default_section_name(): void {
        $this->resetAfterTest(true);

        set_config('sectionprefix', 'Day', 'format_daysections');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(
            ['numsections' => 3, 'format' => 'daysections'],
            ['createsections' => true],
        );
        $courseformat = course_get_format($course);

        foreach ($courseformat->get_sections() as $section) {
            if ($section->sectionnum == 0) {
                $this->assertEquals(
                    get_string('section0name', 'format_daysections'),
                    $courseformat->get_default_section_name($section),
                );
            } else {
                $this->assertEquals(
                    'Day ' . $section->sectionnum,
                    $courseformat->get_default_section_name($section),
                );
            }
        }
    }

    /**
     * Tests per-course section prefix override.
     */
    public function test_get_default_section_name_course_override(): void {
        $this->resetAfterTest(true);

        set_config('sectionprefix', 'Day', 'format_daysections');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(
            ['numsections' => 2, 'format' => 'daysections', 'sectionprefix' => 'Module'],
            ['createsections' => true],
        );
        $courseformat = course_get_format($course);
        $section = $courseformat->get_section(1);

        $this->assertEquals('Module 1', $courseformat->get_default_section_name($section));
    }

    /**
     * Tests that empty section names display numbered defaults via get_section_name.
     */
    public function test_get_section_name(): void {
        $this->resetAfterTest(true);

        set_config('sectionprefix', 'Day', 'format_daysections');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(
            ['numsections' => 2, 'format' => 'daysections'],
            ['createsections' => true],
        );
        $courseformat = course_get_format($course);

        foreach ($courseformat->get_sections() as $section) {
            if ($section->sectionnum == 0) {
                continue;
            }
            $this->assertEquals(
                $courseformat->get_default_section_name($section),
                $courseformat->get_section_name($section),
            );
        }
    }

    /**
     * Tests get_generic_section_name returns the prefix.
     */
    public function test_get_generic_section_name(): void {
        $this->resetAfterTest(true);

        set_config('sectionprefix', 'Week', 'format_daysections');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'daysections']);
        $courseformat = course_get_format($course);

        $this->assertEquals('Week', $courseformat->get_generic_section_name());
    }

    /**
     * Tests linear navigation uses format_daysections site config, not format_topics.
     *
     * Skipped on Moodle versions without core linear navigation settings (pre-5.3).
     * Moodle 6.0+ (MDL-89406) keeps this as a site-level format setting only.
     */
    public function test_linear_navigation_uses_daysections_site_config(): void {
        $linearnav = \core_courseformat\local\linearnavigationsettings::class;
        if (!class_exists($linearnav) || !method_exists($linearnav, 'is_linear_navigation_enabled')) {
            $this->markTestSkipped('Linear navigation settings require Moodle 5.3+');
        }

        $this->resetAfterTest(true);

        set_config('enablelinearnav', 0, 'format_daysections');
        set_config('enablelinearnav', 1, 'format_topics');

        $generator = $this->getDataGenerator();
        $daycourse = $generator->create_course(['format' => 'daysections']);
        $topicscourse = $generator->create_course(['format' => 'topics']);

        $this->assertFalse($linearnav::is_linear_navigation_enabled($daycourse));
        $this->assertTrue($linearnav::is_linear_navigation_enabled($topicscourse));

        // On Moodle 5.3 only, per-course format options may still expose the default.
        if (method_exists($linearnav, 'get_course_format_options_default')) {
            $dayoptions = course_get_format($daycourse)->course_format_options();
            $topicsoptions = course_get_format($topicscourse)->course_format_options();
            $this->assertEquals(0, $dayoptions['enablelinearnav']['default']);
            $this->assertEquals(1, $topicsoptions['enablelinearnav']['default']);
        } else {
            $dayoptions = course_get_format($daycourse)->course_format_options();
            $this->assertArrayNotHasKey('enablelinearnav', $dayoptions);
        }
    }

    /**
     * Test callback updating section name.
     */
    public function test_inplace_editable(): void {
        global $DB, $PAGE;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(
            ['numsections' => 5, 'format' => 'daysections'],
            ['createsections' => true],
        );
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $teacherrole->id);
        $this->setUser($user);

        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 2]);

        $tmpl = component_callback('format_daysections', 'inplace_editable', ['sectionname', $section->id, 'Field trip']);
        $this->assertInstanceOf('core\output\inplace_editable', $tmpl);
        $res = $tmpl->export_for_template($PAGE->get_renderer('core'));
        $this->assertEquals('Field trip', $res['value']);
        $this->assertEquals('Field trip', $DB->get_field('course_sections', 'name', ['id' => $section->id]));

        try {
            component_callback('format_topics', 'inplace_editable', ['sectionname', $section->id, 'New name']);
            $this->fail('Exception expected');
        } catch (\moodle_exception $e) {
            $this->assertEquals(1, preg_match('/^Can\'t find data record in database/', $e->getMessage()));
        }
    }
}
