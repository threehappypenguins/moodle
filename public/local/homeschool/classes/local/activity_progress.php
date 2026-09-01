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
 * Per-child activity progress (completion, submissions, attempts).
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_progress {

    /** Status keys used in templates / CSS. */
    public const STATE_COMPLETE = 'complete';
    public const STATE_FAILED = 'failed';
    public const STATE_SUBMITTED = 'submitted';
    public const STATE_DRAFT = 'draft';
    public const STATE_ATTEMPTED = 'attempted';
    public const STATE_NOTSTARTED = 'notstarted';
    public const STATE_NOTSUBMITTED = 'notsubmitted';

    /** Assign submission plugin types that count as student submissions. */
    protected const ASSIGN_CONTENT_TYPES = ['onlinetext', 'file'];

    /**
     * Progress rows for one activity and one or more enrolled children.
     *
     * @param \stdClass $activity Row with cmid, courseid, modname, completion
     * @param \stdClass[] $students userid-keyed user records
     * @param bool $includenames Include child names (shared courses / show-all)
     * @return \stdClass[]
     */
    public static function export_for_activity(object $activity, array $students, bool $includenames = false): array {
        if ($students === []) {
            return [];
        }

        try {
            $modinfo = get_fast_modinfo((int) $activity->courseid);
            $cm = $modinfo->get_cm((int) $activity->cmid);
        } catch (\Throwable $e) {
            return [];
        }

        $course = $modinfo->get_course();
        $completioninfo = new \completion_info($course);
        $assign = null;
        $hassubmissiontypes = false;

        if ($cm->modname === 'assign') {
            global $CFG;
            require_once($CFG->dirroot . '/mod/assign/locallib.php');
            $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
            $hassubmissiontypes = self::assign_has_content_submission_types($assign);
        }

        $rows = [];
        foreach ($students as $student) {
            $userid = (int) $student->id;
            $lines = [];

            $primary = self::resolve_primary_status(
                $cm,
                $course,
                $completioninfo,
                $userid,
                (int) $activity->completion,
                $assign,
                $hassubmissiontypes,
            );
            if ($primary !== null) {
                $lines[] = $primary;
            }

            if ($hassubmissiontypes && $assign !== null) {
                $lines[] = self::assign_submission_line($assign, $userid);
            }

            if ($lines === []) {
                $lines[] = self::status_line(
                    self::STATE_NOTSTARTED,
                    get_string('progressnotstarted', 'local_homeschool'),
                );
            }

            // Name only on the first line when listing multiple children.
            $first = true;
            foreach ($lines as $line) {
                $line->studentname = student_repository::format_child_name($student);
                $line->showname = $includenames && $first;
                $first = false;
            }

            foreach ($lines as $line) {
                $rows[] = $line;
            }
        }

        return $rows;
    }

    /**
     * Primary status line (completion / attempts). Assign submission is a separate line.
     *
     * @param \cm_info $cm
     * @param \stdClass $course
     * @param \completion_info $completioninfo
     * @param int $userid
     * @param int $tracking COMPLETION_TRACKING_*
     * @param \assign|null $assign
     * @param bool $hassubmissiontypes
     * @return \stdClass|null
     */
    protected static function resolve_primary_status(
        \cm_info $cm,
        \stdClass $course,
        \completion_info $completioninfo,
        int $userid,
        int $tracking,
        $assign,
        bool $hassubmissiontypes,
    ): ?\stdClass {
        if ($tracking !== COMPLETION_TRACKING_NONE && $completioninfo->is_enabled($cm)) {
            $data = $completioninfo->get_data($cm, false, $userid);
            $state = (int) ($data->completionstate ?? COMPLETION_INCOMPLETE);
            if (in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                return self::status_line(self::STATE_COMPLETE, get_string('progresscomplete', 'local_homeschool'));
            }
            if (in_array($state, [COMPLETION_COMPLETE_FAIL, COMPLETION_COMPLETE_FAIL_HIDDEN], true)) {
                return self::status_line(self::STATE_FAILED, get_string('progressfailed', 'local_homeschool'));
            }
            // Assign with a separate submission line: show incomplete completion explicitly.
            if ($hassubmissiontypes) {
                return self::status_line(self::STATE_NOTSTARTED, get_string('progressincomplete', 'local_homeschool'));
            }
            // Otherwise fall through for quiz attempts / assign without file|onlinetext.
        }

        if ($cm->modname === 'quiz') {
            return self::quiz_status($cm, $userid);
        }

        // Assign without file/onlinetext: keep submission-ish status on the primary line.
        if ($cm->modname === 'assign' && !$hassubmissiontypes && $assign !== null) {
            return self::assign_legacy_status($assign, $userid);
        }

        if ($hassubmissiontypes) {
            // Submission covered by the dedicated line.
            return null;
        }

        return self::status_line(self::STATE_NOTSTARTED, get_string('progressnotstarted', 'local_homeschool'));
    }

    /**
     * Whether onlinetext or file submission plugins are enabled.
     *
     * @param \assign $assign
     * @return bool
     */
    protected static function assign_has_content_submission_types(\assign $assign): bool {
        foreach ($assign->get_submission_plugins() as $plugin) {
            if (!$plugin->is_enabled()) {
                continue;
            }
            if (in_array($plugin->get_type(), self::ASSIGN_CONTENT_TYPES, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Dedicated submission line for assignments with file/online text enabled.
     *
     * @param \assign $assign
     * @param int $userid
     * @return \stdClass
     */
    protected static function assign_submission_line(\assign $assign, int $userid): \stdClass {
        $submission = self::get_assign_submission($assign, $userid);

        if ($submission && $submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            return self::status_line(
                self::STATE_SUBMITTED,
                get_string('progresssubmitted', 'local_homeschool'),
                self::assign_submissions_url($assign),
            );
        }

        if ($submission && in_array($submission->status, [
            ASSIGN_SUBMISSION_STATUS_DRAFT,
            ASSIGN_SUBMISSION_STATUS_REOPENED,
        ], true)) {
            if (self::assign_submission_has_content($assign, $submission)) {
                return self::status_line(self::STATE_DRAFT, get_string('progressdraft', 'local_homeschool'));
            }
        }

        return self::status_line(self::STATE_NOTSUBMITTED, get_string('progressnotsubmitted', 'local_homeschool'));
    }

    /**
     * URL for the assignment Submissions (grading) tab, if the viewer can grade.
     *
     * @param \assign $assign
     * @return string|null
     */
    protected static function assign_submissions_url(\assign $assign): ?string {
        if (!$assign->can_grade()) {
            return null;
        }
        return (new \moodle_url('/mod/assign/view.php', [
            'id' => $assign->get_course_module()->id,
            'action' => 'grading',
        ]))->out(false);
    }

    /**
     * @param \assign $assign
     * @param int $userid
     * @return \stdClass|null
     */
    protected static function get_assign_submission(\assign $assign, int $userid): ?\stdClass {
        if ($assign->get_instance()->teamsubmission) {
            $submission = $assign->get_group_submission($userid, 0, false);
        } else {
            $submission = $assign->get_user_submission($userid, false);
        }
        return $submission ?: null;
    }

    /**
     * @param \assign $assign
     * @param \stdClass $submission
     * @return bool
     */
    protected static function assign_submission_has_content(\assign $assign, \stdClass $submission): bool {
        foreach ($assign->get_submission_plugins() as $plugin) {
            if (!$plugin->is_enabled()) {
                continue;
            }
            if (!in_array($plugin->get_type(), self::ASSIGN_CONTENT_TYPES, true)) {
                continue;
            }
            if (!$plugin->is_empty($submission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fallback primary status when assign has no file/online text plugins.
     *
     * @param \assign $assign
     * @param int $userid
     * @return \stdClass|null
     */
    protected static function assign_legacy_status(\assign $assign, int $userid): ?\stdClass {
        $submission = self::get_assign_submission($assign, $userid);
        if (!$submission || empty($submission->status)) {
            return null;
        }

        if ($submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            return self::status_line(
                self::STATE_SUBMITTED,
                get_string('progresssubmitted', 'local_homeschool'),
                self::assign_submissions_url($assign),
            );
        }

        if (in_array($submission->status, [
            ASSIGN_SUBMISSION_STATUS_DRAFT,
            ASSIGN_SUBMISSION_STATUS_REOPENED,
        ], true)) {
            if (!empty($submission->timemodified) && (int) $submission->timemodified > (int) $submission->timecreated) {
                return self::status_line(self::STATE_DRAFT, get_string('progressdraft', 'local_homeschool'));
            }
        }

        return null;
    }

    /**
     * @param \cm_info $cm
     * @param int $userid
     * @return \stdClass|null
     */
    protected static function quiz_status(\cm_info $cm, int $userid): ?\stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $finished = quiz_get_user_attempts($cm->instance, $userid, 'finished', true);
        $unfinished = quiz_get_user_attempts($cm->instance, $userid, 'unfinished', true);
        $count = count($finished) + count($unfinished);

        if ($count < 1) {
            return null;
        }

        return self::status_line(
            self::STATE_ATTEMPTED,
            get_string('progressattempted', 'local_homeschool', $count),
        );
    }

    /**
     * @param string $state
     * @param string $label
     * @param string|null $url Optional link target for the label
     * @return \stdClass
     */
    protected static function status_line(string $state, string $label, ?string $url = null): \stdClass {
        return (object) [
            'state' => $state,
            'label' => $label,
            'stateclass' => 'is-' . $state,
            'studentname' => '',
            'showname' => false,
            'url' => $url ?? '',
            'hasurl' => $url !== null && $url !== '',
        ];
    }
}
