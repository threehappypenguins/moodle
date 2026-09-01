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

namespace local_homeschool\output;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/local/homeschool/tests/base_testcase.php');

/**
 * Tests for day page completion option export.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_homeschool\output\day_page
 */
final class day_page_completion_test extends \local_homeschool\base_testcase {

    /**
     * @param \stdClass $activity
     * @return \stdClass[]
     */
    protected function get_completion_options(\stdClass $activity): array {
        $method = new \ReflectionMethod(day_page::class, 'build_completion_options');
        $method->setAccessible(true);
        return $method->invoke(null, $activity);
    }

    /**
     * Activities without completion requirements must not offer automatic tracking.
     */
    public function test_build_completion_options_without_requirements(): void {
        $activity = (object) [
            'completion' => COMPLETION_TRACKING_NONE,
            'hasrequirements' => false,
        ];

        $options = $this->get_completion_options($activity);
        $values = array_map(static fn($option) => (int) $option->value, $options);

        $this->assertSame([COMPLETION_TRACKING_NONE, COMPLETION_TRACKING_MANUAL], $values);
    }

    /**
     * Activities with completion requirements include automatic tracking.
     */
    public function test_build_completion_options_with_requirements(): void {
        $activity = (object) [
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'hasrequirements' => true,
        ];

        $options = $this->get_completion_options($activity);
        $values = array_map(static fn($option) => (int) $option->value, $options);

        $this->assertSame(
            [COMPLETION_TRACKING_NONE, COMPLETION_TRACKING_MANUAL, COMPLETION_TRACKING_AUTOMATIC],
            $values,
        );
    }
}
