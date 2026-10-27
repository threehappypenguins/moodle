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
 * Latest assignment submissions that count as qualifying work.
 *
 * Matches assign::get_user_submission() / get_group_submission(): only the
 * current attempt (latest = 1). Team rows (userid = 0) are attributed only to
 * members whose single eligible group (via teamsubmissiongroupingid) is the
 * submitting group, matching assign::get_submission_group().
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_work {

    /**
     * Queries that yield submitted assignments as qualifying work.
     *
     * @param string $courseinsql Named IN() fragment for course ids
     * @param string $userinsql Named IN() fragment for user ids
     * @param array $params Query params for those fragments
     * @param bool $withday If true, select daynumber and require section > 0
     * @return array[] List of [sql, params] pairs
     */
    public static function submitted_queries(
        string $courseinsql,
        string $userinsql,
        array $params,
        bool $withday,
    ): array {
        global $DB;

        if (!$DB->record_exists('modules', ['name' => 'assign'])) {
            return [];
        }

        $sectionjoin = '';
        $sectionwhere = '';
        if ($withday) {
            $sectionjoin = ' JOIN {course_sections} cs ON cs.id = cm.section';
            $sectionwhere = ' AND cs.section > 0';
        }

        $cmjoins = "JOIN {modules} m ON m.name = 'assign'
                    JOIN {course_modules} cm ON cm.instance = a.id
                         AND cm.module = m.id AND cm.course = a.course
                    {$sectionjoin}";
        $cmwhere = "AND cm.deletioninprogress = 0{$sectionwhere}";

        $individualselect = $withday
            ? 'sub.userid, cm.id AS cmid, a.course AS courseid, cs.section AS daynumber'
            : 'sub.userid, cm.id AS cmid, a.course AS courseid';
        $teamselect = $withday
            ? 'gm.userid, cm.id AS cmid, a.course AS courseid, cs.section AS daynumber'
            : 'gm.userid, cm.id AS cmid, a.course AS courseid';

        $submitted = ['assignsubmitted' => 'submitted'];

        return [
            [
                "SELECT {$individualselect}
                   FROM {assign_submission} sub
                   JOIN {assign} a ON a.id = sub.assignment AND a.teamsubmission = 0
                   {$cmjoins}
                  WHERE a.course {$courseinsql}
                    AND sub.userid {$userinsql}
                    AND sub.userid <> 0
                    AND sub.groupid = 0
                    AND sub.latest = 1
                    AND sub.status = :assignsubmitted
                    {$cmwhere}",
                $params + $submitted,
            ],
            [
                "SELECT {$teamselect}
                   FROM {assign_submission} sub
                   JOIN {assign} a ON a.id = sub.assignment AND a.teamsubmission = 1
                   JOIN {groups} g ON g.id = sub.groupid AND g.courseid = a.course
                        AND g.participation = 1
                   JOIN {groups_members} gm ON gm.groupid = g.id
                   {$cmjoins}
                  WHERE a.course {$courseinsql}
                    AND gm.userid {$userinsql}
                    AND sub.userid = 0
                    AND sub.latest = 1
                    AND sub.status = :assignsubmitted
                    AND (a.teamsubmissiongroupingid = 0
                         OR EXISTS (
                             SELECT 1
                               FROM {groupings_groups} gg
                              WHERE gg.groupingid = a.teamsubmissiongroupingid
                                AND gg.groupid = g.id
                         ))
                    AND NOT EXISTS (
                        SELECT 1
                          FROM {groups_members} gm2
                          JOIN {groups} g2 ON g2.id = gm2.groupid
                               AND g2.courseid = a.course
                               AND g2.participation = 1
                         WHERE gm2.userid = gm.userid
                           AND g2.id <> g.id
                           AND (a.teamsubmissiongroupingid = 0
                                OR EXISTS (
                                    SELECT 1
                                      FROM {groupings_groups} gg2
                                     WHERE gg2.groupingid = a.teamsubmissiongroupingid
                                       AND gg2.groupid = g2.id
                                ))
                    )
                    {$cmwhere}",
                $params + $submitted,
            ],
        ];
    }
}
