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

use local_homeschool\local\day_scheduler;
use renderable;
use renderer_base;
use templatable;

/**
 * Clear reminder dates page renderable.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class clear_reminders_page implements renderable, templatable {

    /** @var \stdClass[] */
    protected $courses;

    /** @var int */
    protected $courseid;

    /** @var bool */
    protected $showhidden;

    /**
     * @param \stdClass[] $courses Managed daysections courses
     * @param int $courseid Selected course id, or 0
     * @param bool $showhidden Include courses hidden from students
     */
    public function __construct(array $courses, int $courseid = 0, bool $showhidden = false) {
        $this->courses = $courses;
        $this->courseid = $courseid;
        $this->showhidden = $showhidden;
    }

    /**
     * @param renderer_base $output
     * @return \stdClass
     */
    public function export_for_template(renderer_base $output): \stdClass {
        $dashboardurl = new \moodle_url('/local/homeschool/index.php');
        $pageurl = new \moodle_url('/local/homeschool/clearreminders.php');
        if ($this->showhidden) {
            $dashboardurl->param('showhidden', 1);
            $pageurl->param('showhidden', 1);
        }

        $courseoptions = [];
        foreach ($this->courses as $course) {
            $courseoptions[] = (object) [
                'id' => $course->id,
                'name' => $course->fullname,
                'selected' => (int) $course->id === $this->courseid,
            ];
        }

        $data = (object) [
            'dashboardurl' => $dashboardurl->out(false),
            'pageurl' => $pageurl->out(false),
            'showhidden' => $this->showhidden,
            'sesskey' => sesskey(),
            'courseoptions' => $courseoptions,
            'hascourses' => !empty($courseoptions),
            'hascourse' => false,
            'hasdays' => false,
            'hasselectable' => false,
            'days' => [],
        ];

        if ($this->courseid < 1 || !isset($this->courses[$this->courseid])) {
            return $data;
        }

        $course = $this->courses[$this->courseid];
        $rows = [];
        $hasselectable = false;
        foreach (day_scheduler::get_course_day_reminder_rows($course) as $row) {
            if ($row->hasdate) {
                $hasselectable = true;
            }
            $rows[] = (object) [
                'daynumber' => $row->daynumber,
                'daylabel' => $row->daylabel,
                'dateformatted' => $row->dateformatted,
                'hasdate' => $row->hasdate,
                'checked' => $row->hasdate,
                'disabled' => !$row->hasdate,
                'hasactivitycount' => $row->hasdate && $row->datedcount > 0,
                'datedcount' => $row->datedcount,
            ];
        }

        $data->hascourse = true;
        $data->courseid = $course->id;
        $data->coursename = $course->fullname;
        $data->hasdays = !empty($rows);
        $data->hasselectable = $hasselectable;
        $data->days = $rows;

        return $data;
    }
}
