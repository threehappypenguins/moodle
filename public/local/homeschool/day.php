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

$day = optional_param('day', 0, PARAM_INT);
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

if ($day < 0 || (array_key_exists('day', $_GET) && $day < 1)) {
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
        $cmids = array_values(array_filter($cmids, static function($cmid) use ($allowedcmids) {
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
        $cmids = array_values(array_filter($cmids, static function($cmid) use ($allowedcmids) {
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
        $cmids = array_values(array_filter($cmids, static function($cmid) use ($allowedcmids) {
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

            if (!in_array($completion, [
                COMPLETION_TRACKING_NONE,
                COMPLETION_TRACKING_MANUAL,
                COMPLETION_TRACKING_AUTOMATIC,
            ], true)) {
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
                if (\local_homeschool\local\activity_updater::update_completion(
                    $cmid,
                    $completion,
                    $conditionstate,
                )) {
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

$PAGE->requires->js_init_code(<<<'JS'
(function() {
    var daySelect = document.getElementById('local-homeschool-day');
    if (daySelect) {
        daySelect.addEventListener('change', function() {
            daySelect.form.submit();
        });
    }

    var showAll = document.getElementById('local-homeschool-showall');
    if (showAll) {
        showAll.addEventListener('change', function() {
            showAll.form.submit();
        });
    }

    var root = document.querySelector('.local-homeschool-day');
    if (!root) {
        return;
    }

    var syncBulkDeleteFromSelection = function() {
        var bulkDelete = root.querySelector('[data-action="local-homeschool-delete-selected"]');
        if (!bulkDelete) {
            return;
        }
        bulkDelete.disabled = root.querySelectorAll('.local-homeschool-select-cm:checked').length === 0;
    };

    var setMultiselectLock = function(container, locked) {
        if (!container) {
            return;
        }
        if (locked) {
            container.setAttribute('inert', '');
            container.querySelectorAll('input, select, textarea, button, fieldset').forEach(function(el) {
                if (el.type === 'hidden') {
                    return;
                }
                if (el.disabled) {
                    el.setAttribute('data-multiselect-was-disabled', '1');
                    return;
                }
                el.disabled = true;
                el.setAttribute('data-multiselect-disabled', '1');
            });
            return;
        }
        container.removeAttribute('inert');
        container.querySelectorAll('[data-multiselect-disabled]').forEach(function(el) {
            el.disabled = false;
            el.removeAttribute('data-multiselect-disabled');
        });
        container.querySelectorAll('[data-multiselect-was-disabled]').forEach(function(el) {
            el.removeAttribute('data-multiselect-was-disabled');
        });
    };

    var updateRowEditing = function() {
        var selected = root.querySelectorAll('.local-homeschool-select-cm:checked').length;
        var multi = selected >= 2;
        root.classList.toggle('local-homeschool-multiselect', multi);
        var hint = root.querySelector('.local-homeschool-multiselect-hint');
        if (hint) {
            hint.hidden = !multi;
        }
        root.querySelectorAll('.local-homeschool-activity').forEach(function(row) {
            var checkbox = row.querySelector('.local-homeschool-select-cm');
            var isselected = !!(checkbox && checkbox.checked);
            row.classList.toggle('is-selected', isselected);
            setMultiselectLock(row.querySelector('.local-homeschool-activity-details'), multi && isselected);
        });
        syncBulkDeleteFromSelection();
    };

    var syncDateEditable = function(row, editable) {
        if (!row) {
            return;
        }
        var dateForm = row.querySelector('.local-homeschool-row-date');
        if (!dateForm) {
            return;
        }
        dateForm.classList.toggle('is-disabled', !editable);
        if (editable) {
            dateForm.removeAttribute('title');
        } else {
            dateForm.setAttribute('title', dateForm.getAttribute('data-disabled-title') || '');
        }
        dateForm.querySelectorAll('input, button').forEach(function(el) {
            if (el.type === 'hidden') {
                return;
            }
            el.disabled = !editable;
        });
    };

    var syncRequirementExtras = function(form) {
        var gradeReq = form.querySelector('.local-homeschool-requirement[data-requirement="completionusegrade"]');
        var passgrade = form.querySelector('.local-homeschool-passgrade');
        if (gradeReq && passgrade) {
            passgrade.hidden = !gradeReq.checked;
        }
        var passSelected = form.querySelector('.local-homeschool-passgrade-radio:checked');
        var exhausted = form.querySelector('.local-homeschool-exhausted');
        if (exhausted) {
            exhausted.hidden = !(gradeReq && gradeReq.checked && passSelected && passSelected.value === '1');
        }
        form.querySelectorAll('.local-homeschool-requirement[data-requirement-type="int"]').forEach(function(cb) {
            var valueInput = form.querySelector('[data-requirement-value-for="' + cb.getAttribute('data-requirement') + '"]');
            if (valueInput) {
                valueInput.hidden = !cb.checked;
                if (cb.checked && (!valueInput.value || parseInt(valueInput.value, 10) < 1)) {
                    valueInput.value = '1';
                }
            }
        });
    };

    root.querySelectorAll('.local-homeschool-row-date.is-disabled').forEach(function(dateForm) {
        dateForm.setAttribute('data-disabled-title', dateForm.getAttribute('title') || '');
    });

    root.addEventListener('change', function(event) {
        if (event.target.classList.contains('local-homeschool-select-cm') ||
                event.target.id === 'local-homeschool-selectall') {
            if (event.target.id === 'local-homeschool-selectall') {
                var checked = event.target.checked;
                root.querySelectorAll('.local-homeschool-select-cm').forEach(function(cb) {
                    cb.checked = checked;
                });
            }
            updateRowEditing();
            return;
        }

        if (root.classList.contains('local-homeschool-multiselect')) {
            var selectedRow = event.target.closest('.local-homeschool-activity.is-selected');
            // Settings on selected rows are locked while multi-selecting; dates stay editable.
            if (selectedRow && !event.target.classList.contains('local-homeschool-date-input')) {
                return;
            }
        }

        if (event.target.classList.contains('local-homeschool-autosave') ||
                event.target.classList.contains('local-homeschool-date-input') ||
                event.target.classList.contains('local-homeschool-requirement-value')) {
            var form = event.target.closest('form');
            if (!form) {
                return;
            }

            var cmidInput = form.querySelector('input[name="cmid"]');
            var cmid = cmidInput ? cmidInput.value : '';
            var requirements = form.querySelector('.local-homeschool-requirements');
            var completionRadio = event.target.classList.contains('local-homeschool-completion-radio')
                ? event.target
                : null;
            var automaticSelected = false;
            var selectedCompletion = form.querySelector('input[name="completion"]:checked');
            if (selectedCompletion) {
                automaticSelected = selectedCompletion.value === '2';
            } else {
                var hiddenCompletion = form.querySelector('input[name="completion"][type="hidden"]');
                automaticSelected = hiddenCompletion && hiddenCompletion.value === '2';
            }

            if (completionRadio) {
                var summaryText = form.querySelector('.local-homeschool-completion-summary-text');
                if (summaryText) {
                    var label = completionRadio.closest('label');
                    var labelSpan = label ? label.querySelector('span') : null;
                    if (labelSpan) {
                        summaryText.textContent = labelSpan.textContent;
                    }
                }
                var picker = form.querySelector('.local-homeschool-completion-picker');
                if (picker) {
                    picker.open = false;
                }
                if (requirements) {
                    requirements.hidden = !automaticSelected;
                    if (automaticSelected) {
                        requirements.open = true;
                    }
                }
                var activityRow = form.closest('.local-homeschool-activity');
                syncDateEditable(activityRow, selectedCompletion && selectedCompletion.value !== '0');
            }

            if (event.target.classList.contains('local-homeschool-requirement') ||
                    event.target.classList.contains('local-homeschool-passgrade-radio')) {
                syncRequirementExtras(form);
            }

            if (automaticSelected && requirements) {
                var anyRequirement = form.querySelectorAll('.local-homeschool-requirement:checked').length > 0;
                var error = requirements.querySelector('.local-homeschool-requirements-error');
                if (!anyRequirement) {
                    requirements.hidden = false;
                    requirements.open = true;
                    if (error) {
                        error.hidden = false;
                    }
                    if (completionRadio) {
                        // Keep automatic selected so the user can pick conditions.
                    }
                    return;
                }
                if (error) {
                    error.hidden = true;
                }
            }

            if (cmid && (event.target.classList.contains('local-homeschool-autosave') ||
                    event.target.classList.contains('local-homeschool-requirement-value'))) {
                if (event.target.closest('.local-homeschool-activity-details')) {
                    sessionStorage.setItem('local_homeschool_expand_activity', cmid);
                }
                if (event.target.closest('.local-homeschool-submissions')) {
                    sessionStorage.setItem('local_homeschool_expand_submissions', cmid);
                }
                if (event.target.closest('.local-homeschool-requirements') || automaticSelected) {
                    sessionStorage.setItem('local_homeschool_expand_requirements', cmid);
                }
            }
            form.submit();
        }
    });

    root.addEventListener('click', function(event) {
        var selectAll = event.target.closest('[data-action="local-homeschool-selectall"]');
        var deselectAll = event.target.closest('[data-action="local-homeschool-deselectall"]');
        if (selectAll || deselectAll) {
            event.preventDefault();
            var checked = !!selectAll;
            root.querySelectorAll('.local-homeschool-select-cm').forEach(function(cb) {
                cb.checked = checked;
            });
            var master = document.getElementById('local-homeschool-selectall');
            if (master) {
                master.checked = checked;
            }
            updateRowEditing();
            return;
        }

        var dateInput = event.target.closest('.local-homeschool-date-input');
        if (dateInput && typeof dateInput.showPicker === 'function') {
            try {
                dateInput.showPicker();
            } catch (e) {
                // Browser may reject showPicker outside a direct gesture; native click still works.
            }
        }
    });

    var dateForm = document.getElementById('local-homeschool-bulk-date-form');
    if (dateForm) {
        dateForm.addEventListener('submit', function() {
            dateForm.querySelectorAll('input[name="cmids[]"]').forEach(function(el) {
                if (el.classList.contains('local-homeschool-select-cm')) {
                    return;
                }
                el.remove();
            });
            root.querySelectorAll('.local-homeschool-select-cm:checked').forEach(function(cb) {
                if (cb.form === dateForm) {
                    return;
                }
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'cmids[]';
                input.value = cb.value;
                dateForm.appendChild(input);
            });
        });
    }

    var expandActivity = sessionStorage.getItem('local_homeschool_expand_activity');
    var expandSubmissions = sessionStorage.getItem('local_homeschool_expand_submissions');
    var expandRequirements = sessionStorage.getItem('local_homeschool_expand_requirements');
    sessionStorage.removeItem('local_homeschool_expand_activity');
    sessionStorage.removeItem('local_homeschool_expand_submissions');
    sessionStorage.removeItem('local_homeschool_expand_requirements');

    var desktopQuery = window.matchMedia('(min-width: 768px)');
    var syncActivityDetails = function() {
        root.querySelectorAll('.local-homeschool-activity-details').forEach(function(details) {
            var keepOpen = expandActivity && details.getAttribute('data-cmid') === expandActivity;
            details.open = desktopQuery.matches || !!keepOpen;
        });
        if (expandSubmissions) {
            var submissions = root.querySelector(
                '.local-homeschool-submissions[data-cmid="' + expandSubmissions + '"]'
            );
            if (submissions) {
                submissions.open = true;
            }
        }
        if (expandRequirements) {
            var requirementsPanel = root.querySelector(
                '.local-homeschool-requirements[data-cmid="' + expandRequirements + '"]'
            );
            if (requirementsPanel) {
                requirementsPanel.hidden = false;
                requirementsPanel.open = true;
            }
        }
    };
    syncActivityDetails();
    root.querySelectorAll('.local-homeschool-row-edit').forEach(syncRequirementExtras);
    if (desktopQuery.addEventListener) {
        desktopQuery.addEventListener('change', syncActivityDetails);
    } else if (desktopQuery.addListener) {
        desktopQuery.addListener(syncActivityDetails);
    }

    updateRowEditing();
})();
JS
);

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

if ($day > 0 && !empty($courses)) {
    $PAGE->requires->js_call_amd('local_homeschool/addactivity', 'init');
}
if ($day > 0 && !empty($activities)) {
    $PAGE->requires->js_call_amd('local_homeschool/deleteactivity', 'init');
}

echo $OUTPUT->header();
echo $renderer->render($renderable);
echo $OUTPUT->footer();
