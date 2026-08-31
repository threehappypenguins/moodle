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

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/completionlib.php');

require_login();

$context = context_system::instance();
require_capability('local/homeschool:manage', $context);

$day = optional_param('day', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$showall = (bool) optional_param('showall', 0, PARAM_BOOL);
$expandreq = optional_param('expandreq', 0, PARAM_INT);

if (!\local_homeschool\local\requirements::daysections_available()) {
    throw new moodle_exception('missingdaysections', 'local_homeschool');
}

$courses = \local_homeschool\local\course_repository::get_managed_daysections_courses($USER->id);
$maxday = \local_homeschool\local\course_repository::get_max_day_number($courses);

$url = new moodle_url('/local/homeschool/review.php');
if ($day > 0) {
    $url->param('day', $day);
}
if ($showall) {
    $url->param('showall', 1);
}

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('base');
$PAGE->set_primary_active_tab('local_homeschool');
if ($day > 0) {
    $PAGE->set_title(get_string('reviewday', 'local_homeschool', $day));
    $PAGE->set_heading(get_string('reviewday', 'local_homeschool', $day));
} else {
    $PAGE->set_title(get_string('schedule', 'local_homeschool'));
    $PAGE->set_heading(get_string('schedule', 'local_homeschool'));
}

if ($day < 0 || (array_key_exists('day', $_GET) && $day < 1)) {
    \core\notification::error(get_string('invaliddaynumber', 'local_homeschool'));
    redirect(new moodle_url('/local/homeschool/review.php'));
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

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $hour = 0;
        $minute = 0;
        if (!empty($cm->completionexpected)) {
            $hour = (int) userdate($cm->completionexpected, '%H');
            $minute = (int) userdate($cm->completionexpected, '%M');
        }

        $timestamp = make_timestamp(
            (int) $matches[1],
            (int) $matches[2],
            (int) $matches[3],
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
        $completion = required_param('completion', PARAM_INT);

        if (!in_array($completion, [
            COMPLETION_TRACKING_NONE,
            COMPLETION_TRACKING_MANUAL,
            COMPLETION_TRACKING_AUTOMATIC,
        ], true)) {
            throw new moodle_exception('invalidcompletion', 'local_homeschool');
        }

        if (!isset($allowedcmids[(int) $cmid])) {
            throw new moodle_exception('invalidactivity', 'local_homeschool');
        }

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $modinfo = get_fast_modinfo($cm->course);
        $cminfo = $modinfo->get_cm($cmid);

        $conditionstate = null;
        if ($completion == COMPLETION_TRACKING_AUTOMATIC) {
            $conditionstate = \local_homeschool\local\completion_conditions::read_posted_state($cminfo);
            if (!\local_homeschool\local\completion_conditions::state_has_condition($conditionstate)) {
                \core\notification::error(get_string('badautocompletion', 'completion'));
                $failurl = new moodle_url($url);
                $failurl->param('expandreq', $cmid);
                redirect($failurl);
            }
        }

        try {
            $changed = \local_homeschool\local\activity_updater::update_completion(
                $cmid,
                $completion,
                $conditionstate,
            );
        } catch (moodle_exception $e) {
            if ($e->errorcode === 'badautocompletion') {
                \core\notification::error(get_string('badautocompletion', 'completion'));
                $failurl = new moodle_url($url);
                $failurl->param('expandreq', $cmid);
                redirect($failurl);
            }
            throw $e;
        }

        if ($cm->modname === 'assign') {
            $enabled = [];
            foreach (\local_homeschool\local\activity_repository::get_assign_submission_types($cminfo) as $submission) {
                if (optional_param('submission_' . $submission->type, 0, PARAM_BOOL)) {
                    $enabled[] = $submission->type;
                }
            }
            \local_homeschool\local\activity_updater::update_assign_submissions($cmid, $enabled);
            $changed = true;
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
    var showAll = document.getElementById('local-homeschool-showall');
    if (showAll) {
        showAll.addEventListener('change', function() {
            showAll.form.submit();
        });
    }

    var root = document.querySelector('.local-homeschool-review');
    if (!root) {
        return;
    }

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
            row.classList.toggle('is-selected', !!(checkbox && checkbox.checked));
        });
    };

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
                event.target.classList.contains('local-homeschool-date-input')) {
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
            }

            if (event.target.classList.contains('local-homeschool-requirement') &&
                    event.target.getAttribute('data-requirement') === 'completionusegrade') {
                var passgrade = form.querySelector('.local-homeschool-passgrade');
                if (passgrade) {
                    passgrade.hidden = !event.target.checked;
                }
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
                        // Revert the visible selection to the previously saved value on the summary.
                        // Keep the attempted automatic selection in the radio group so the user can pick conditions.
                    }
                    return;
                }
                if (error) {
                    error.hidden = true;
                }
            }

            if (cmid && event.target.classList.contains('local-homeschool-autosave')) {
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

    var dateForm = root.querySelector('.local-homeschool-date-form form.mform');
    if (dateForm) {
        dateForm.addEventListener('submit', function() {
            dateForm.querySelectorAll('input[name="cmids[]"]').forEach(function(el) {
                el.remove();
            });
            root.querySelectorAll('.local-homeschool-select-cm:checked').forEach(function(cb) {
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
$renderable = new \local_homeschool\output\day_review(
    $day,
    $courses,
    $dateformhtml,
    $showall,
    $maxday,
    $expandreq,
);
$renderer = $PAGE->get_renderer('local_homeschool');

echo $OUTPUT->header();
echo $renderer->render($renderable);
echo $OUTPUT->footer();
