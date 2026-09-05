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
 * Furthest day with qualifying work for each child.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class current_day {

    /**
     * Highest day number with complete/pass, submitted assignment, or finished quiz.
     *
     * Section 0 is ignored. Only work in courses attached to each student is counted.
     *
     * @param \stdClass[] $students userid-keyed records with courseids
     * @return int[] userid => day number (0 if none)
     */
    public static function get_furthest_days(array $students): array {
        global $DB;

        $days = [];
        $studentsbyid = [];
        foreach ($students as $student) {
            $userid = (int) $student->id;
            $days[$userid] = 0;
            $studentsbyid[$userid] = $student;
        }
        $students = $studentsbyid;

        if ($students === []) {
            return $days;
        }

        $courseids = [];
        foreach ($students as $student) {
            foreach ($student->courseids ?? [] as $courseid) {
                $courseids[(int) $courseid] = (int) $courseid;
            }
        }
        if ($courseids === []) {
            return $days;
        }

        $userids = array_map('intval', array_keys($students));
        [$courseinsql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
        [$userinsql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $params = $courseparams + $userparams;

        $queries = [];
        $queries[] = [
            "SELECT cmc.userid, cm.course AS courseid, cs.section AS daynumber
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
               JOIN {course_sections} cs ON cs.id = cm.section
              WHERE cm.course {$courseinsql}
                AND cmc.userid {$userinsql}
                AND cs.section > 0
                AND cm.deletioninprogress = 0
                AND cm.completion > 0
                AND cmc.completionstate IN (" . COMPLETION_COMPLETE . ', ' . COMPLETION_COMPLETE_PASS . ')',
            $params,
        ];

        if ($DB->record_exists('modules', ['name' => 'assign'])) {
            $queries[] = [
                "SELECT sub.userid, a.course AS courseid, cs.section AS daynumber
                   FROM {assign_submission} sub
                   JOIN {assign} a ON a.id = sub.assignment
                   JOIN {modules} m ON m.name = 'assign'
                   JOIN {course_modules} cm ON cm.instance = a.id
                        AND cm.module = m.id AND cm.course = a.course
                   JOIN {course_sections} cs ON cs.id = cm.section
                  WHERE a.course {$courseinsql}
                    AND sub.userid {$userinsql}
                    AND sub.userid <> 0
                    AND sub.status = :assignsubmitted
                    AND cs.section > 0
                    AND cm.deletioninprogress = 0",
                $params + ['assignsubmitted' => 'submitted'],
            ];
        }

        if ($DB->record_exists('modules', ['name' => 'quiz'])) {
            $queries[] = [
                "SELECT qa.userid, q.course AS courseid, cs.section AS daynumber
                   FROM {quiz_attempts} qa
                   JOIN {quiz} q ON q.id = qa.quiz
                   JOIN {modules} m ON m.name = 'quiz'
                   JOIN {course_modules} cm ON cm.instance = q.id
                        AND cm.module = m.id AND cm.course = q.course
                   JOIN {course_sections} cs ON cs.id = cm.section
                  WHERE q.course {$courseinsql}
                    AND qa.userid {$userinsql}
                    AND qa.state = :quizfinished
                    AND qa.preview = 0
                    AND qa.timefinish > 0
                    AND cs.section > 0
                    AND cm.deletioninprogress = 0",
                $params + ['quizfinished' => 'finished'],
            ];
        }

        foreach ($queries as $query) {
            [$sql, $queryparams] = $query;
            $rows = $DB->get_recordset_sql($sql, $queryparams);
            foreach ($rows as $row) {
                $userid = (int) $row->userid;
                $courseid = (int) $row->courseid;
                if (!isset($students[$userid], $days[$userid], $students[$userid]->courseids[$courseid])) {
                    continue;
                }
                $daynumber = (int) $row->daynumber;
                if ($daynumber > $days[$userid]) {
                    $days[$userid] = $daynumber;
                }
            }
            $rows->close();
        }

        return $days;
    }
}
