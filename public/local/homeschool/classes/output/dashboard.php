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
use local_homeschool\local\current_day;
use local_homeschool\local\requirements;
use local_homeschool\local\student_repository;
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
        $hiddendaysectionscount = course_repository::count_hidden_viewable_daysections_courses($this->userid);
        $hiddenotherformatscount = course_repository::count_hidden_viewable_other_format_courses($this->userid);
        $hiddencount = $hiddendaysectionscount;
        if ($this->showotherformats) {
            $hiddencount += $hiddenotherformatscount;
        }

        $visibleotherformatscount = course_repository::count_viewable_other_format_courses($this->userid, false);
        $otherformatstotalcount = course_repository::count_viewable_other_format_courses($this->userid, true);
        // Prefer a visible-only label; fall back to total when every other-format course is hidden.
        $otherformatscount = $visibleotherformatscount > 0 ? $visibleotherformatscount : $otherformatstotalcount;

        $courses = course_repository::get_viewable_daysections_courses($this->userid, $this->showhidden);
        if ($this->showotherformats) {
            $courses += course_repository::get_viewable_other_format_courses($this->userid, $this->showhidden);
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

        $manageddaysectionscourses = course_repository::get_managed_daysections_courses($this->userid, $this->showhidden);
        $maxday = course_repository::get_max_day_number($manageddaysectionscourses);
        $dayoptions = [];
        $optionmax = max($maxday, 1);
        for ($i = 1; $i <= $optionmax; $i++) {
            $dayoptions[] = (object) [
                'value' => $i,
                'label' => get_string('daytitle', 'local_homeschool', $i),
            ];
        }

        $shifturl = new \moodle_url('/local/homeschool/shift.php');
        $clearremindersurl = new \moodle_url('/local/homeschool/clearreminders.php');
        $dayurl = new \moodle_url('/local/homeschool/day.php');
        if ($this->showhidden) {
            $shifturl->param('showhidden', 1);
            $clearremindersurl->param('showhidden', 1);
            $dayurl->param('showhidden', 1);
        }

        $canmanagecourses = requirements::user_can_manage() && !empty($manageddaysectionscourses);

        $currentdaysdata = [
            'hascurrentdays' => false,
            'currentnone' => false,
            'currentsameday' => false,
            'samedayurl' => '',
            'samedaylabel' => '',
            'currentchildren' => [],
        ];
        if ($canmanagecourses) {
            $managedids = array_fill_keys(array_keys($manageddaysectionscourses), true);
            $managedstudents = [];
            foreach ($students as $student) {
                $courseids = array_intersect_key($student->courseids ?? [], $managedids);
                if ($courseids === []) {
                    continue;
                }
                $managedstudent = clone $student;
                $managedstudent->courseids = $courseids;
                $managedstudents[(int) $student->id] = $managedstudent;
            }
            $currentdaysdata = current_day::picker_template_data($managedstudents, $dayurl);
        }

        return (object) ([
            'showhidden' => $this->showhidden,
            'hashiddencourses' => $hiddencount > 0,
            'hiddencount' => $hiddencount,
            'showotherformats' => $this->showotherformats,
            'hasotherformats' => $otherformatstotalcount > 0,
            'otherformatscount' => $otherformatscount,
            'showfilters' => ($hiddencount > 0 || $otherformatstotalcount > 0),
            'hascourses' => !empty($courses),
            'hasstudents' => !empty($studentrows),
            'students' => array_values($studentrows),
            'courses' => array_values($courserows),
            'dayurl' => $dayurl->out(false),
            'hasdaypicker' => $canmanagecourses,
            'shifturl' => $shifturl->out(false),
            'hasshiftlink' => $canmanagecourses,
            'clearremindersurl' => $clearremindersurl->out(false),
            'hasclearreminderslink' => $canmanagecourses,
            'dayoptions' => $dayoptions,
            'dashboardurl' => (new \moodle_url('/local/homeschool/index.php'))->out(false),
            'nodatahelp' => get_string('nodatahelp', 'local_homeschool'),
            'otherformatshelp' => get_string('otherformatshelp', 'local_homeschool'),
        ] + $currentdaysdata);
    }
}
