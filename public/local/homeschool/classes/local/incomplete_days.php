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

require_once($GLOBALS['CFG']->libdir . '/completionlib.php');

/**
 * Days with leftover due work for each child.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class incomplete_days {

    /**
     * Leftover due activities grouped by child and day.
     *
     * A leftover is a trackable activity (completion tracking, assignment, or
     * quiz) with a reminder date of today or earlier, and no qualifying work
     * (complete/pass, submitted assignment, or finished quiz). Section 0 is
     * ignored. Only activities in courses attached to each student are counted.
     *
     * Days are sorted by the earliest leftover reminder, oldest first.
     *
     * @param \stdClass[] $students userid-keyed records with courseids
     * @return array userid => list of objects {day, leftovercount, sorttime}
     */
    public static function get_leftovers(array $students): array {
        global $DB;

        $leftovers = [];
        $studentsbyid = [];
        foreach ($students as $student) {
            $userid = (int) $student->id;
            $leftovers[$userid] = [];
            $studentsbyid[$userid] = $student;
        }
        $students = $studentsbyid;

        if ($students === []) {
            return $leftovers;
        }

        $courseids = [];
        foreach ($students as $student) {
            foreach ($student->courseids ?? [] as $courseid) {
                $courseids[(int) $courseid] = (int) $courseid;
            }
        }
        if ($courseids === []) {
            return $leftovers;
        }

        $candidates = self::get_candidate_cms($courseids);
        if ($candidates === []) {
            return $leftovers;
        }

        $done = self::get_done_cm_keys($students, $courseids);
        $grouped = [];
        foreach (array_keys($students) as $userid) {
            $grouped[$userid] = [];
        }

        foreach ($candidates as $candidate) {
            $cmid = (int) $candidate->cmid;
            $courseid = (int) $candidate->courseid;
            $daynumber = (int) $candidate->daynumber;
            $expected = (int) $candidate->completionexpected;
            foreach ($students as $userid => $student) {
                if (!isset($student->courseids[$courseid])) {
                    continue;
                }
                if (isset($done[$userid . ':' . $cmid])) {
                    continue;
                }
                if (!isset($grouped[$userid][$daynumber])) {
                    $grouped[$userid][$daynumber] = [
                        'leftovercount' => 0,
                        'sorttime' => $expected,
                    ];
                }
                $grouped[$userid][$daynumber]['leftovercount']++;
                if ($expected < $grouped[$userid][$daynumber]['sorttime']) {
                    $grouped[$userid][$daynumber]['sorttime'] = $expected;
                }
            }
        }

        foreach ($grouped as $userid => $days) {
            $items = [];
            foreach ($days as $daynumber => $info) {
                $items[] = (object) [
                    'day' => (int) $daynumber,
                    'leftovercount' => (int) $info['leftovercount'],
                    'sorttime' => (int) $info['sorttime'],
                ];
            }
            usort($items, static function (\stdClass $a, \stdClass $b): int {
                if ($a->sorttime !== $b->sorttime) {
                    return $a->sorttime <=> $b->sorttime;
                }
                return $a->day <=> $b->day;
            });
            $leftovers[$userid] = $items;
        }

        return $leftovers;
    }

    /**
     * Trackable course modules whose reminder date is today or earlier.
     *
     * @param int[] $courseids
     * @return \stdClass[]
     */
    protected static function get_candidate_cms(array $courseids): array {
        global $DB;

        [$courseinsql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
        $tomorrow = usergetmidnight(time()) + DAYSECS;
        $sql = "SELECT cm.id AS cmid, cm.course AS courseid, cs.section AS daynumber,
                       cm.completionexpected
                  FROM {course_modules} cm
                  JOIN {course_sections} cs ON cs.id = cm.section
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.course {$courseinsql}
                   AND cs.section > 0
                   AND cm.deletioninprogress = 0
                   AND cm.completionexpected > 0
                   AND cm.completionexpected < :tomorrow
                   AND (cm.completion > 0 OR m.name = :assignname OR m.name = :quizname)";

        return $DB->get_records_sql($sql, $courseparams + [
            'tomorrow' => $tomorrow,
            'assignname' => 'assign',
            'quizname' => 'quiz',
        ]);
    }

    /**
     * Qualifying work keyed as "userid:cmid".
     *
     * @param \stdClass[] $students
     * @param int[] $courseids
     * @return array<string, bool>
     */
    protected static function get_done_cm_keys(array $students, array $courseids): array {
        global $DB;

        $userids = array_map('intval', array_keys($students));
        [$courseinsql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
        [$userinsql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $params = $courseparams + $userparams;

        $queries = [];
        $queries[] = [
            "SELECT cmc.userid, cm.id AS cmid, cm.course AS courseid
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
              WHERE cm.course {$courseinsql}
                AND cmc.userid {$userinsql}
                AND cm.deletioninprogress = 0
                AND cm.completion > 0
                AND cmc.completionstate IN (" . COMPLETION_COMPLETE . ', ' . COMPLETION_COMPLETE_PASS . ')',
            $params,
        ];

        if ($DB->record_exists('modules', ['name' => 'assign'])) {
            $queries[] = [
                "SELECT sub.userid, cm.id AS cmid, a.course AS courseid
                   FROM {assign_submission} sub
                   JOIN {assign} a ON a.id = sub.assignment
                   JOIN {modules} m ON m.name = 'assign'
                   JOIN {course_modules} cm ON cm.instance = a.id
                        AND cm.module = m.id AND cm.course = a.course
                  WHERE a.course {$courseinsql}
                    AND sub.userid {$userinsql}
                    AND sub.userid <> 0
                    AND sub.status = :assignsubmitted
                    AND cm.deletioninprogress = 0",
                $params + ['assignsubmitted' => 'submitted'],
            ];
        }

        if ($DB->record_exists('modules', ['name' => 'quiz'])) {
            $queries[] = [
                "SELECT qa.userid, cm.id AS cmid, q.course AS courseid
                   FROM {quiz_attempts} qa
                   JOIN {quiz} q ON q.id = qa.quiz
                   JOIN {modules} m ON m.name = 'quiz'
                   JOIN {course_modules} cm ON cm.instance = q.id
                        AND cm.module = m.id AND cm.course = q.course
                  WHERE q.course {$courseinsql}
                    AND qa.userid {$userinsql}
                    AND qa.state = :quizfinished
                    AND qa.preview = 0
                    AND qa.timefinish > 0
                    AND cm.deletioninprogress = 0",
                $params + ['quizfinished' => 'finished'],
            ];
        }

        $done = [];
        foreach ($queries as $query) {
            [$sql, $queryparams] = $query;
            $rows = $DB->get_recordset_sql($sql, $queryparams);
            foreach ($rows as $row) {
                $userid = (int) $row->userid;
                $courseid = (int) $row->courseid;
                if (!isset($students[$userid], $students[$userid]->courseids[$courseid])) {
                    continue;
                }
                $done[$userid . ':' . (int) $row->cmid] = true;
            }
            $rows->close();
        }

        return $done;
    }
}
