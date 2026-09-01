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
 * Activity lookup and presentation helpers.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_repository {

    /**
     * Activities in a given day (section number) across courses.
     *
     * @param \stdClass[] $courses
     * @param int $daynumber section number (1 = Day 1)
     * @return \stdClass[]
     */
    public static function get_activities_for_day(array $courses, int $daynumber): array {
        $activities = [];

        foreach ($courses as $course) {
            $modinfo = get_fast_modinfo($course->id);
            $completioninfo = new \completion_info($course);
            $sections = $modinfo->get_sections();
            if (empty($sections[$daynumber])) {
                continue;
            }

            $sectioninfo = $modinfo->get_section_info($daynumber);
            $sectionname = get_section_name($course, $sectioninfo);

            foreach ($sections[$daynumber] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!$cm->uservisible || $cm->deletioninprogress) {
                    continue;
                }

                $conditions = \local_homeschool\local\completion_conditions::get_available($cm);

                $row = (object) [
                    'cmid' => $cm->id,
                    'courseid' => $course->id,
                    'coursename' => $course->fullname,
                    'modname' => $cm->modname,
                    'activityname' => $cm->name,
                    'sectionnum' => $daynumber,
                    'sectionname' => $sectionname,
                    'completion' => $cm->completion,
                    'completionlabel' => self::format_completion_type($cm->completion),
                    'completionexpected' => $cm->completionexpected,
                    'completionexpectedformatted' => self::format_expected_date($cm->completionexpected),
                    'completionexpectediso' => self::format_expected_date_iso($cm->completionexpected),
                    'hasreminderdate' => !empty($cm->completionexpected),
                    'completionlocked' => $completioninfo->count_user_data($cm) > 0,
                    'requirements' => $conditions,
                    'hasrequirements' => !empty($conditions),
                    'activityurl' => ($cm->url ? $cm->url->out(false) : (new \moodle_url('/mod/' . $cm->modname . '/view.php', [
                        'id' => $cm->id,
                    ]))->out(false)),
                    'isassign' => ($cm->modname === 'assign'),
                    'submissions' => $cm->modname === 'assign' ? self::get_assign_submission_types($cm) : [],
                ];
                $activities[] = $row;
            }
        }

        usort($activities, function($a, $b) {
            return [$a->coursename, $a->activityname] <=> [$b->coursename, $b->activityname];
        });

        return $activities;
    }

    /**
     * Human-readable completion tracking type.
     *
     * @param int $completion
     * @return string
     */
    public static function format_completion_type(int $completion): string {
        switch ($completion) {
            case COMPLETION_TRACKING_MANUAL:
                return get_string('completion_manual', 'completion');
            case COMPLETION_TRACKING_AUTOMATIC:
                return get_string('completion_automatic', 'completion');
            default:
                return get_string('completion_none', 'completion');
        }
    }

    /**
     * Format expected completion timestamp for display.
     *
     * @param int $timestamp
     * @return string
     */
    public static function format_expected_date(int $timestamp): string {
        if (empty($timestamp)) {
            return get_string('notset', 'local_homeschool');
        }
        // Full year (YYYY), not two-digit (%y).
        return userdate($timestamp, '%d/%m/%Y');
    }

    /**
     * ISO date (Y-m-d) in the user's timezone for HTML date inputs.
     *
     * @param int $timestamp
     * @return string
     */
    public static function format_expected_date_iso(int $timestamp): string {
        if (empty($timestamp)) {
            return '';
        }
        return userdate($timestamp, '%Y-%m-%d');
    }

    /**
     * Submission plugin options for an assignment activity.
     *
     * @param \cm_info $cm
     * @return \stdClass[]
     */
    public static function get_assign_submission_types(\cm_info $cm): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $context = \context_module::instance($cm->id);
        $course = get_course($cm->course);
        $assign = new \assign($context, $cm, $course);
        $types = [];

        foreach ($assign->get_submission_plugins() as $plugin) {
            if (!$plugin->is_visible() || !$plugin->is_configurable()) {
                continue;
            }
            $types[] = (object) [
                'type' => $plugin->get_type(),
                'name' => $plugin->get_name(),
                'enabled' => (bool) $plugin->is_enabled(),
            ];
        }

        return $types;
    }

    /**
     * Preview counts for scheduling a day across courses.
     *
     * @param \stdClass[] $courses
     * @param int $daynumber
     * @return \stdClass
     */
    public static function preview_day_schedule(array $courses, int $daynumber): \stdClass {
        $preview = (object) [
            'activitycount' => 0,
            'withcompletion' => 0,
            'withoutcompletion' => 0,
            'alreadydated' => 0,
            'coursenames' => [],
        ];

        foreach ($courses as $course) {
            $modinfo = get_fast_modinfo($course->id);
            $sections = $modinfo->get_sections();
            if (empty($sections[$daynumber])) {
                continue;
            }

            $hascms = false;
            foreach ($sections[$daynumber] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if ($cm->deletioninprogress) {
                    continue;
                }
                $hascms = true;
                $preview->activitycount++;
                if ($cm->completion == COMPLETION_TRACKING_NONE) {
                    $preview->withoutcompletion++;
                } else {
                    $preview->withcompletion++;
                }
                if (!empty($cm->completionexpected)) {
                    $preview->alreadydated++;
                }
            }
            if ($hascms) {
                $preview->coursenames[] = $course->fullname;
            }
        }

        return $preview;
    }
}
