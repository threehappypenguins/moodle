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
 * Tests for homeschool course repository counts.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_homeschool\local\course_repository
 */
final class course_repository_test extends \local_homeschool\base_testcase {

    /**
     * Hidden other-format courses are counted separately from daysections.
     */
    public function test_count_hidden_viewable_other_format_courses(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $teacher = $generator->create_user();
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher']);

        $dayhidden = $generator->create_course(['format' => 'daysections', 'visible' => 0]);
        $otherhidden = $generator->create_course(['format' => 'topics', 'visible' => 0]);
        $generator->enrol_user($teacher->id, $dayhidden->id, $teacherrole->id);
        $generator->enrol_user($teacher->id, $otherhidden->id, $teacherrole->id);

        $this->assertEquals(1, course_repository::count_hidden_viewable_daysections_courses($teacher->id));
        $this->assertEquals(1, course_repository::count_hidden_viewable_other_format_courses($teacher->id));
    }
}
