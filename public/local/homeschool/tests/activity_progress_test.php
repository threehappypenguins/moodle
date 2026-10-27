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

global $CFG;

require_once($CFG->dirroot . '/local/homeschool/tests/base_testcase.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->libdir . '/questionlib.php');

/**
 * Tests for per-child activity progress on the day page.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_homeschool\local\activity_progress
 */
final class activity_progress_test extends \local_homeschool\base_testcase {

    /**
     * Skip when filter/report embedquestion are not installed.
     */
    protected function require_embedquestion(): void {
        if (!class_exists(\filter_embedquestion\text_filter::class)
                || !class_exists(\report_embedquestion\attempt_tracker::class)) {
            $this->markTestSkipped('filter_embedquestion / report_embedquestion not installed');
        }
    }

    /**
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass} teacher, course, student
     */
    protected function setup_course_with_student(): array {
        global $DB;

        $this->enable_completion_globally();
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_user();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $student = $generator->create_user(['firstname' => 'Ada', 'lastname' => 'Child']);

        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $generator->enrol_user($teacher->id, $course->id, $teacherrole->id);
        $generator->enrol_user($student->id, $course->id, $studentrole->id);
        $this->setUser($teacher);

        return [$teacher, $course, $student];
    }

    /**
     * Placeholder embed token — detection only looks for the {Q{ prefix.
     *
     * @return string
     */
    protected function sample_embed_code(): string {
        return '{Q{cat/q|faketoken}Q}';
    }

    /**
     * @param \stdClass $course
     * @param array $options
     * @return \stdClass assign module record with cmid
     */
    protected function create_assign_with_embed(\stdClass $course, array $options = []): \stdClass {
        $defaults = [
            'course' => $course->id,
            'intro' => '<p>Work: ' . $this->sample_embed_code() . '</p>',
            'introformat' => FORMAT_HTML,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'assignsubmission_onlinetext_enabled' => 0,
            'assignsubmission_file_enabled' => 0,
        ];
        return $this->getDataGenerator()->create_module('assign', array_merge($defaults, $options));
    }

    /**
     * Create an opened-but-unanswered embed attempt (“Not yet answered” / todo only).
     *
     * @param \stdClass $course
     * @param \cm_info $cm
     * @param \stdClass $student
     * @return int question usage id
     */
    protected function create_todo_embed_attempt(\stdClass $course, \cm_info $cm, \stdClass $student): int {
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $qbankcontext = \context_module::instance($qbank->cmid);
        /** @var \filter_embedquestion_generator $embedgenerator */
        $embedgenerator = $this->getDataGenerator()->get_plugin_generator('filter_embedquestion');
        $question = $embedgenerator->create_embeddable_question('truefalse', null, null, [
            'contextid' => $qbankcontext->id,
        ]);
        $attempt = $embedgenerator->create_attempt_at_embedded_question(
            $question,
            $student,
            'True',
            $cm->context,
            null,
            1,
            false,
        );
        return $attempt->get_question_usage()->get_id();
    }

    /**
     * Create a finished (answered) embed attempt.
     *
     * @param \stdClass $course
     * @param \cm_info $cm
     * @param \stdClass $student
     * @return int question usage id
     */
    protected function create_answered_embed_attempt(\stdClass $course, \cm_info $cm, \stdClass $student): int {
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $qbankcontext = \context_module::instance($qbank->cmid);
        /** @var \filter_embedquestion_generator $embedgenerator */
        $embedgenerator = $this->getDataGenerator()->get_plugin_generator('filter_embedquestion');
        $question = $embedgenerator->create_embeddable_question('truefalse', null, null, [
            'contextid' => $qbankcontext->id,
        ]);
        $attempt = $embedgenerator->create_attempt_at_embedded_question(
            $question,
            $student,
            'True',
            $cm->context,
        );
        return $attempt->get_question_usage()->get_id();
    }

    /**
     * Complete + no embed attempt still shows a red Not submitted line.
     */
    public function test_complete_without_embed_attempt_shows_not_submitted(): void {
        $this->require_embedquestion();
        [, $course, $student] = $this->setup_course_with_student();

        $assign = $this->create_assign_with_embed($course);
        $modinfo = get_fast_modinfo($course->id);
        $cm = $modinfo->get_cm($assign->cmid);

        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);

        $activity = (object) [
            'cmid' => $assign->cmid,
            'courseid' => $course->id,
            'modname' => 'assign',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ];
        $rows = activity_progress::export_for_activity($activity, [$student->id => $student]);

        $this->assertCount(2, $rows);
        $this->assertSame(activity_progress::STATE_COMPLETE, $rows[0]->state);
        $this->assertSame(activity_progress::STATE_NOTSUBMITTED, $rows[1]->state);
        $this->assertTrue($rows[1]->needsattention);
        $this->assertStringContainsString('text-bg-danger', $rows[1]->labelclass);
        $this->assertFalse($rows[1]->hasurl);
    }

    /**
     * An answered embed attempt becomes a Submitted link to the student's attempt page.
     *
     * Incomplete completion stays visible even when the embed was answered.
     */
    public function test_embed_attempt_links_to_usage_page(): void {
        $this->require_embedquestion();
        [, $course, $student] = $this->setup_course_with_student();

        $assign = $this->create_assign_with_embed($course);
        $modinfo = get_fast_modinfo($course->id);
        $cm = $modinfo->get_cm($assign->cmid);
        $usageid = $this->create_answered_embed_attempt($course, $cm, $student);

        $activity = (object) [
            'cmid' => $assign->cmid,
            'courseid' => $course->id,
            'modname' => 'assign',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ];
        $rows = activity_progress::export_for_activity($activity, [$student->id => $student]);

        $this->assertCount(2, $rows);
        $this->assertSame(activity_progress::STATE_NOTSTARTED, $rows[0]->state);
        $this->assertSame(get_string('progressincomplete', 'local_homeschool'), $rows[0]->label);
        $this->assertTrue($rows[0]->needsattention);

        $submitted = $rows[1];
        $this->assertSame(activity_progress::STATE_SUBMITTED, $submitted->state);
        $this->assertTrue($submitted->hasurl);

        $expected = (new \moodle_url('/report/embedquestion/activity.php', [
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'usageid' => $usageid,
        ]))->out(false);
        $this->assertSame($expected, $submitted->url);
    }

    /**
     * Opening an embed without answering (“Not yet answered”) is still Not submitted.
     */
    public function test_todo_only_embed_attempt_is_not_submitted(): void {
        $this->require_embedquestion();
        [, $course, $student] = $this->setup_course_with_student();

        $assign = $this->create_assign_with_embed($course);
        $modinfo = get_fast_modinfo($course->id);
        $cm = $modinfo->get_cm($assign->cmid);
        $this->create_todo_embed_attempt($course, $cm, $student);

        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);

        $activity = (object) [
            'cmid' => $assign->cmid,
            'courseid' => $course->id,
            'modname' => 'assign',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ];
        $rows = activity_progress::export_for_activity($activity, [$student->id => $student]);

        $this->assertCount(2, $rows);
        $this->assertSame(activity_progress::STATE_COMPLETE, $rows[0]->state);
        $this->assertSame(activity_progress::STATE_NOTSUBMITTED, $rows[1]->state);
        $this->assertTrue($rows[1]->needsattention);
    }

    /**
     * Activities without embed codes do not get an embed Not submitted line.
     */
    public function test_no_embed_line_without_embed_code(): void {
        $this->require_embedquestion();
        [, $course, $student] = $this->setup_course_with_student();

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'intro' => '<p>No embeds here</p>',
            'completion' => COMPLETION_TRACKING_MANUAL,
            'assignsubmission_onlinetext_enabled' => 0,
            'assignsubmission_file_enabled' => 0,
        ]);
        $modinfo = get_fast_modinfo($course->id);
        $cm = $modinfo->get_cm($assign->cmid);
        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);

        $activity = (object) [
            'cmid' => $assign->cmid,
            'courseid' => $course->id,
            'modname' => 'assign',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ];
        $rows = activity_progress::export_for_activity($activity, [$student->id => $student]);

        $this->assertCount(1, $rows);
        $this->assertSame(activity_progress::STATE_COMPLETE, $rows[0]->state);
    }

    /**
     * Assign file Not submitted softens when complete; embed Not submitted stays red.
     */
    public function test_embed_not_submitted_stays_urgent_when_complete(): void {
        $this->require_embedquestion();
        [, $course, $student] = $this->setup_course_with_student();

        $assign = $this->create_assign_with_embed($course, [
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 0,
        ]);
        $modinfo = get_fast_modinfo($course->id);
        $cm = $modinfo->get_cm($assign->cmid);
        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);

        $activity = (object) [
            'cmid' => $assign->cmid,
            'courseid' => $course->id,
            'modname' => 'assign',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ];
        $rows = activity_progress::export_for_activity($activity, [$student->id => $student]);

        $this->assertCount(3, $rows);
        $this->assertSame(activity_progress::STATE_COMPLETE, $rows[0]->state);

        $this->assertSame(activity_progress::STATE_NOTSUBMITTED, $rows[1]->state);
        $this->assertFalse($rows[1]->needsattention);
        $this->assertStringContainsString('is-optional', $rows[1]->stateclass);

        $this->assertSame(activity_progress::STATE_NOTSUBMITTED, $rows[2]->state);
        $this->assertTrue($rows[2]->needsattention);
        $this->assertStringContainsString('text-bg-danger', $rows[2]->labelclass);
    }

    /**
     * Quiz attempt counts link to that student's overview results report.
     */
    public function test_quiz_attempts_link_to_student_overview(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        [, $course, $student] = $this->setup_course_with_student();

        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'questionsperpage' => 0,
            'grade' => 100.0,
            'sumgrades' => 1,
            'completion' => COMPLETION_TRACKING_NONE,
        ]);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('truefalse', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);

        /** @var \mod_quiz_generator $quizgenerator */
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quizgenerator->create_attempt($quiz->id, $student->id);

        $activity = (object) [
            'cmid' => $quiz->cmid,
            'courseid' => $course->id,
            'modname' => 'quiz',
            'completion' => COMPLETION_TRACKING_NONE,
        ];
        $rows = activity_progress::export_for_activity($activity, [$student->id => $student]);

        $this->assertCount(1, $rows);
        $this->assertSame(activity_progress::STATE_ATTEMPTED, $rows[0]->state);
        $this->assertTrue($rows[0]->hasurl);
        $expected = (new \moodle_url('/mod/quiz/report.php', [
            'id' => $quiz->cmid,
            'mode' => 'overview',
            'userid' => $student->id,
        ]))->out(false);
        $this->assertSame($expected, $rows[0]->url);
    }

    /**
     * Quizzes show only the attempt link, even when marked complete (unlike assigns/embeds).
     */
    public function test_quiz_complete_shows_attempts_only(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        [, $course, $student] = $this->setup_course_with_student();

        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'questionsperpage' => 0,
            'grade' => 100.0,
            'sumgrades' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('truefalse', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);

        /** @var \mod_quiz_generator $quizgenerator */
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quizgenerator->create_attempt($quiz->id, $student->id);

        $modinfo = get_fast_modinfo($course->id);
        $cm = $modinfo->get_cm($quiz->cmid);
        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);

        $activity = (object) [
            'cmid' => $quiz->cmid,
            'courseid' => $course->id,
            'modname' => 'quiz',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ];
        $rows = activity_progress::export_for_activity($activity, [$student->id => $student]);

        $this->assertCount(1, $rows);
        $this->assertSame(activity_progress::STATE_ATTEMPTED, $rows[0]->state);
        $this->assertTrue($rows[0]->hasurl);
        $expected = (new \moodle_url('/mod/quiz/report.php', [
            'id' => $quiz->cmid,
            'mode' => 'overview',
            'userid' => $student->id,
        ]))->out(false);
        $this->assertSame($expected, $rows[0]->url);
    }
}
