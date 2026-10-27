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

require_once($GLOBALS['CFG']->libdir . '/grouplib.php');

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

    /** @var array */
    protected static $sourceallowedcache = [];

    /** @var array */
    protected static $coursescapabilitycache = [];

    /** @var array */
    protected static $courseanycapabilitycache = [];

    /** @var array */
    protected static $groupsvisiblecache = [];

    /** @var array */
    protected static $cmgroupfieldscache = [];

    /** @var array */
    protected static $cmgroupfieldsloadedcourses = [];

    /** @var array<int, bool> cmid => whether the activity has embedded questions. */
    protected static $hasembedcache = [];

    /**
     * Clear request-level caches (also used between PHPUnit tests).
     */
    public static function reset_caches(): void {
        self::$sourceallowedcache = [];
        self::$coursescapabilitycache = [];
        self::$courseanycapabilitycache = [];
        self::$groupsvisiblecache = [];
        self::$cmgroupfieldscache = [];
        self::$cmgroupfieldsloadedcourses = [];
        self::$hasembedcache = [];
    }

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
        $hasembeds = self::activity_has_embedded_questions($cm);

        if ($cm->modname === 'assign') {
            global $CFG;
            require_once($CFG->dirroot . '/mod/assign/locallib.php');
            $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
            $hassubmissiontypes = self::assign_has_content_submission_types($assign);
        }

        $rows = [];
        foreach ($students as $student) {
            $userid = (int) $student->id;
            if (!self::can_view_any_progress_for_activity(
                $cm,
                $course,
                $completioninfo,
                $userid,
                (int) $activity->completion,
                $assign,
                $hassubmissiontypes,
                $hasembeds,
            )) {
                continue;
            }

            $lines = [];

            $primary = self::resolve_primary_status(
                $cm,
                $course,
                $completioninfo,
                $userid,
                (int) $activity->completion,
                $assign,
                $hassubmissiontypes,
                $hasembeds,
            );
            if ($primary !== null) {
                $lines[] = $primary;
            }

            if ($hassubmissiontypes && $assign !== null && self::can_view_assign_submission_for_user($assign, $course, $cm, $userid)) {
                $lines[] = self::assign_submission_line($assign, $userid);
            }

            if ($hasembeds && self::can_view_embed_progress_for_user($course, $cm, $userid)) {
                $lines[] = self::embed_submission_line($cm, $userid);
            }

            self::mark_optional_notsubmitted($lines);

            if ($lines === []) {
                $lines[] = self::status_line(
                    self::STATE_NOTSTARTED,
                    get_string('progressincomplete', 'local_homeschool'),
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
     * Whether the current user may display any progress source for this student/activity.
     *
     * @param \cm_info $cm
     * @param \stdClass $course
     * @param \completion_info $completioninfo
     * @param int $userid
     * @param int $tracking COMPLETION_TRACKING_*
     * @param \assign|null $assign
     * @param bool $hassubmissiontypes
     * @param bool $hasembeds
     * @return bool
     */
    protected static function can_view_any_progress_for_activity(
        \cm_info $cm,
        \stdClass $course,
        \completion_info $completioninfo,
        int $userid,
        int $tracking,
        $assign,
        bool $hassubmissiontypes,
        bool $hasembeds = false,
    ): bool {
        if ($tracking !== COMPLETION_TRACKING_NONE && $completioninfo->is_enabled($cm)
                && self::can_view_completion_for_user($course, $cm, $userid)) {
            return true;
        }

        if ($cm->modname === 'quiz' && self::can_view_quiz_attempts_for_user($course, $cm, $userid)) {
            return true;
        }

        if ($cm->modname === 'assign' && $assign !== null
                && self::can_view_assign_submission_for_user($assign, $course, $cm, $userid)) {
            return true;
        }

        if ($hasembeds && self::can_view_embed_progress_for_user($course, $cm, $userid)) {
            return true;
        }

        return false;
    }

    /**
     * @param \stdClass $course
     * @param \cm_info $cm
     * @param int $userid
     * @return bool
     */
    protected static function can_view_completion_for_user(\stdClass $course, \cm_info $cm, int $userid): bool {
        global $USER;

        if ($userid === (int) $USER->id) {
            return true;
        }

        $coursecontext = \context_course::instance($course->id);
        if (!has_capability('report/progress:view', $coursecontext)) {
            return false;
        }

        return groups_user_groups_visible($course, $userid, $cm);
    }

    /**
     * @param \assign $assign
     * @param \stdClass $course
     * @param \cm_info $cm
     * @param int $userid
     * @return bool
     */
    protected static function can_view_assign_submission_for_user(
        \assign $assign,
        \stdClass $course,
        \cm_info $cm,
        int $userid,
    ): bool {
        global $USER;

        if (!$assign->can_view_submission($userid)) {
            return false;
        }

        if ($userid === (int) $USER->id) {
            return true;
        }

        return groups_user_groups_visible($course, $userid, $cm);
    }

    /**
     * @param \stdClass $course
     * @param \cm_info $cm
     * @param int $userid
     * @return bool
     */
    protected static function can_view_quiz_attempts_for_user(\stdClass $course, \cm_info $cm, int $userid): bool {
        global $USER;

        if ($userid === (int) $USER->id) {
            return true;
        }

        if (!has_capability('mod/quiz:viewreports', $cm->context)) {
            return false;
        }

        return groups_user_groups_visible($course, $userid, $cm);
    }

    /**
     * @param \stdClass $course
     * @param \cm_info $cm
     * @param int $userid
     * @return bool
     */
    protected static function can_view_embed_progress_for_user(\stdClass $course, \cm_info $cm, int $userid): bool {
        global $USER;

        if (!self::embedquestion_available()) {
            return false;
        }

        if ($userid === (int) $USER->id) {
            return has_capability('report/embedquestion:viewmyprogress', $cm->context);
        }

        if (!has_capability('report/embedquestion:viewallprogress', $cm->context)) {
            return false;
        }

        return groups_user_groups_visible($course, $userid, $cm);
    }

    /**
     * Whether the current user may count this progress source for a child.
     *
     * Used by Currently on / incomplete days so bulk queries do not bypass
     * report/progress:view, assign submission access, quiz reports, or
     * activity-level separate groups.
     *
     * Avoids get_fast_modinfo and instantiating \assign per row: those hold
     * entire courses in memory and exhaust the request on the dashboard.
     *
     * @param string $source completion, assign, or quiz
     * @param int $courseid
     * @param int $cmid
     * @param int $userid
     * @return bool
     */
    public static function can_count_source_for_user(string $source, int $courseid, int $cmid, int $userid): bool {
        $key = $source . ':' . $cmid . ':' . $userid;
        if (array_key_exists($key, self::$sourceallowedcache)) {
            return self::$sourceallowedcache[$key];
        }

        $allowed = false;
        switch ($source) {
            case 'completion':
                $allowed = self::viewer_can_count_completion($courseid, $cmid, $userid);
                break;
            case 'assign':
                $allowed = self::viewer_can_count_assign($courseid, $cmid, $userid);
                break;
            case 'quiz':
                $allowed = self::viewer_can_count_quiz($courseid, $cmid, $userid);
                break;
        }

        self::$sourceallowedcache[$key] = $allowed;
        return $allowed;
    }

    /**
     * Whether leftovers for this activity may be listed for a child.
     *
     * @param \stdClass $candidate Row with courseid, cmid, completion, modname
     * @param int $userid
     * @return bool
     */
    public static function can_track_activity_for_user(\stdClass $candidate, int $userid): bool {
        $courseid = (int) $candidate->courseid;
        $cmid = (int) $candidate->cmid;
        $modname = (string) ($candidate->modname ?? '');
        if ((int) ($candidate->completion ?? 0) > 0
                && self::can_count_source_for_user('completion', $courseid, $cmid, $userid)) {
            return true;
        }
        if ($modname === 'assign' && self::can_count_source_for_user('assign', $courseid, $cmid, $userid)) {
            return true;
        }
        if ($modname === 'quiz' && self::can_count_source_for_user('quiz', $courseid, $cmid, $userid)) {
            return true;
        }
        return false;
    }

    /**
     * @param int $courseid
     * @param int $cmid
     * @param int $userid
     * @return bool
     */
    protected static function viewer_can_count_completion(int $courseid, int $cmid, int $userid): bool {
        global $USER;

        if ($userid === (int) $USER->id) {
            return true;
        }
        if (!self::viewer_has_course_capability($courseid, 'report/progress:view')) {
            return false;
        }
        return self::groups_visible_for_cmid($courseid, $cmid, $userid);
    }

    /**
     * @param int $courseid
     * @param int $cmid
     * @param int $userid
     * @return bool
     */
    protected static function viewer_can_count_assign(int $courseid, int $cmid, int $userid): bool {
        global $USER;

        if ($userid === (int) $USER->id) {
            return true;
        }
        if (!self::viewer_has_any_course_capability($courseid, ['mod/assign:viewgrades', 'mod/assign:grade'])) {
            return false;
        }
        return self::groups_visible_for_cmid($courseid, $cmid, $userid);
    }

    /**
     * @param int $courseid
     * @param int $cmid
     * @param int $userid
     * @return bool
     */
    protected static function viewer_can_count_quiz(int $courseid, int $cmid, int $userid): bool {
        global $USER;

        if ($userid === (int) $USER->id) {
            return true;
        }
        if (!self::viewer_has_course_capability($courseid, 'mod/quiz:viewreports')) {
            return false;
        }
        return self::groups_visible_for_cmid($courseid, $cmid, $userid);
    }

    /**
     * @param int $courseid
     * @param string $capability
     * @return bool
     */
    protected static function viewer_has_course_capability(int $courseid, string $capability): bool {
        $key = $courseid . ':' . $capability;
        if (!array_key_exists($key, self::$coursescapabilitycache)) {
            self::$coursescapabilitycache[$key] = has_capability($capability, \context_course::instance($courseid));
        }
        return self::$coursescapabilitycache[$key];
    }

    /**
     * @param int $courseid
     * @param string[] $capabilities
     * @return bool
     */
    protected static function viewer_has_any_course_capability(int $courseid, array $capabilities): bool {
        $key = $courseid . ':' . implode(',', $capabilities);
        if (!array_key_exists($key, self::$courseanycapabilitycache)) {
            self::$courseanycapabilitycache[$key] = has_any_capability($capabilities, \context_course::instance($courseid));
        }
        return self::$courseanycapabilitycache[$key];
    }

    /**
     * Activity-level separate groups, without loading course modinfo.
     *
     * @param int $courseid
     * @param int $cmid
     * @param int $userid
     * @return bool
     */
    protected static function groups_visible_for_cmid(int $courseid, int $cmid, int $userid): bool {
        $key = $cmid . ':' . $userid;
        if (array_key_exists($key, self::$groupsvisiblecache)) {
            return self::$groupsvisiblecache[$key];
        }

        $course = get_course($courseid);
        $cm = self::cm_group_fields($courseid, $cmid);
        if ($cm === null) {
            self::$groupsvisiblecache[$key] = false;
            return false;
        }

        self::$groupsvisiblecache[$key] = groups_user_groups_visible($course, $userid, $cm);
        return self::$groupsvisiblecache[$key];
    }

    /**
     * Lightweight course_modules fields needed for groups_user_groups_visible.
     *
     * @param int $courseid
     * @param int $cmid
     * @return \stdClass|null
     */
    protected static function cm_group_fields(int $courseid, int $cmid): ?\stdClass {
        if (!isset(self::$cmgroupfieldsloadedcourses[$courseid])) {
            global $DB;
            $records = $DB->get_records(
                'course_modules',
                ['course' => $courseid],
                '',
                'id, course, groupmode, groupingid',
            );
            foreach ($records as $id => $record) {
                self::$cmgroupfieldscache[(int) $id] = $record;
            }
            self::$cmgroupfieldsloadedcourses[$courseid] = true;
        }

        return self::$cmgroupfieldscache[$cmid] ?? null;
    }

    /**
     * Primary status line (completion / attempts). Assign/embed submission is a separate line.
     *
     * @param \cm_info $cm
     * @param \stdClass $course
     * @param \completion_info $completioninfo
     * @param int $userid
     * @param int $tracking COMPLETION_TRACKING_*
     * @param \assign|null $assign
     * @param bool $hassubmissiontypes
     * @param bool $hasembeds
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
        bool $hasembeds = false,
    ): ?\stdClass {
        // File/online text or embeds get their own line; keep completion on the primary line.
        $hassecondarysubmission = $hassubmissiontypes || $hasembeds;

        if ($tracking !== COMPLETION_TRACKING_NONE && $completioninfo->is_enabled($cm)
                && self::can_view_completion_for_user($course, $cm, $userid)) {
            $data = $completioninfo->get_data($cm, false, $userid);
            $state = (int) ($data->completionstate ?? COMPLETION_INCOMPLETE);
            if (in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                return self::status_line(self::STATE_COMPLETE, get_string('progresscomplete', 'local_homeschool'));
            }
            if (in_array($state, [COMPLETION_COMPLETE_FAIL, COMPLETION_COMPLETE_FAIL_HIDDEN], true)) {
                return self::status_line(self::STATE_FAILED, get_string('progressfailed', 'local_homeschool'));
            }
            // Unfinished completion stays Incomplete even when embeds/files were submitted.
            if ($hassecondarysubmission) {
                return self::status_line(self::STATE_NOTSTARTED, get_string('progressincomplete', 'local_homeschool'));
            }
            // Otherwise fall through for quiz attempts / assign without file|onlinetext|embeds.
        }

        if ($cm->modname === 'quiz' && self::can_view_quiz_attempts_for_user($course, $cm, $userid)) {
            return self::quiz_status($cm, $userid);
        }

        // Assign without a dedicated submission line: keep submission-ish status on the primary line.
        if ($cm->modname === 'assign' && !$hassecondarysubmission && $assign !== null
                && self::can_view_assign_submission_for_user($assign, $course, $cm, $userid)) {
            $legacy = self::assign_legacy_status($assign, $userid);
            if ($legacy !== null) {
                return $legacy;
            }
        }

        if ($hassecondarysubmission) {
            // Submission covered by the dedicated line.
            return null;
        }

        return self::status_line(self::STATE_NOTSTARTED, get_string('progressincomplete', 'local_homeschool'));
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

        // File/online text can be skipped when the activity is already complete.
        return self::status_line(
            self::STATE_NOTSUBMITTED,
            get_string('progressnotsubmitted', 'local_homeschool'),
            null,
            true,
        );
    }

    /**
     * Whether filter/report embedquestion are available on this site.
     *
     * @return bool
     */
    protected static function embedquestion_available(): bool {
        return class_exists(\filter_embedquestion\text_filter::class)
            && class_exists(\report_embedquestion\attempt_tracker::class);
    }

    /**
     * Whether this activity contains (or has ever recorded) embedded questions.
     *
     * @param \cm_info $cm
     * @return bool
     */
    protected static function activity_has_embedded_questions(\cm_info $cm): bool {
        $cmid = (int) $cm->id;
        if (array_key_exists($cmid, self::$hasembedcache)) {
            return self::$hasembedcache[$cmid];
        }

        if (!self::embedquestion_available()) {
            self::$hasembedcache[$cmid] = false;
            return false;
        }

        $has = self::activity_content_has_embed($cm);
        if (!$has) {
            global $DB;
            $has = $DB->record_exists('report_embedquestion_attempt', ['contextid' => $cm->context->id]);
        }

        self::$hasembedcache[$cmid] = $has;
        return $has;
    }

    /**
     * Scan activity content for {Q{...}Q} embed tokens.
     *
     * @param \cm_info $cm
     * @return bool
     */
    protected static function activity_content_has_embed(\cm_info $cm): bool {
        global $DB;

        $modname = $cm->modname;
        $instance = (int) $cm->instance;
        $needle = '{Q{';

        if (plugin_supports('mod', $modname, FEATURE_MOD_INTRO)) {
            $intro = $DB->get_field($modname, 'intro', ['id' => $instance]);
            if (is_string($intro) && str_contains($intro, $needle)) {
                return true;
            }
        }

        switch ($modname) {
            case 'assign':
                $activity = $DB->get_field('assign', 'activity', ['id' => $instance]);
                return is_string($activity) && str_contains($activity, $needle);
            case 'page':
                $content = $DB->get_field('page', 'content', ['id' => $instance]);
                return is_string($content) && str_contains($content, $needle);
            case 'lesson':
                return $DB->record_exists_select(
                    'lesson_pages',
                    'lessonid = ? AND ' . $DB->sql_like('contents', '?'),
                    [$instance, '%' . $DB->sql_like_escape($needle) . '%'],
                );
            case 'book':
                return $DB->record_exists_select(
                    'book_chapters',
                    'bookid = ? AND ' . $DB->sql_like('content', '?'),
                    [$instance, '%' . $DB->sql_like_escape($needle) . '%'],
                );
            default:
                return false;
        }
    }

    /**
     * Embedded-question attempt line (Submitted / Not submitted).
     *
     * Opening an embed creates a “Not yet answered” (todo) step; that alone is
     * not a submission. Only a non-todo step counts as answered.
     *
     * Unlike assignment file/online text, a missing embed answer stays a problem
     * even when the activity is already marked complete.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return \stdClass
     */
    protected static function embed_submission_line(\cm_info $cm, int $userid): \stdClass {
        $usageid = self::latest_answered_embed_usageid($cm, $userid);

        if ($usageid !== null) {
            $url = (new \moodle_url('/report/embedquestion/activity.php', [
                'cmid' => $cm->id,
                'userid' => $userid,
                'usageid' => $usageid,
            ]))->out(false);
            return self::status_line(
                self::STATE_SUBMITTED,
                get_string('progresssubmitted', 'local_homeschool'),
                $url,
            );
        }

        return self::status_line(
            self::STATE_NOTSUBMITTED,
            get_string('progressnotsubmitted', 'local_homeschool'),
        );
    }

    /**
     * Latest question usage for this user/activity that has a real (non-todo) answer.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return int|null
     */
    protected static function latest_answered_embed_usageid(\cm_info $cm, int $userid): ?int {
        global $DB;

        $usageid = $DB->get_field_sql(
            "SELECT ra.questionusageid
               FROM {report_embedquestion_attempt} ra
               JOIN {question_attempts} qa ON qa.questionusageid = ra.questionusageid
               JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
              WHERE ra.userid = ? AND ra.contextid = ?
                    AND qas.state <> ?
           ORDER BY qas.timecreated DESC, ra.timemodified DESC, ra.id DESC",
            [$userid, $cm->context->id, 'todo'],
            IGNORE_MULTIPLE,
        );

        return $usageid !== false ? (int) $usageid : null;
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
     * When work is already complete, optional unsubmitted lines (e.g. assign file)
     * are de-emphasised. Embedded-question “Not submitted” stays highlighted.
     *
     * @param \stdClass[] $lines
     * @return void
     */
    protected static function mark_optional_notsubmitted(array $lines): void {
        $complete = false;
        foreach ($lines as $line) {
            if ($line->state === self::STATE_COMPLETE) {
                $complete = true;
                break;
            }
        }
        if (!$complete) {
            return;
        }

        foreach ($lines as $line) {
            if ($line->state !== self::STATE_NOTSUBMITTED) {
                continue;
            }
            if (empty($line->optionalwhencomplete)) {
                continue;
            }
            $line->needsattention = false;
            $line->stateclass = 'is-notsubmitted is-optional';
            $line->labelclass = 'local-homeschool-progress-label badge text-bg-secondary';
        }
    }

    /**
     * @param string $state
     * @param string $label
     * @param string|null $url Optional link target for the label
     * @param bool $optionalwhencomplete Soften Not submitted when already complete
     * @return \stdClass
     */
    protected static function status_line(
        string $state,
        string $label,
        ?string $url = null,
        bool $optionalwhencomplete = false,
    ): \stdClass {
        $needsattention = in_array($state, [self::STATE_NOTSTARTED, self::STATE_NOTSUBMITTED], true);
        $labelclass = 'local-homeschool-progress-label';
        if ($needsattention) {
            $labelclass .= ' badge text-bg-danger';
        }

        return (object) [
            'state' => $state,
            'label' => $label,
            'labelclass' => $labelclass,
            'stateclass' => 'is-' . $state,
            'needsattention' => $needsattention,
            'optionalwhencomplete' => $optionalwhencomplete,
            'studentname' => '',
            'showname' => false,
            'url' => $url ?? '',
            'hasurl' => $url !== null && $url !== '',
        ];
    }
}
