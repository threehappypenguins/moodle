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
 * Tests for homeschool student listing.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_homeschool\local\student_repository
 */
final class student_repository_test extends \local_homeschool\base_testcase {

    /**
     * Children stay sorted by display name when a later enrolment would change insertion order.
     */
    public function test_get_students_for_courses_sorts_by_display_name(): void {
        global $DB;

        set_config('showchildsurname', 0, 'local_homeschool');

        $generator = $this->getDataGenerator();
        $teacher = $generator->create_user();
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher']);
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);

        $art = $generator->create_course(['fullname' => 'Art']);
        $math = $generator->create_course(['fullname' => 'Math']);
        $generator->enrol_user($teacher->id, $art->id, $teacherrole->id);
        $generator->enrol_user($teacher->id, $math->id, $teacherrole->id);
        $this->setUser($teacher);

        // Zoe is only in Art (processed first). Adam is only in Math (processed second).
        $zoe = $generator->create_user(['firstname' => 'Zoe', 'lastname' => 'Adams']);
        $adam = $generator->create_user(['firstname' => 'Adam', 'lastname' => 'Zimmerman']);
        $generator->enrol_user($zoe->id, $art->id, $studentrole->id);
        $generator->enrol_user($adam->id, $math->id, $studentrole->id);

        $students = student_repository::get_students_for_courses([$art, $math]);
        $this->assertEquals([(int) $adam->id, (int) $zoe->id], array_map('intval', array_keys($students)));

        // Enrolling Zoe in Math used to move her later in the merged list.
        $generator->enrol_user($zoe->id, $math->id, $studentrole->id);
        $students = student_repository::get_students_for_courses([$art, $math]);
        $this->assertEquals([(int) $adam->id, (int) $zoe->id], array_map('intval', array_keys($students)));
    }
}
