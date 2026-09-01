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
 * Upcoming timeline reminders from scheduled completion expected dates.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upcoming_service {

    /** @var int Default days ahead to show. */
    public const DEFAULT_DAYS_AHEAD = 14;

    /**
     * Upcoming and overdue activities with timeline reminders set.
     *
     * @param \stdClass[] $courses
     * @param int $fromtimestamp
     * @param int $totimestamp
     * @return \stdClass[]
     */
    public static function get_upcoming(array $courses, int $fromtimestamp, int $totimestamp): array {
        $items = [];

        foreach ($courses as $course) {
            $modinfo = get_fast_modinfo($course->id);
            foreach ($modinfo->get_cms() as $cm) {
                if ($cm->deletioninprogress || empty($cm->completionexpected)) {
                    continue;
                }
                if ($cm->completion == COMPLETION_TRACKING_NONE) {
                    continue;
                }
                if ($cm->completionexpected < $fromtimestamp || $cm->completionexpected > $totimestamp) {
                    continue;
                }

                $sectionnum = $cm->sectionnum;
                $sectionname = $sectionnum ? get_section_name($course, $modinfo->get_section_info($sectionnum)) : '';

                $items[] = (object) [
                    'cmid' => $cm->id,
                    'courseid' => $course->id,
                    'coursename' => $course->fullname,
                    'activityname' => $cm->name,
                    'modname' => $cm->modname,
                    'sectionnum' => $sectionnum,
                    'sectionname' => $sectionname,
                    'timestamp' => $cm->completionexpected,
                    'dateformatted' => userdate($cm->completionexpected, '%d/%m/%Y'),
                    'overdue' => $cm->completionexpected < time(),
                    'url' => $cm->url ? $cm->url->out(false) : '',
                    'dayurl' => (new \moodle_url('/local/homeschool/day.php', ['day' => $sectionnum]))->out(false),
                ];
            }
        }

        usort($items, function($a, $b) {
            return $a->timestamp <=> $b->timestamp ?: strcmp($a->coursename, $b->coursename);
        });

        return $items;
    }
}
