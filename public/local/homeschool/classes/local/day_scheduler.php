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
     * Whether a timeline reminder date may be set for this activity.
     *
     * Matches core behaviour: completion must be enabled at site/course level and
     * the activity must use manual or automatic tracking.
     *
     * @param \cm_info $cm
     * @return bool
     */
    public static function completion_expected_allowed(\cm_info $cm): bool {
        $completioninfo = new \completion_info($cm->get_course());
        return (bool) $completioninfo->is_enabled($cm);
    }

    /**
     * Set completion expected date for specific course modules.
     *
     * @param int[] $cmids
     * @param int $timestamp Unix timestamp (0 clears the date)
     * @param bool $invalidateundo Clear shift undo when a snapshotted activity is changed
     * @return \stdClass result stats
     */
    public static function apply_to_activities(array $cmids, int $timestamp, bool $invalidateundo = true): \stdClass {
        $timestampsbycmid = [];
        foreach (array_unique(array_map('intval', $cmids)) as $cmid) {
            if ($cmid > 0) {
                $timestampsbycmid[$cmid] = $timestamp;
            }
        }

        return self::apply_timestamps($timestampsbycmid, $invalidateundo);
    }

    /**
     * Set completion expected dates for modules with per-cm timestamps.
     *
     * Updates fields and calendar events first, then rebuilds each touched course once.
     *
     * @param array $timestampsbycmid Map of cmid => unix timestamp (0 clears the date)
     * @param bool $invalidateundo Clear shift undo when a snapshotted activity is changed
     * @return \stdClass result stats (updated, skipped, courses, updatedcmids)
     */
    public static function apply_timestamps(array $timestampsbycmid, bool $invalidateundo = true): \stdClass {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');

        $result = (object) [
            'updated' => 0,
            'skipped' => 0,
            'courses' => 0,
            'updatedcmids' => [],
        ];

        if (empty($timestampsbycmid)) {
            return $result;
        }

        $cmids = array_map('intval', array_keys($timestampsbycmid));
        if ($invalidateundo) {
            shift_undo::invalidate_for_cmids($cmids);
        }

        $touchedcourses = [];
        $updatedcms = [];

        foreach ($timestampsbycmid as $cmid => $timestamp) {
            $cmid = (int) $cmid;
            $timestamp = (int) $timestamp;

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

            $modinfo = get_fast_modinfo($cm->course);
            $cminfo = $modinfo->get_cm($cm->id);

            // Timeline reminders require completion enabled for the activity (same as core).
            if ($timestamp > 0 && !self::completion_expected_allowed($cminfo)) {
                $result->skipped++;
                continue;
            }

            $expected = $timestamp ?: 0;
            $DB->update_record('course_modules', (object) [
                'id' => $cm->id,
                'completionexpected' => $expected,
                'timemodified' => time(),
            ]);

            $calendartime = $expected ?: null;
            \core_completion\api::update_completion_date_event(
                $cm->id,
                $cm->modname,
                $cm->instance,
                $calendartime,
            );

            $result->updated++;
            $result->updatedcmids[] = $cmid;
            $touchedcourses[(int) $cm->course] = true;
            $updatedcms[] = (object) [
                'cm' => $cm,
                'context' => $context,
            ];
        }

        foreach (array_keys($touchedcourses) as $courseid) {
            rebuild_course_cache($courseid, true);
            $result->courses++;
        }

        // Match core completion updates: emit course_module_updated after modinfo refresh.
        foreach ($updatedcms as $item) {
            $modinfo = get_fast_modinfo($item->cm->course);
            $cminfo = $modinfo->get_cm($item->cm->id);
            \core\event\course_module_updated::create_from_cm($cminfo, $item->context)->trigger();
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
                if (!$includewithoutcompletion && !self::completion_expected_allowed($cm)) {
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

                    if (!self::completion_expected_allowed($cm)) {
                        $preview->skippednocompletion++;
                        continue;
                    }

                    if (empty($cm->completionexpected)) {
                        $preview->skippednodate++;
                        continue;
                    }

                    $oldtimestamp = (int) $cm->completionexpected;
                    $newtimestamp = self::shift_timestamp_by_days($oldtimestamp, $dayoffset);

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
     * @return \stdClass updated, skipped, snapshots (cmid, timestamp, shifted)
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

        $cmids = array_values(array_unique(array_map('intval', $cmids)));
        $timestampsbycmid = [];
        $oldtimestamps = [];

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

            $modinfo = get_fast_modinfo($cm->course);
            $cminfo = $modinfo->get_cm($cm->id);

            if (!self::completion_expected_allowed($cminfo)) {
                $result->skipped++;
                continue;
            }

            if (empty($cm->completionexpected)) {
                $result->skipped++;
                continue;
            }

            $oldtimestamp = (int) $cm->completionexpected;
            $oldtimestamps[$cmid] = $oldtimestamp;
            $timestampsbycmid[$cmid] = self::shift_timestamp_by_days($oldtimestamp, $dayoffset);
        }

        $apply = self::apply_timestamps($timestampsbycmid, false);
        $result->skipped += $apply->skipped;
        $result->updated = $apply->updated;

        foreach ($apply->updatedcmids as $cmid) {
            $result->snapshots[] = (object) [
                'cmid' => $cmid,
                'timestamp' => $oldtimestamps[$cmid],
                'shifted' => $timestampsbycmid[$cmid],
            ];
        }

        return $result;
    }

    /**
     * Shift a unix timestamp by a signed number of calendar days in the user's timezone.
     *
     * Preserves local wall-clock time across DST transitions (unlike DAYSECS arithmetic).
     *
     * @param int $timestamp
     * @param int $dayoffset Signed calendar day count
     * @return int
     */
    protected static function shift_timestamp_by_days(int $timestamp, int $dayoffset): int {
        if ($dayoffset === 0) {
            return $timestamp;
        }

        $date = new \DateTime('@' . $timestamp);
        $date->setTimezone(\core_date::get_user_timezone_object());
        $date->modify(sprintf('%+d day', $dayoffset));

        return $date->getTimestamp();
    }
}
