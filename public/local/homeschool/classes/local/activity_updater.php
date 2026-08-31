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
 * Per-activity completion and assignment submission updates.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_updater {

    /**
     * Update completion tracking and automatic conditions for one activity.
     *
     * @param int $cmid
     * @param int $completion COMPLETION_TRACKING_*
     * @param array|null $conditionstate from completion_conditions::read_posted_state(); null = leave conditions
     * @return bool
     */
    public static function update_completion(int $cmid, int $completion, ?array $conditionstate = null): bool {
        global $DB;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        $context = \context_module::instance($cm->id);
        require_capability('moodle/course:manageactivities', $context);

        if (!plugin_supports('mod', $cm->modname, FEATURE_COMPLETION, true)) {
            throw new \moodle_exception('cannoteditcompletion', 'local_homeschool');
        }

        $modinfo = get_fast_modinfo($course);
        $cminfo = $modinfo->get_cm($cmid);

        $completioninfo = new \completion_info($course);
        $locked = $completioninfo->count_user_data($cminfo) > 0;

        $completionchanged = (int) $cminfo->completion !== (int) $completion;
        if ($completionchanged && $locked) {
            throw new \moodle_exception('completionlocked', 'local_homeschool');
        }

        if ($completion == COMPLETION_TRACKING_AUTOMATIC) {
            if ($conditionstate === null) {
                $conditionstate = completion_conditions::snapshot_state($cminfo);
            }
            if (!completion_conditions::state_has_condition($conditionstate)) {
                throw new \moodle_exception('badautocompletion', 'completion');
            }
            if ($locked) {
                // Conditions themselves are locked when completion data exists.
                $conditionstate = null;
            }
        } else {
            // Leave existing condition fields when leaving automatic tracking.
            $conditionstate = null;
        }

        $changed = false;

        if ($completionchanged) {
            $DB->set_field('course_modules', 'completion', $completion, ['id' => $cm->id]);
            $changed = true;
        }

        if ($conditionstate !== null) {
            if (completion_conditions::apply($cminfo, $conditionstate)) {
                $changed = true;
            }
        }

        // Reminder dates only apply when completion tracking is enabled.
        $calendartime = !empty($cminfo->completionexpected) ? $cminfo->completionexpected : null;
        if ($completion == COMPLETION_TRACKING_NONE && !empty($cminfo->completionexpected)) {
            $DB->set_field('course_modules', 'completionexpected', 0, ['id' => $cm->id]);
            $calendartime = null;
            $changed = true;
        }

        if (!$changed) {
            return false;
        }

        \core_completion\api::update_completion_date_event(
            $cm->id,
            $cm->modname,
            $cm->instance,
            $calendartime,
        );

        rebuild_course_cache($course->id, true);
        $modinfo = get_fast_modinfo($course);
        $completioninfo = new \completion_info($modinfo->get_course());
        $completioninfo->reset_all_state($modinfo->get_cm($cmid));

        \core\event\course_module_updated::create_from_cm($cminfo, $context)->trigger();

        return true;
    }

    /**
     * Update enabled submission types for an assignment.
     *
     * @param int $cmid
     * @param string[] $enabledtypes plugin type names (e.g. onlinetext, file)
     * @return bool
     */
    public static function update_assign_submissions(int $cmid, array $enabledtypes): bool {
        global $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        $context = \context_module::instance($cm->id);
        require_capability('moodle/course:manageactivities', $context);

        $modinfo = get_fast_modinfo($course);
        $cminfo = $modinfo->get_cm($cmid);
        $assign = new \assign($context, $cminfo, $course);

        foreach ($assign->get_submission_plugins() as $plugin) {
            if (!$plugin->is_visible() || !$plugin->is_configurable()) {
                continue;
            }
            $type = $plugin->get_type();
            $enabled = in_array($type, $enabledtypes, true) ? 1 : 0;
            $plugin->set_config('enabled', $enabled);
        }

        return true;
    }
}
