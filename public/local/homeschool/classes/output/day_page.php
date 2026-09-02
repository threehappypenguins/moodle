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

use local_homeschool\local\activity_progress;
use local_homeschool\local\activity_repository;
use local_homeschool\local\modedit_launch;
use local_homeschool\local\student_repository;
use renderable;
use renderer_base;
use templatable;

/**
 * Day hub page renderable.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class day_page implements renderable, templatable {

    /** @var int */
    protected $daynumber;

    /** @var \stdClass[] */
    protected $courses;

    /** @var string */
    protected $dateformhtml;

    /** @var bool */
    protected $showall;

    /** @var bool */
    protected $showhidden;

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
     * @param bool $showhidden Include courses hidden from students
     */
    public function __construct(
        int $daynumber,
        array $courses,
        string $dateformhtml = '',
        bool $showall = false,
        int $maxday = 0,
        int $expandreqcmid = 0,
        bool $showhidden = false,
    ) {
        $this->daynumber = $daynumber;
        $this->courses = $courses;
        $this->dateformhtml = $dateformhtml;
        $this->showall = $showall;
        $this->showhidden = $showhidden;
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
            } else {
                $this->attach_progress_to_rows($rows, $coursestudents);
            }

            $addcourseexport = $this->export_add_courses($coursestudents);
        } else {
            $addcourseexport = (object) [
                'flat' => true,
                'courses' => [],
                'groups' => [],
            ];
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

        $dashboardurl = new \moodle_url('/local/homeschool/index.php');
        if ($this->showhidden) {
            $dashboardurl->param('showhidden', 1);
        }

        return (object) [
            'daynumber' => $this->daynumber,
            'hasday' => $hasday,
            'daytitle' => $hasday ? get_string('daytitle', 'local_homeschool', $this->daynumber) : '',
            'dayoptions' => $dayoptions,
            'maxday' => $this->maxday,
            'showall' => $this->showall,
            'showhidden' => $this->showhidden,
            'activities' => $rows,
            'groups' => $groups,
            'hasgroups' => !empty($groups),
            'hasactivities' => !empty($rows),
            'noactivities' => $hasday && empty($rows),
            'dashboardurl' => $dashboardurl->out(false),
            'dayurl' => $this->day_url()->out(false),
            'dayformaction' => (new \moodle_url('/local/homeschool/day.php'))->out(false),
            'sesskey' => sesskey(),
            'multiselecthint' => get_string('multiselecthint', 'local_homeschool'),
            'dateformhtml' => $this->dateformhtml,
            'hasdateform' => $this->dateformhtml !== '',
            'addcourses' => $addcourseexport->courses,
            'addcoursegroups' => $addcourseexport->groups,
            'addcourseflat' => !empty($addcourseexport->flat),
            'hasaddcourses' => $hasday && !empty($this->courses),
        ];
    }

    /**
     * Courses available for adding an activity to the current day section.
     *
     * When showall is off, courses are grouped under the same child headings as activities.
     *
     * @param array $coursestudents courseid => [userid => user]
     * @return \stdClass{flat:bool,courses:\stdClass[],groups:\stdClass[]}
     */
    protected function export_add_courses(array $coursestudents): \stdClass {
        $optionsbyid = [];

        foreach ($this->courses as $course) {
            $optionsbyid[(int) $course->id] = $this->build_add_course_option($course);
        }

        if ($this->showall) {
            return (object) [
                'flat' => true,
                'courses' => array_values($optionsbyid),
                'groups' => [],
            ];
        }

        $grouped = [];
        foreach ($optionsbyid as $courseid => $option) {
            $meta = $this->child_group_meta((int) $courseid, $coursestudents);
            if (!isset($grouped[$meta->key])) {
                $grouped[$meta->key] = (object) [
                    'key' => $meta->key,
                    'heading' => $meta->heading,
                    'shared' => $meta->shared,
                    'nostudents' => $meta->nostudents,
                    'sortname' => $meta->sortname,
                    'courses' => [],
                ];
            }
            $grouped[$meta->key]->courses[] = $option;
        }

        $groups = array_values($grouped);
        usort($groups, [$this, 'compare_child_groups']);

        return (object) [
            'flat' => false,
            'courses' => array_values($optionsbyid),
            'groups' => $groups,
        ];
    }

    /**
     * @param \stdClass $course
     * @return \stdClass
     */
    protected function build_add_course_option(\stdClass $course): \stdClass {
        $modinfo = get_fast_modinfo($course);
        $section = $modinfo->get_section_info($this->daynumber, IGNORE_MISSING);
        $missing = empty($section);
        $courseurl = course_get_url($course);
        if ($courseurl) {
            $courseurl = $courseurl->out(false);
        } else {
            $courseurl = (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
        }

        $option = (object) [
            'id' => $course->id,
            'name' => format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]),
            'disabled' => $missing,
            'missingsection' => $missing,
            'selected' => false,
            'courseurl' => $courseurl,
            'sectionid' => $missing ? 0 : (int) $section->id,
            'sectionnum' => $this->daynumber,
            'returnoptions' => '',
        ];

        if (!$missing) {
            $option->returnoptions = json_encode([
                'sr' => (int) $this->daynumber,
                'pagesectionid' => (int) $section->id,
            ]);
        }

        return $option;
    }

    /**
     * @return \moodle_url
     */
    protected function day_url(): \moodle_url {
        $url = new \moodle_url('/local/homeschool/day.php');
        if ($this->daynumber > 0) {
            $url->param('day', $this->daynumber);
        }
        if ($this->showall) {
            $url->param('showall', 1);
        }
        if ($this->showhidden) {
            $url->param('showhidden', 1);
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
            'completionenabled' => !empty($activity->completionenabled),
            'dateeditable' => !empty($activity->completionenabled)
                && (int) $activity->completion !== COMPLETION_TRACKING_NONE,
            'completionlocked' => !empty($activity->completionlocked),
            'isassign' => $activity->isassign,
            'hassubmissions' => !empty($submissions),
            'submissions' => $submissions,
            'hasrequirements' => !empty($activity->hasrequirements),
            'showrequirements' => ((int) $activity->completion === COMPLETION_TRACKING_AUTOMATIC)
                || ((int) $this->expandreqcmid === (int) $activity->cmid),
            'requirementsopen' => ((int) $this->expandreqcmid === (int) $activity->cmid),
            'requirements' => self::export_requirements($activity),
            'activityurl' => $activity->activityurl,
            'editurl' => modedit_launch::build_edit_launch_url(
                (int) $activity->cmid,
                $this->daynumber,
                $this->showall,
                $this->showhidden,
            )->out(false),
            'progress' => [],
            'hasprogress' => false,
            'dayurl' => $this->day_url()->out(false),
            'sesskey' => sesskey(),
            'daynumber' => $this->daynumber,
            'showall' => $this->showall,
            'showhidden' => $this->showhidden,
            'completionoptions' => self::build_completion_options($activity),
        ];
    }

    /**
     * Completion tracking choices for the day page multiselect.
     *
     * @param \stdClass $activity
     * @return \stdClass[]
     */
    protected static function build_completion_options(\stdClass $activity): array {
        $options = [
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
        ];

        if (!empty($activity->hasrequirements)) {
            $options[] = (object) [
                'value' => COMPLETION_TRACKING_AUTOMATIC,
                'label' => get_string('completion_automatic', 'completion'),
                'selected' => $activity->completion == COMPLETION_TRACKING_AUTOMATIC,
            ];
        }

        return $options;
    }

    /**
     * @param \stdClass $activity
     * @return \stdClass[]
     */
    protected function export_requirements(\stdClass $activity): array {
        $requirements = [];
        foreach ($activity->requirements ?? [] as $requirement) {
            $isint = ($requirement->valuetype ?? 'bool') === 'int';
            $item = (object) [
                'name' => $requirement->name,
                'label' => $requirement->label,
                'enabled' => !empty($requirement->enabled),
                'fieldname' => 'requirement_' . $requirement->name,
                'inputid' => 'requirement-' . $activity->cmid . '-' . $requirement->name,
                'haspassgrade' => !empty($requirement->haspassgrade),
                'canrequirepassgrade' => !empty($requirement->canrequirepassgrade),
                'passgrade' => !empty($requirement->passgrade),
                'hasexhausted' => !empty($requirement->hasexhausted),
                'exhausted' => !empty($requirement->exhausted),
                'exhaustedlabel' => $requirement->exhaustedlabel ?? '',
                'exhaustedinputid' => 'requirement-' . $activity->cmid . '-completionattemptsexhausted',
                'isint' => $isint,
                'isbool' => !$isint,
                'value' => (int) ($requirement->value ?? 1),
                'min' => (int) ($requirement->min ?? 1),
                'hasmax' => isset($requirement->max),
                'max' => (int) ($requirement->max ?? 0),
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
            $meta = $this->child_group_meta((int) $row->courseid, $coursestudents);
            if (!isset($grouped[$meta->key])) {
                $grouped[$meta->key] = (object) [
                    'key' => $meta->key,
                    'heading' => $meta->heading,
                    'shared' => $meta->shared,
                    'singlestudent' => $meta->singlestudent,
                    'nostudents' => $meta->nostudents,
                    'sortname' => $meta->sortname,
                    'students' => $meta->students,
                    'activities' => [],
                ];
            }
            // Clone so shared-course enrichment does not leak across groups.
            $activity = clone $row;
            $grouped[$meta->key]->activities[] = $activity;
        }

        foreach ($grouped as $group) {
            $this->attach_progress_to_group($group);
        }

        $groups = array_values($grouped);
        usort($groups, [$this, 'compare_child_groups']);

        return $groups;
    }

    /**
     * Attach per-child progress onto a child group's activity rows.
     *
     * @param \stdClass $group
     * @return void
     */
    protected function attach_progress_to_group(\stdClass $group): void {
        $students = $group->students ?? [];
        $includenames = !empty($group->shared);
        foreach ($group->activities as $activity) {
            $activity->progress = activity_progress::export_for_activity($activity, $students, $includenames);
            $activity->hasprogress = !empty($activity->progress);
        }
    }

    /**
     * Attach progress for every enrolled child when showing the flat list.
     *
     * @param \stdClass[] $rows
     * @param array $coursestudents courseid => [userid => user]
     * @return void
     */
    protected function attach_progress_to_rows(array $rows, array $coursestudents): void {
        foreach ($rows as $row) {
            $students = $coursestudents[(int) $row->courseid] ?? [];
            $includenames = count($students) > 1;
            $row->progress = activity_progress::export_for_activity($row, $students, $includenames);
            $row->hasprogress = !empty($row->progress);
        }
    }

    /**
     * Shared child/shared/empty heading metadata for a course.
     *
     * @param int $courseid
     * @param array $coursestudents courseid => [userid => user]
     * @return \stdClass
     */
    protected function child_group_meta(int $courseid, array $coursestudents): \stdClass {
        $students = $coursestudents[$courseid] ?? [];
        $ids = array_map('intval', array_keys($students));
        sort($ids);
        $key = $ids ? implode(',', $ids) : 'none';

        $ordered = [];
        $names = [];
        foreach ($ids as $id) {
            $ordered[$id] = $students[$id];
            $names[] = student_repository::format_child_name($students[$id]);
        }
        $shared = count($ids) > 1;
        if ($ids === []) {
            $heading = get_string('nochildrenforcourse', 'local_homeschool');
        } else if ($shared) {
            $heading = get_string('sharedchildrenheading', 'local_homeschool', implode(', ', $names));
        } else {
            $heading = $names[0];
        }

        return (object) [
            'key' => $key,
            'heading' => $heading,
            'shared' => $shared,
            'singlestudent' => count($ids) === 1,
            'nostudents' => $ids === [],
            'sortname' => $ids === [] ? 'zzz' : implode(', ', $names),
            'students' => $ordered,
        ];
    }

    /**
     * Sort child groups: individuals, then shared, then no children.
     *
     * @param \stdClass $a
     * @param \stdClass $b
     * @return int
     */
    protected function compare_child_groups(\stdClass $a, \stdClass $b): int {
        $arank = !empty($a->nostudents) ? 2 : (!empty($a->shared) ? 1 : 0);
        $brank = !empty($b->nostudents) ? 2 : (!empty($b->shared) ? 1 : 0);
        if ($arank !== $brank) {
            return $arank <=> $brank;
        }
        return strcasecmp($a->sortname, $b->sortname);
    }
}
