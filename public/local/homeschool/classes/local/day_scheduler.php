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
     * @param array $requiredoldbycmid Optional map of cmid => completionexpected that must still match at write time
     * @return \stdClass result stats (updated, skipped, skippedchanged, courses, updatedcmids)
     */
    public static function apply_timestamps(
        array $timestampsbycmid,
        bool $invalidateundo = true,
        array $requiredoldbycmid = [],
    ): \stdClass {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');

        $result = (object) [
            'updated' => 0,
            'skipped' => 0,
            'skippedchanged' => 0,
            'courses' => 0,
            'updatedcmids' => [],
        ];

        if (empty($timestampsbycmid)) {
            return $result;
        }

        $conditional = !empty($requiredoldbycmid);
        $transaction = null;
        if ($conditional) {
            $transaction = $DB->start_delegated_transaction();
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
            if (!self::can_modify_activity_schedule($cm)) {
                $result->skipped++;
                continue;
            }

            $modinfo = get_fast_modinfo($cm->course);
            $cminfo = $modinfo->get_cm($cm->id);

            if (!$cminfo->uservisible) {
                $result->skipped++;
                continue;
            }

            // Timeline reminders require completion enabled for the activity (same as core).
            if ($timestamp > 0 && !self::completion_expected_allowed($cminfo)) {
                $result->skipped++;
                continue;
            }

            $expected = $timestamp ?: 0;
            if ($conditional && array_key_exists($cmid, $requiredoldbycmid)) {
                $oldexpected = (int) $requiredoldbycmid[$cmid];
                if (!self::compare_and_swap_completionexpected($cm->id, $oldexpected, $expected)) {
                    $result->skippedchanged++;
                    continue;
                }
            } else {
                $DB->set_field('course_modules', 'completionexpected', $expected, ['id' => $cm->id]);
            }

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

        if ($transaction) {
            $transaction->allow_commit();
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
                    if (!self::can_modify_activity_schedule($cm)) {
                        $preview->skippedpermission++;
                        continue;
                    }

                    if (!$cm->uservisible) {
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
            if (!self::can_modify_activity_schedule($cm)) {
                $result->skipped++;
                continue;
            }

            $modinfo = get_fast_modinfo($cm->course);
            $cminfo = $modinfo->get_cm($cm->id);

            if (!$cminfo->uservisible) {
                $result->skipped++;
                continue;
            }

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
     * Apply a stored preview snapshot, shifting only rows whose reminder still matches preview.
     *
     * Each item must include cmid, oldtimestamp, and newtimestamp from preview_shift().
     * Rows whose completionexpected differs from oldtimestamp at write time are skipped as changed.
     *
     * @param \stdClass[] $items Preview snapshot rows
     * @return \stdClass updated, skipped, skippedchanged, snapshots (for undo)
     */
    public static function apply_shift_snapshot(array $items): \stdClass {
        $result = (object) [
            'updated' => 0,
            'skipped' => 0,
            'skippedchanged' => 0,
            'snapshots' => [],
        ];

        if (empty($items)) {
            return $result;
        }

        $timestampsbycmid = [];
        $oldtimestamps = [];

        foreach ($items as $item) {
            $cmid = (int) ($item->cmid ?? 0);
            $oldexpected = (int) ($item->oldtimestamp ?? 0);
            $newexpected = (int) ($item->newtimestamp ?? 0);

            if ($cmid < 1 || $oldexpected < 1 || $newexpected < 1) {
                $result->skipped++;
                continue;
            }

            $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
            if (!$cm || !empty($cm->deletioninprogress)) {
                $result->skipped++;
                continue;
            }

            $context = \context_module::instance($cm->id);
            if (!self::can_modify_activity_schedule($cm)) {
                $result->skipped++;
                continue;
            }

            $modinfo = get_fast_modinfo($cm->course);
            $cminfo = $modinfo->get_cm($cm->id);

            if (!$cminfo->uservisible) {
                $result->skipped++;
                continue;
            }

            if (!self::completion_expected_allowed($cminfo)) {
                $result->skipped++;
                continue;
            }

            $oldtimestamps[$cmid] = $oldexpected;
            $timestampsbycmid[$cmid] = $newexpected;
        }

        $apply = self::apply_timestamps($timestampsbycmid, false, $oldtimestamps);
        $result->skipped += $apply->skipped;
        $result->skippedchanged = $apply->skippedchanged;
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

    /**
     * Atomically replace completionexpected when the stored value still matches the preview.
     *
     * Uses a per-activity lock and a conditional DML update so the check-and-set is portable
     * across Moodle-supported databases (including SQL Server).
     *
     * @param int $cmid
     * @param int $oldexpected Value that must still be stored
     * @param int $expected Value to write
     * @return bool True only when the row still had $oldexpected and was updated
     */
    protected static function compare_and_swap_completionexpected(int $cmid, int $oldexpected, int $expected): bool {
        global $DB;

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_homeschool_completionexpected');
        $lock = $lockfactory->get_lock('cm_' . $cmid, 10);
        if (!$lock) {
            return false;
        }

        try {
            if (!$DB->record_exists('course_modules', [
                'id' => $cmid,
                'completionexpected' => $oldexpected,
            ])) {
                return false;
            }

            $DB->set_field_select(
                'course_modules',
                'completionexpected',
                $expected,
                'id = ? AND completionexpected = ?',
                [$cmid, $oldexpected],
            );

            return $DB->record_exists('course_modules', [
                'id' => $cmid,
                'completionexpected' => $expected,
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether the current user may change Homeschool schedule data in a course.
     *
     * @param int $courseid
     * @return bool
     */
    protected static function can_manage_course_schedule(int $courseid): bool {
        global $USER;

        return requirements::user_has_active_enrolment_in_course(
            (int) $USER->id,
            $courseid,
            'local/homeschool:manage',
        );
    }

    /**
     * Whether the current user may update timeline reminders for an activity.
     *
     * @param object $cm Course-module with id and course (stdClass or cm_info)
     * @return bool
     */
    protected static function can_modify_activity_schedule(object $cm): bool {
        $context = \context_module::instance($cm->id);

        return has_capability('moodle/course:manageactivities', $context)
            && self::can_manage_course_schedule((int) $cm->course);
    }
}
