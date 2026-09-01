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

/**
 * Students enrolled in homeschool courses.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student_repository {

    /**
     * Student role shortname used to identify children.
     *
     * @return string
     */
    public static function get_student_role_shortname(): string {
        $configured = get_config('local_homeschool', 'studentrole');
        if (!empty($configured)) {
            return $configured;
        }
        return 'student';
    }

    /**
     * Whether child names should include surname (Moodle lastname field).
     *
     * @return bool
     */
    public static function shows_child_surname(): bool {
        return (bool) get_config('local_homeschool', 'showchildsurname');
    }

    /**
     * Format a child's name for Homeschool dashboard and day page display.
     *
     * @param \stdClass $user User record with at least name fields loaded
     * @return string
     */
    public static function format_child_name(\stdClass $user): string {
        if (self::shows_child_surname()) {
            return fullname($user);
        }

        if (!empty($user->firstname)) {
            return $user->firstname;
        }

        return fullname($user);
    }

    /**
     * Students enrolled in the given courses, keyed by user id.
     *
     * Only users who are enrolled AND have the configured student role in the
     * course context are returned. Teachers and other enrolled roles are excluded.
     *
     * @param \stdClass[] $courses
     * @return \stdClass[] user records with courseids array attached
     */
    public static function get_students_for_courses(array $courses): array {
        global $DB;

        if (empty($courses)) {
            return [];
        }

        $role = $DB->get_record('role', ['shortname' => self::get_student_role_shortname()]);
        if (!$role) {
            return [];
        }

        // fullname() requires all name fields, not just firstname/lastname.
        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

        $students = [];
        foreach ($courses as $course) {
            $context = \context_course::instance($course->id);
            [$esql, $params] = get_enrolled_sql($context, '', 0, true);
            $params['roleid'] = $role->id;
            $params['contextid'] = $context->id;

            $sql = "SELECT u.id, {$userfields}
                      FROM {user} u
                      JOIN ({$esql}) je ON je.id = u.id
                      JOIN {role_assignments} ra ON ra.userid = u.id
                           AND ra.roleid = :roleid
                           AND ra.contextid = :contextid
                     WHERE u.deleted = 0
                  ORDER BY u.lastname ASC, u.firstname ASC";

            $enrolled = $DB->get_records_sql($sql, $params);
            foreach ($enrolled as $user) {
                if (!isset($students[$user->id])) {
                    $user->courseids = [];
                    $students[$user->id] = $user;
                }
                $students[$user->id]->courseids[$course->id] = $course->id;
            }
        }

        return $students;
    }
}
