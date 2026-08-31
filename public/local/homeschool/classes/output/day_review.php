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

use local_homeschool\local\activity_repository;
use local_homeschool\local\student_repository;
use renderable;
use renderer_base;
use templatable;

/**
 * Day activity review page renderable.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class day_review implements renderable, templatable {

    /** @var int */
    protected $daynumber;

    /** @var \stdClass[] */
    protected $courses;

    /** @var string */
    protected $dateformhtml;

    /** @var bool */
    protected $showall;

    /** @var int */
    protected $maxday;

    /** @var int */
    protected $expandreqcmid;

    /**
     * @param int $daynumber 0 = no day selected yet
     * @param \stdClass[] $courses
     * @param string $dateformhtml Rendered bulk date form HTML
     * @param bool $showall Flat list of all activities (default is per-child groups)
     * @param int $maxday Highest day/section number across managed courses
     * @param int $expandreqcmid Open requirements for this cm after a failed autosave
     */
    public function __construct(
        int $daynumber,
        array $courses,
        string $dateformhtml = '',
        bool $showall = false,
        int $maxday = 0,
        int $expandreqcmid = 0,
    ) {
        $this->daynumber = $daynumber;
        $this->courses = $courses;
        $this->dateformhtml = $dateformhtml;
        $this->showall = $showall;
        $this->maxday = max(0, $maxday);
        $this->expandreqcmid = $expandreqcmid;
    }

    /**
     * @param renderer_base $output
     * @return \stdClass
     */
    public function export_for_template(renderer_base $output): \stdClass {
        $hasday = $this->daynumber > 0;
        $rows = [];
        $groups = [];

        if ($hasday) {
            $activities = activity_repository::get_activities_for_day($this->courses, $this->daynumber);
            $students = student_repository::get_students_for_courses($this->courses);

            $coursestudents = [];
            foreach ($this->courses as $course) {
                $coursestudents[$course->id] = [];
            }
            foreach ($students as $student) {
                foreach ($student->courseids as $courseid) {
                    if (isset($coursestudents[$courseid])) {
                        $coursestudents[$courseid][$student->id] = $student;
                    }
                }
            }

            foreach ($activities as $activity) {
                $rows[] = $this->export_activity_row($activity);
            }

            if (!$this->showall) {
                $groups = $this->build_child_groups($rows, $coursestudents);
            }
        }

        $dayoptions = [];
        $optionmax = max($this->maxday, $this->daynumber, 1);
        for ($i = 1; $i <= $optionmax; $i++) {
            $dayoptions[] = (object) [
                'value' => $i,
                'label' => get_string('daytitle', 'local_homeschool', $i),
                'selected' => $i === $this->daynumber,
            ];
        }

        return (object) [
            'daynumber' => $this->daynumber,
            'hasday' => $hasday,
            'daytitle' => $hasday ? get_string('daytitle', 'local_homeschool', $this->daynumber) : '',
            'dayoptions' => $dayoptions,
            'maxday' => $this->maxday,
            'showall' => $this->showall,
            'activities' => $rows,
            'groups' => $groups,
            'hasgroups' => !empty($groups),
            'hasactivities' => !empty($rows),
            'noactivities' => $hasday && empty($rows),
            'dashboardurl' => (new \moodle_url('/local/homeschool/index.php'))->out(false),
            'reviewurl' => $this->review_url()->out(false),
            'reviewformaction' => (new \moodle_url('/local/homeschool/review.php'))->out(false),
            'sesskey' => sesskey(),
            'reviewhelp' => get_string('reviewhelp', 'local_homeschool'),
            'opendayhelp' => get_string('opendayhelp', 'local_homeschool'),
            'multiselecthint' => get_string('multiselecthint', 'local_homeschool'),
            'dateformhtml' => $this->dateformhtml,
            'hasdateform' => $this->dateformhtml !== '',
        ];
    }

    /**
     * @return \moodle_url
     */
    protected function review_url(): \moodle_url {
        $url = new \moodle_url('/local/homeschool/review.php');
        if ($this->daynumber > 0) {
            $url->param('day', $this->daynumber);
        }
        if ($this->showall) {
            $url->param('showall', 1);
        }
        return $url;
    }

    /**
     * @param \stdClass $activity
     * @return \stdClass
     */
    protected function export_activity_row(\stdClass $activity): \stdClass {
        $submissions = [];
        foreach ($activity->submissions as $submission) {
            $submissions[] = (object) [
                'type' => $submission->type,
                'name' => $submission->name,
                'enabled' => $submission->enabled,
                'fieldname' => 'submission_' . $activity->cmid . '_' . $submission->type,
            ];
        }

        return (object) [
            'cmid' => $activity->cmid,
            'courseid' => $activity->courseid,
            'coursename' => $activity->coursename,
            'activityname' => $activity->activityname,
            'modname' => $activity->modname,
            'completion' => $activity->completion,
            'completionlabel' => $activity->completionlabel,
            'completionexpectedformatted' => $activity->completionexpectedformatted,
            'completionexpectediso' => $activity->completionexpectediso,
            'hasreminderdate' => !empty($activity->hasreminderdate),
            'completionlocked' => !empty($activity->completionlocked),
            'isassign' => $activity->isassign,
            'hassubmissions' => !empty($submissions),
            'submissions' => $submissions,
            'hasrequirements' => !empty($activity->hasrequirements),
            'showrequirements' => ((int) $activity->completion === COMPLETION_TRACKING_AUTOMATIC)
                || ((int) $this->expandreqcmid === (int) $activity->cmid),
            'requirementsopen' => ((int) $this->expandreqcmid === (int) $activity->cmid),
            'requirements' => self::export_requirements($activity),
            'editurl' => $activity->editurl,
            'reviewurl' => $this->review_url()->out(false),
            'sesskey' => sesskey(),
            'showall' => $this->showall,
            'completionoptions' => [
                (object) [
                    'value' => COMPLETION_TRACKING_NONE,
                    'label' => get_string('completion_none', 'completion'),
                    'selected' => $activity->completion == COMPLETION_TRACKING_NONE,
                ],
                (object) [
                    'value' => COMPLETION_TRACKING_MANUAL,
                    'label' => get_string('completion_manual', 'completion'),
                    'selected' => $activity->completion == COMPLETION_TRACKING_MANUAL,
                ],
                (object) [
                    'value' => COMPLETION_TRACKING_AUTOMATIC,
                    'label' => get_string('completion_automatic', 'completion'),
                    'selected' => $activity->completion == COMPLETION_TRACKING_AUTOMATIC,
                ],
            ],
        ];
    }

    /**
     * @param \stdClass $activity
     * @return \stdClass[]
     */
    protected function export_requirements(\stdClass $activity): array {
        $requirements = [];
        foreach ($activity->requirements ?? [] as $requirement) {
            $item = (object) [
                'name' => $requirement->name,
                'label' => $requirement->label,
                'enabled' => !empty($requirement->enabled),
                'fieldname' => 'requirement_' . $requirement->name,
                'inputid' => 'requirement-' . $activity->cmid . '-' . $requirement->name,
                'haspassgrade' => !empty($requirement->haspassgrade),
                'passgrade' => !empty($requirement->passgrade),
                'locked' => !empty($activity->completionlocked),
            ];
            $requirements[] = $item;
        }
        return $requirements;
    }

    /**
     * Group activity rows by the set of children enrolled in each course.
     *
     * @param \stdClass[] $rows
     * @param array $coursestudents courseid => [userid => user]
     * @return \stdClass[]
     */
    protected function build_child_groups(array $rows, array $coursestudents): array {
        $grouped = [];

        foreach ($rows as $row) {
            $students = $coursestudents[$row->courseid] ?? [];
            $ids = array_map('intval', array_keys($students));
            sort($ids);
            $key = $ids ? implode(',', $ids) : 'none';

            if (!isset($grouped[$key])) {
                $names = [];
                foreach ($ids as $id) {
                    $names[] = fullname($students[$id]);
                }
                $shared = count($ids) > 1;
                if ($ids === []) {
                    $heading = get_string('nochildrenforcourse', 'local_homeschool');
                } else if ($shared) {
                    $heading = get_string('sharedchildrenheading', 'local_homeschool', implode(', ', $names));
                } else {
                    $heading = $names[0];
                }

                $grouped[$key] = (object) [
                    'key' => $key,
                    'heading' => $heading,
                    'shared' => $shared,
                    'singlestudent' => count($ids) === 1,
                    'nostudents' => $ids === [],
                    'sortname' => $ids === [] ? 'zzz' : implode(', ', $names),
                    'activities' => [],
                ];
            }
            $grouped[$key]->activities[] = $row;
        }

        $groups = array_values($grouped);
        usort($groups, static function($a, $b) {
            $arank = $a->nostudents ? 2 : ($a->shared ? 1 : 0);
            $brank = $b->nostudents ? 2 : ($b->shared ? 1 : 0);
            if ($arank !== $brank) {
                return $arank <=> $brank;
            }
            return strcasecmp($a->sortname, $b->sortname);
        });

        return $groups;
    }
}
