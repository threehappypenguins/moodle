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
 * Day-sections courses the current user can manage.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_repository {

    /**
     * Courses using daysections that the user can manage activities in.
     *
     * @param int $userid
     * @param bool $includehidden Include courses that are hidden from students
     * @return \stdClass[] keyed by course id (includes ->visible)
     */
    public static function get_managed_daysections_courses(int $userid, bool $includehidden = false): array {
        return self::get_managed_courses($userid, $includehidden, true);
    }

    /**
     * Managed courses that do not use the daysections format.
     *
     * @param int $userid
     * @param bool $includehidden
     * @return \stdClass[] keyed by course id
     */
    public static function get_managed_other_format_courses(int $userid, bool $includehidden = false): array {
        return self::get_managed_courses($userid, $includehidden, false);
    }

    /**
     * Count of hidden daysections courses the user can manage.
     *
     * @param int $userid
     * @return int
     */
    public static function count_hidden_managed_daysections_courses(int $userid): int {
        return count(self::get_managed_daysections_courses($userid, true))
            - count(self::get_managed_daysections_courses($userid, false));
    }

    /**
     * Count of managed courses not using daysections.
     *
     * @param int $userid
     * @param bool $includehidden
     * @return int
     */
    public static function count_managed_other_format_courses(int $userid, bool $includehidden = false): int {
        return count(self::get_managed_other_format_courses($userid, $includehidden));
    }

    /**
     * Human-readable course format name.
     *
     * @param string $format
     * @return string
     */
    public static function get_format_display_name(string $format): string {
        $component = 'format_' . $format;
        if (get_string_manager()->string_exists('pluginname', $component)) {
            return get_string('pluginname', $component);
        }
        return $format;
    }

    /**
     * Maximum section number across managed courses (excluding section 0).
     *
     * @param \stdClass[] $courses
     * @return int
     */
    public static function get_max_day_number(array $courses): int {
        global $DB;

        if (empty($courses)) {
            return 0;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($courses), SQL_PARAMS_NAMED);
        $params['zerosection'] = 0;

        $max = $DB->get_field_sql(
            "SELECT MAX(section) FROM {course_sections} WHERE course $insql AND section > :zerosection",
            $params,
        );

        return (int) $max;
    }

    /**
     * @param int $userid
     * @param bool $includehidden
     * @param bool $daysectionsonly true = only daysections, false = every other format
     * @return \stdClass[]
     */
    protected static function get_managed_courses(int $userid, bool $includehidden, bool $daysectionsonly): array {
        $managed = [];
        foreach (self::get_manageable_courses($userid) as $courseid => $course) {
            $isdaysections = ($course->format === 'daysections');
            if ($daysectionsonly !== $isdaysections) {
                continue;
            }
            if (!$includehidden && !(int) $course->visible) {
                continue;
            }
            $managed[$courseid] = $course;
        }

        return $managed;
    }

    /**
     * Courses where the user can manage activities (capability-filtered, then site course removed).
     *
     * Cached per request so dashboard counts/lists do not repeat the capability scan.
     *
     * @param int $userid
     * @return \stdClass[] keyed by course id
     */
    protected static function get_manageable_courses(int $userid): array {
        static $cache = [];

        if (array_key_exists($userid, $cache)) {
            return $cache[$userid];
        }

        $courses = get_user_capability_course(
            'moodle/course:manageactivities',
            $userid,
            true,
            'fullname, shortname, format, visible',
            'fullname ASC',
        );

        $managed = [];
        if (!empty($courses)) {
            foreach ($courses as $course) {
                if ((int) $course->id === (int) SITEID) {
                    continue;
                }
                $managed[(int) $course->id] = $course;
            }
        }

        $cache[$userid] = $managed;
        return $managed;
    }
}
