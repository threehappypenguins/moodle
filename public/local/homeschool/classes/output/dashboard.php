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

namespace local_homeschool\output;

use local_homeschool\local\course_repository;
use local_homeschool\local\requirements;
use local_homeschool\local\student_repository;
use local_homeschool\local\upcoming_service;
use renderable;
use renderer_base;
use templatable;

/**
 * Main dashboard renderable.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard implements renderable, templatable {

    /** @var int */
    protected $userid;

    /** @var bool */
    protected $showhidden;

    /** @var bool */
    protected $showotherformats;

    /**
     * @param int $userid
     * @param bool $showhidden Include courses hidden from students
     * @param bool $showotherformats Include courses that are not daysections format
     */
    public function __construct(int $userid, bool $showhidden = false, bool $showotherformats = false) {
        $this->userid = $userid;
        $this->showhidden = $showhidden;
        $this->showotherformats = $showotherformats;
    }

    /**
     * @param renderer_base $output
     * @return \stdClass
     */
    public function export_for_template(renderer_base $output): \stdClass {
        $hiddencount = course_repository::count_hidden_managed_daysections_courses($this->userid);
        // Include hidden in the count so the cleanup toggle remains discoverable.
        $otherformatscount = course_repository::count_managed_other_format_courses($this->userid, true);

        $courses = course_repository::get_managed_daysections_courses($this->userid, $this->showhidden);
        if ($this->showotherformats) {
            $courses += course_repository::get_managed_other_format_courses($this->userid, $this->showhidden);
            uasort($courses, static function($a, $b) {
                return strcmp($a->fullname, $b->fullname);
            });
        }

        $students = student_repository::get_students_for_courses($courses);

        $studentrows = [];
        foreach ($students as $student) {
            $studentcourses = [];
            foreach ($student->courseids as $courseid) {
                if (!isset($courses[$courseid])) {
                    continue;
                }
                $course = $courses[$courseid];
                $isdaysections = ($course->format === 'daysections');
                $studentcourses[] = (object) [
                    'name' => $course->fullname,
                    'hidden' => empty($course->visible),
                    'needsdaysections' => !$isdaysections,
                    'formatname' => course_repository::get_format_display_name($course->format),
                    'courseurl' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                ];
            }
            $studentrows[] = (object) [
                'name' => student_repository::format_child_name($student),
                'courses' => $studentcourses,
                'hascourses' => !empty($studentcourses),
            ];
        }

        $courserows = [];
        foreach ($courses as $course) {
            $childnames = [];
            foreach ($students as $student) {
                if (isset($student->courseids[$course->id])) {
                    $childnames[] = student_repository::format_child_name($student);
                }
            }
            $isdaysections = ($course->format === 'daysections');
            $courserows[] = (object) [
                'name' => $course->fullname,
                'hidden' => empty($course->visible),
                'needsdaysections' => !$isdaysections,
                'formatname' => course_repository::get_format_display_name($course->format),
                'childnames' => $childnames,
                'childlist' => implode(', ', $childnames),
                'courseurl' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
                'editurl' => (new \moodle_url('/course/edit.php', ['id' => $course->id]))->out(false),
            ];
        }

        // Upcoming reminders only from daysections courses (scheduling target).
        $daysectionscourses = array_filter($courses, static function($course) {
            return $course->format === 'daysections';
        });
        $maxday = course_repository::get_max_day_number($daysectionscourses);
        $dayoptions = [];
        $optionmax = max($maxday, 1);
        for ($i = 1; $i <= $optionmax; $i++) {
            $dayoptions[] = (object) [
                'value' => $i,
                'label' => get_string('daytitle', 'local_homeschool', $i),
            ];
        }

        $now = time();
        $upcoming = upcoming_service::get_upcoming(
            $daysectionscourses,
            $now - (7 * DAYSECS),
            $now + (upcoming_service::DEFAULT_DAYS_AHEAD * DAYSECS),
        );

        $upcomingrows = [];
        foreach ($upcoming as $item) {
            $upcomingrows[] = (object) [
                'coursename' => $item->coursename,
                'activityname' => $item->activityname,
                'sectionname' => $item->sectionname,
                'dateformatted' => $item->dateformatted,
                'overdue' => $item->overdue,
                'url' => $item->url,
                'dayurl' => $item->dayurl,
            ];
        }

        return (object) [
            'canmanage' => requirements::user_can_manage(),
            'showhidden' => $this->showhidden,
            'hashiddencourses' => $hiddencount > 0,
            'hiddencount' => $hiddencount,
            'showotherformats' => $this->showotherformats,
            'hasotherformats' => $otherformatscount > 0,
            'otherformatscount' => $otherformatscount,
            'showfilters' => ($hiddencount > 0 || $otherformatscount > 0),
            'hascourses' => !empty($courses),
            'hasstudents' => !empty($studentrows),
            'students' => array_values($studentrows),
            'courses' => array_values($courserows),
            'upcoming' => $upcomingrows,
            'hasupcoming' => !empty($upcomingrows),
            'dayurl' => (new \moodle_url('/local/homeschool/day.php'))->out(false),
            'hasdaypicker' => requirements::user_can_manage() && !empty($daysectionscourses),
            'shifturl' => (new \moodle_url('/local/homeschool/shift.php'))->out(false),
            'hasshiftlink' => requirements::user_can_manage() && !empty($daysectionscourses),
            'dayoptions' => $dayoptions,
            'dashboardurl' => (new \moodle_url('/local/homeschool/index.php'))->out(false),
            'nodatahelp' => get_string('nodatahelp', 'local_homeschool'),
            'otherformatshelp' => get_string('otherformatshelp', 'local_homeschool'),
        ];
    }
}
