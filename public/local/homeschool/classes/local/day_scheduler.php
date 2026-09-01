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
     * @param bool $invalidateundo Clear shift undo when a snapshotted activity is changed
     * @return \stdClass result stats
     */
    public static function apply_to_activities(array $cmids, int $timestamp, bool $invalidateundo = true): \stdClass {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');

        $result = (object) [
            'updated' => 0,
            'skipped' => 0,
            'courses' => 0,
        ];

        $cmids = array_values(array_unique(array_map('intval', $cmids)));
        if (empty($cmids)) {
            return $result;
        }

        if ($invalidateundo) {
            shift_undo::invalidate_for_cmids($cmids);
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

            // Timeline reminders require completion tracking (same as core completionexpected).
            if ((int) $cm->completion === COMPLETION_TRACKING_NONE && $timestamp > 0) {
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

    /** @var int Maximum preview rows shown on the shift page. */
    public const SHIFT_PREVIEW_LIMIT = 100;

    /**
     * Build a preview of shifting timeline reminders in a day range.
     *
     * @param \stdClass[] $courses Managed daysections courses
     * @param int $fromday First section number (ignored when $alldays is true)
     * @param int $today Last section number (ignored when $alldays is true)
     * @param int $dayoffset Signed day count (negative = backward)
     * @param bool $alldays Include every day section
     * @return \stdClass Preview with items and skip counts
     */
    public static function preview_shift(
        array $courses,
        int $fromday,
        int $today,
        int $dayoffset,
        bool $alldays,
    ): \stdClass {
        $preview = (object) [
            'items' => [],
            'shiftcount' => 0,
            'skippednodate' => 0,
            'skippednocompletion' => 0,
            'skippedpermission' => 0,
            'skippeddeleted' => 0,
            'dayoffset' => $dayoffset,
            'alldays' => $alldays,
            'fromday' => $fromday,
            'today' => $today,
        ];

        if ($dayoffset === 0) {
            return $preview;
        }

        $offsetsecs = $dayoffset * DAYSECS;

        foreach ($courses as $course) {
            $modinfo = get_fast_modinfo($course->id);
            foreach ($modinfo->get_sections() as $sectionnum => $sectioncmids) {
                if ($sectionnum === 0) {
                    continue;
                }
                if (!$alldays && ($sectionnum < $fromday || $sectionnum > $today)) {
                    continue;
                }

                $sectioninfo = $modinfo->get_section_info($sectionnum);
                $sectionname = get_section_name($course, $sectioninfo);

                foreach ($sectioncmids as $cmid) {
                    $cm = $modinfo->get_cm($cmid);
                    if ($cm->deletioninprogress) {
                        $preview->skippeddeleted++;
                        continue;
                    }

                    $context = \context_module::instance($cm->id);
                    if (!has_capability('moodle/course:manageactivities', $context)) {
                        $preview->skippedpermission++;
                        continue;
                    }

                    if ((int) $cm->completion === COMPLETION_TRACKING_NONE) {
                        $preview->skippednocompletion++;
                        continue;
                    }

                    if (empty($cm->completionexpected)) {
                        $preview->skippednodate++;
                        continue;
                    }

                    $oldtimestamp = (int) $cm->completionexpected;
                    $newtimestamp = $oldtimestamp + $offsetsecs;

                    $preview->items[] = (object) [
                        'cmid' => (int) $cm->id,
                        'coursename' => $course->fullname,
                        'activityname' => $cm->name,
                        'sectionnum' => (int) $sectionnum,
                        'sectionname' => $sectionname,
                        'oldtimestamp' => $oldtimestamp,
                        'newtimestamp' => $newtimestamp,
                        'olddateformatted' => activity_repository::format_expected_date($oldtimestamp),
                        'newdateformatted' => activity_repository::format_expected_date($newtimestamp),
                    ];
                    $preview->shiftcount++;
                }
            }
        }

        usort($preview->items, static function($a, $b) {
            return [$a->sectionnum, $a->coursename, $a->activityname]
                <=> [$b->sectionnum, $b->coursename, $b->activityname];
        });

        return $preview;
    }

    /**
     * Shift timeline reminder dates by a signed day offset.
     *
     * @param int[] $cmids
     * @param int $dayoffset Signed day count (negative = backward)
     * @return \stdClass updated, skipped, snapshots (cmid + original timestamp)
     */
    public static function shift_by_offset(array $cmids, int $dayoffset): \stdClass {
        $result = (object) [
            'updated' => 0,
            'skipped' => 0,
            'snapshots' => [],
        ];

        if ($dayoffset === 0 || empty($cmids)) {
            return $result;
        }

        $offsetsecs = $dayoffset * DAYSECS;
        $cmids = array_values(array_unique(array_map('intval', $cmids)));

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

            if ((int) $cm->completion === COMPLETION_TRACKING_NONE) {
                $result->skipped++;
                continue;
            }

            if (empty($cm->completionexpected)) {
                $result->skipped++;
                continue;
            }

            $oldtimestamp = (int) $cm->completionexpected;
            $newtimestamp = $oldtimestamp + $offsetsecs;

            $apply = self::apply_to_activities([$cmid], $newtimestamp, false);
            if ($apply->updated > 0) {
                $result->snapshots[] = (object) [
                    'cmid' => $cmid,
                    'timestamp' => $oldtimestamp,
                ];
                $result->updated++;
            } else {
                $result->skipped++;
            }
        }

        return $result;
    }
}
