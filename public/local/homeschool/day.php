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

/**
 * Homeschool day hub — manage activities, reminders, and settings for a day section.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/completionlib.php');

require_login();

\local_homeschool\local\return_context::purge_expired();

\local_homeschool\local\requirements::require_manage();

$context = context_system::instance();

$day = optional_param('day', null, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$showall = (bool) optional_param('showall', 0, PARAM_BOOL);
$showhidden = (bool) optional_param('showhidden', 0, PARAM_BOOL);
$expandreq = optional_param('expandreq', 0, PARAM_INT);

if (!\local_homeschool\local\requirements::daysections_available()) {
    throw new moodle_exception('missingdaysections', 'local_homeschool');
}

$courses = \local_homeschool\local\course_repository::get_managed_daysections_courses($USER->id, $showhidden);
$maxday = \local_homeschool\local\course_repository::get_max_day_number($courses);

$url = new moodle_url('/local/homeschool/day.php');
if ($day > 0) {
    $url->param('day', $day);
}
if ($showall) {
    $url->param('showall', 1);
}
if ($showhidden) {
    $url->param('showhidden', 1);
}

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('base');
$PAGE->set_primary_active_tab('local_homeschool');
if ($day > 0) {
    $PAGE->set_title(get_string('daytitle', 'local_homeschool', $day));
    $PAGE->set_heading(get_string('daytitle', 'local_homeschool', $day));
} else {
    $PAGE->set_title(get_string('openday', 'local_homeschool'));
    $PAGE->set_heading(get_string('openday', 'local_homeschool'));
}

if ($day !== null && $day < 1) {
    \core\notification::error(get_string('invaliddaynumber', 'local_homeschool'));
    redirect(new moodle_url('/local/homeschool/day.php'));
}
$day = $day ?? 0;

if ($day > 0 && ($maxday < 1 || $day > $maxday)) {
    \core\notification::error(get_string('invaliddaynumber', 'local_homeschool'));
    redirect(new moodle_url('/local/homeschool/day.php'));
}

$activities = [];
$allowedcmids = [];
$dateform = null;
$dateformhtml = '';

if ($day > 0) {
    $activities = \local_homeschool\local\activity_repository::get_activities_for_day($courses, $day);
    foreach ($activities as $activity) {
        $allowedcmids[(int) $activity->cmid] = true;
    }

    $dateform = new \local_homeschool\form\schedule_date_form($url, [
        'daynumber' => $day,
        'showall' => $showall,
        'showhidden' => $showhidden,
    ]);

    if ($dateform && optional_param('cleardates', null, PARAM_RAW) !== null) {
        require_sesskey();
        $cmids = optional_param_array('cmids', [], PARAM_INT);
        $cmids = array_values(array_filter($cmids, static function ($cmid) use ($allowedcmids) {
            return isset($allowedcmids[(int) $cmid]);
        }));

        if (empty($cmids)) {
            \core\notification::error(get_string('noactivitiesselected', 'local_homeschool'));
            redirect($url);
        }

        $result = \local_homeschool\local\day_scheduler::apply_to_activities($cmids, 0);
        \core\notification::success(get_string('datescleared', 'local_homeschool', $result->updated));
        redirect($url);
    }

    if ($data = $dateform->get_data()) {
        $cmids = optional_param_array('cmids', [], PARAM_INT);
        $cmids = array_values(array_filter($cmids, static function ($cmid) use ($allowedcmids) {
            return isset($allowedcmids[(int) $cmid]);
        }));

        if (empty($cmids)) {
            \core\notification::error(get_string('noactivitiesselected', 'local_homeschool'));
            redirect($url);
        }

        $result = \local_homeschool\local\day_scheduler::apply_to_activities($cmids, (int) $data->scheduledate);
        \core\notification::success(get_string('datesapplied', 'local_homeschool', (object) [
            'updated' => $result->updated,
            'day' => $day,
        ]));
        redirect($url);
    }

    if ($action === 'delete') {
        require_sesskey();

        $cmids = optional_param_array('cmids', [], PARAM_INT);
        if (empty($cmids)) {
            $singlecmid = optional_param('cmid', 0, PARAM_INT);
            if ($singlecmid) {
                $cmids = [$singlecmid];
            }
        }
        $cmids = array_values(array_filter($cmids, static function ($cmid) use ($allowedcmids) {
            return isset($allowedcmids[(int) $cmid]);
        }));

        if (empty($cmids)) {
            \core\notification::error(get_string('noactivitiesselected', 'local_homeschool'));
            redirect($url);
        }

        $deleted = 0;
        $deletedname = '';
        foreach ($cmids as $cmid) {
            $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
            $modcontext = context_module::instance($cm->id);
            require_capability('moodle/course:manageactivities', $modcontext);
            if ($deleted === 0) {
                $deletedname = $cm->name;
            }
            \core_courseformat\formatactions::cm($cm->course)->delete($cm->id);
            $deleted++;
        }

        if ($deleted === 1) {
            \core\notification::success(get_string('activitydeleted', 'local_homeschool', $deletedname));
        } else {
            \core\notification::success(get_string('activitiesdeleted', 'local_homeschool', $deleted));
        }
        redirect($url);
    }

    if ($action === 'updatedate') {
        require_sesskey();

        $cmid = required_param('cmid', PARAM_INT);

        if (!isset($allowedcmids[(int) $cmid])) {
            throw new moodle_exception('invalidactivity', 'local_homeschool');
        }

        if (optional_param('clearreminder', 0, PARAM_BOOL)) {
            \local_homeschool\local\day_scheduler::apply_to_activities([$cmid], 0);
            \core\notification::success(get_string('reminderdatecleared', 'local_homeschool'));
            redirect($url);
        }

        $datestr = required_param('scheduledate', PARAM_TEXT);

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $datestr, $matches)) {
            \core\notification::error(get_string('invalidreminderdate', 'local_homeschool'));
            redirect($url);
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $dayofmonth = (int) $matches[3];
        if (!checkdate($month, $dayofmonth, $year)) {
            \core\notification::error(get_string('invalidreminderdate', 'local_homeschool'));
            redirect($url);
        }

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        $completioninfo = new completion_info($course);
        if (!$completioninfo->is_enabled()) {
            throw new moodle_exception('completionnotenabledforcourse', 'completion');
        }

        if ((int) $cm->completion === COMPLETION_TRACKING_NONE) {
            \core\notification::error(get_string('datenotavailable', 'local_homeschool'));
            redirect($url);
        }

        [$hour, $minute] = \local_homeschool\local\reminder_time::get_new_reminder_hour_minute();
        if (!empty($cm->completionexpected)) {
            $hour = (int) userdate($cm->completionexpected, '%H');
            $minute = (int) userdate($cm->completionexpected, '%M');
        }

        $timestamp = make_timestamp(
            $year,
            $month,
            $dayofmonth,
            $hour,
            $minute,
        );

        \local_homeschool\local\day_scheduler::apply_to_activities([$cmid], $timestamp);
        \core\notification::success(get_string('reminderdateupdated', 'local_homeschool'));
        redirect($url);
    }

    if ($action === 'update') {
        require_sesskey();

        $cmid = required_param('cmid', PARAM_INT);

        if (!isset($allowedcmids[(int) $cmid])) {
            throw new moodle_exception('invalidactivity', 'local_homeschool');
        }

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $modinfo = get_fast_modinfo($cm->course);
        $cminfo = $modinfo->get_cm($cmid);
        $completioninfo = new completion_info($modinfo->get_course());
        $completionenabled = (bool) $completioninfo->is_enabled();

        $changed = false;

        if ($completionenabled) {
            $completion = required_param('completion', PARAM_INT);

            if (
                !in_array($completion, [
                    COMPLETION_TRACKING_NONE,
                    COMPLETION_TRACKING_MANUAL,
                    COMPLETION_TRACKING_AUTOMATIC,
                ], true)
            ) {
                throw new moodle_exception('invalidcompletion', 'local_homeschool');
            }

            $conditionstate = null;
            if ($completion == COMPLETION_TRACKING_AUTOMATIC) {
                // Disabled requirement fields are not posted when completion is locked.
                // Pass null so update_completion() keeps the existing conditions.
                if ($completioninfo->count_user_data($cminfo) === 0) {
                    $conditionstate = \local_homeschool\local\completion_conditions::read_posted_state($cminfo);
                    if (!\local_homeschool\local\completion_conditions::state_has_condition($conditionstate)) {
                        \core\notification::error(get_string('badautocompletion', 'completion'));
                        $failurl = new moodle_url($url);
                        $failurl->param('expandreq', $cmid);
                        redirect($failurl);
                    }
                }
            }

            try {
                if (
                    \local_homeschool\local\activity_updater::update_completion(
                        $cmid,
                        $completion,
                        $conditionstate,
                    )
                ) {
                    $changed = true;
                }
            } catch (moodle_exception $e) {
                if ($e->errorcode === 'badautocompletion') {
                    \core\notification::error(get_string('badautocompletion', 'completion'));
                    $failurl = new moodle_url($url);
                    $failurl->param('expandreq', $cmid);
                    redirect($failurl);
                }
                if ($e->errorcode === 'invalidcompletioncondition') {
                    \core\notification::error($e->getMessage());
                    $failurl = new moodle_url($url);
                    $failurl->param('expandreq', $cmid);
                    redirect($failurl);
                }
                throw $e;
            }
        } else if (optional_param('completion', (int) $cminfo->completion, PARAM_INT) !== (int) $cminfo->completion) {
            throw new moodle_exception('completionnotenabledforcourse', 'completion');
        }

        if ($cm->modname === 'assign') {
            $enabled = [];
            foreach (\local_homeschool\local\activity_repository::get_assign_submission_types($cminfo) as $submission) {
                if (optional_param('submission_' . $submission->type, 0, PARAM_BOOL)) {
                    $enabled[] = $submission->type;
                }
            }
            if (\local_homeschool\local\activity_updater::update_assign_submissions($cmid, $enabled)) {
                $changed = true;
            }
        }

        if ($changed) {
            \core\notification::success(get_string('activityupdated', 'local_homeschool'));
        } else {
            \core\notification::info(get_string('nochanges', 'local_homeschool'));
        }

        redirect($url);
    }
}

$dateformhtml = ($day > 0 && !empty($activities) && $dateform) ? $dateform->render() : '';
$renderable = new \local_homeschool\output\day_page(
    $day,
    $courses,
    $dateformhtml,
    $showall,
    $maxday,
    $expandreq,
    $showhidden,
);
$renderer = $PAGE->get_renderer('local_homeschool');

$PAGE->requires->js_call_amd('local_homeschool/day', 'init');
if ($day > 0 && !empty($courses)) {
    $PAGE->requires->js_call_amd('local_homeschool/addactivity', 'init');
}
if ($day > 0 && !empty($activities)) {
    $PAGE->requires->js_call_amd('local_homeschool/deleteactivity', 'init');
}

echo $OUTPUT->header();
echo $renderer->render($renderable);
echo $OUTPUT->footer();
