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
 * Homeschool courses the current user may view or manage.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_repository {

    /**
     * Daysections courses the user may view on the dashboard.
     *
     * @param int $userid
     * @param bool $includehidden Include courses hidden from students when the user also has moodle/course:viewhiddencourses
     * @return \stdClass[] keyed by course id (includes ->visible)
     */
    public static function get_viewable_daysections_courses(int $userid, bool $includehidden = false): array {
        return self::get_homeschool_courses($userid, 'local/homeschool:view', false, $includehidden, true);
    }

    /**
     * Non-daysections courses the user may view on the dashboard.
     *
     * @param int $userid
     * @param bool $includehidden
     * @return \stdClass[] keyed by course id
     */
    public static function get_viewable_other_format_courses(int $userid, bool $includehidden = false): array {
        return self::get_homeschool_courses($userid, 'local/homeschool:view', false, $includehidden, false);
    }

    /**
     * Daysections courses the user may manage through Homeschool scheduling pages.
     *
     * @param int $userid
     * @param bool $includehidden Include courses hidden from students when the user also has moodle/course:viewhiddencourses
     * @return \stdClass[] keyed by course id (includes ->visible)
     */
    public static function get_managed_daysections_courses(int $userid, bool $includehidden = false): array {
        return self::get_homeschool_courses($userid, 'local/homeschool:manage', true, $includehidden, true);
    }

    /**
     * Managed courses that do not use the daysections format.
     *
     * @param int $userid
     * @param bool $includehidden
     * @return \stdClass[] keyed by course id
     */
    public static function get_managed_other_format_courses(int $userid, bool $includehidden = false): array {
        return self::get_homeschool_courses($userid, 'local/homeschool:manage', true, $includehidden, false);
    }

    /**
     * Count of hidden daysections courses the user may view.
     *
     * @param int $userid
     * @return int
     */
    public static function count_hidden_viewable_daysections_courses(int $userid): int {
        return count(self::get_viewable_daysections_courses($userid, true))
            - count(self::get_viewable_daysections_courses($userid, false));
    }

    /**
     * Count of hidden non-daysections courses the user may view.
     *
     * @param int $userid
     * @return int
     */
    public static function count_hidden_viewable_other_format_courses(int $userid): int {
        return count(self::get_viewable_other_format_courses($userid, true))
            - count(self::get_viewable_other_format_courses($userid, false));
    }

    /**
     * Count of viewable courses not using daysections.
     *
     * @param int $userid
     * @param bool $includehidden
     * @return int
     */
    public static function count_viewable_other_format_courses(int $userid, bool $includehidden = false): int {
        return count(self::get_viewable_other_format_courses($userid, $includehidden));
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
     * @param string $homeschoolcap local/homeschool:view or local/homeschool:manage
     * @param bool $requiremanageactivities Also require moodle/course:manageactivities in the course
     * @param bool $includehidden
     * @param bool $daysectionsonly true = only daysections, false = every other format
     * @return \stdClass[]
     */
    protected static function get_homeschool_courses(
        int $userid,
        string $homeschoolcap,
        bool $requiremanageactivities,
        bool $includehidden,
        bool $daysectionsonly,
    ): array {
        $courses = [];
        foreach (self::get_courses_with_homeschool_capability($userid, $homeschoolcap, $requiremanageactivities) as $courseid => $course) {
            $isdaysections = ($course->format === 'daysections');
            if ($daysectionsonly !== $isdaysections) {
                continue;
            }
            if (!(int) $course->visible) {
                if (!$includehidden || !self::user_can_view_hidden_course($userid, $courseid)) {
                    continue;
                }
            }
            $courses[$courseid] = $course;
        }

        return $courses;
    }

    /**
     * Whether the user may see a course hidden from students in Homeschool.
     *
     * @param int $userid
     * @param int $courseid
     * @return bool
     */
    protected static function user_can_view_hidden_course(int $userid, int $courseid): bool {
        return has_capability(
            'moodle/course:viewhiddencourses',
            \context_course::instance($courseid),
            $userid,
        );
    }

    /**
     * Courses where the user holds a Homeschool capability with active enrolment.
     *
     * Cached per request so dashboard counts/lists do not repeat the capability scan.
     *
     * @param int $userid
     * @param string $homeschoolcap
     * @param bool $requiremanageactivities
     * @return \stdClass[] keyed by course id
     */
    protected static function get_courses_with_homeschool_capability(
        int $userid,
        string $homeschoolcap,
        bool $requiremanageactivities,
    ): array {
        static $cache = [];

        $cachekey = $userid . ':' . $homeschoolcap . ':' . (int) $requiremanageactivities;
        if (array_key_exists($cachekey, $cache)) {
            return $cache[$cachekey];
        }

        $courses = get_user_capability_course(
            $homeschoolcap,
            $userid,
            true,
            'fullname, shortname, format, visible',
            'fullname ASC',
        );

        $filtered = [];
        if (!empty($courses)) {
            foreach ($courses as $course) {
                $courseid = (int) $course->id;
                if ($courseid === (int) SITEID) {
                    continue;
                }
                if (!requirements::user_has_active_enrolment_in_course($userid, $courseid, $homeschoolcap)) {
                    continue;
                }
                if ($requiremanageactivities &&
                        !requirements::user_has_active_enrolment_in_course(
                            $userid,
                            $courseid,
                            'moodle/course:manageactivities',
                        )) {
                    continue;
                }
                $filtered[$courseid] = $course;
            }
        }

        $cache[$cachekey] = $filtered;
        return $filtered;
    }
}
