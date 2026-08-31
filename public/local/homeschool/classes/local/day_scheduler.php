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
 * Apply timeline reminder dates to selected activities.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class day_scheduler {

    /**
     * Set completion expected date for specific course modules.
     *
     * @param int[] $cmids
     * @param int $timestamp Unix timestamp (0 clears the date)
     * @return \stdClass result stats
     */
    public static function apply_to_activities(array $cmids, int $timestamp): \stdClass {
        global $DB;

        $result = (object) [
            'updated' => 0,
            'skipped' => 0,
            'courses' => 0,
        ];

        $cmids = array_values(array_unique(array_map('intval', $cmids)));
        if (empty($cmids)) {
            return $result;
        }

        $touchedcourses = [];

        foreach ($cmids as $cmid) {
            $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
            if (!$cm || !empty($cm->deletioninprogress)) {
                $result->skipped++;
                continue;
            }

            $context = \context_module::instance($cm->id);
            if (!has_capability('moodle/course:manageactivities', $context)) {
                $result->skipped++;
                continue;
            }

            $expected = $timestamp ?: 0;
            $DB->set_field('course_modules', 'completionexpected', $expected, ['id' => $cm->id]);

            $calendartime = $expected ?: null;
            \core_completion\api::update_completion_date_event(
                $cm->id,
                $cm->modname,
                $cm->instance,
                $calendartime,
            );

            $result->updated++;
            $touchedcourses[(int) $cm->course] = true;
        }

        foreach (array_keys($touchedcourses) as $courseid) {
            rebuild_course_cache($courseid, true);
            $result->courses++;
        }

        return $result;
    }

    /**
     * Set completion expected date for activities in a day section.
     *
     * @param \stdClass[] $courses
     * @param int $daynumber
     * @param int $timestamp Unix timestamp (0 clears the date)
     * @param bool $includewithoutcompletion
     * @return \stdClass result stats
     * @deprecated since 0.3.0 Use apply_to_activities() with an explicit selection instead.
     */
    public static function apply_day_date(
        array $courses,
        int $daynumber,
        int $timestamp,
        bool $includewithoutcompletion = false,
    ): \stdClass {
        $cmids = [];
        foreach ($courses as $course) {
            $modinfo = get_fast_modinfo($course->id);
            $sections = $modinfo->get_sections();
            if (empty($sections[$daynumber])) {
                continue;
            }
            foreach ($sections[$daynumber] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if ($cm->deletioninprogress) {
                    continue;
                }
                if (!$includewithoutcompletion && $cm->completion == COMPLETION_TRACKING_NONE) {
                    continue;
                }
                $cmids[] = $cm->id;
            }
        }

        return self::apply_to_activities($cmids, $timestamp);
    }
}
