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
 * Clear timeline reminder dates for a selected course.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

require_login();

\local_homeschool\local\return_context::purge_expired();

\local_homeschool\local\requirements::require_manage();

$context = context_system::instance();

if (!\local_homeschool\local\requirements::daysections_available()) {
    throw new moodle_exception('missingdaysections', 'local_homeschool');
}

$action = optional_param('action', '', PARAM_ALPHA);
$showhidden = (bool) optional_param('showhidden', 0, PARAM_BOOL);
$courseid = optional_param('id', 0, PARAM_INT);

$url = new moodle_url('/local/homeschool/clearreminders.php');
if ($showhidden) {
    $url->param('showhidden', 1);
}
if ($courseid > 0) {
    $url->param('id', $courseid);
}

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('base');
$PAGE->set_primary_active_tab('local_homeschool');
$PAGE->set_title(get_string('clearreminderstitle', 'local_homeschool'));
$PAGE->set_heading(get_string('clearreminderstitle', 'local_homeschool'));

$courses = \local_homeschool\local\course_repository::get_managed_daysections_courses($USER->id, $showhidden);

if ($courseid < 1 && count($courses) === 1) {
    $courseid = (int) array_key_first($courses);
    $url->param('id', $courseid);
    $PAGE->set_url($url);
}

/**
 * Selected day section numbers from the request.
 *
 * @return int[]
 */
$parse_selected_days = static function(): array {
    $raw = $_POST['days'] ?? $_GET['days'] ?? null;
    if ($raw === null || $raw === '') {
        return [];
    }

    if (is_array($raw)) {
        $days = optional_param_array('days', [], PARAM_INT);
        return array_values(array_unique(array_map('intval', $days)));
    }

    $daysparam = optional_param('days', '', PARAM_SEQUENCE);
    if ($daysparam === '') {
        return [];
    }

    return array_values(array_unique(array_map('intval', explode(',', $daysparam))));
};

/**
 * Days that currently have reminder dates in the selected course.
 *
 * @param \stdClass $course
 * @param int[] $requested
 * @return array{0:int[],1:int} selectable day numbers and dated activity count
 */
$filter_clearable_days = static function(\stdClass $course, array $requested): array {
    $allowed = array_fill_keys($requested, true);
    $days = [];
    $activitycount = 0;
    foreach (\local_homeschool\local\day_scheduler::get_course_day_reminder_rows($course) as $row) {
        if (!$row->hasdate || !isset($allowed[$row->daynumber])) {
            continue;
        }
        $days[] = $row->daynumber;
        $activitycount += $row->datedcount;
    }

    return [$days, $activitycount];
};

$listurl = new moodle_url('/local/homeschool/clearreminders.php');
if ($showhidden) {
    $listurl->param('showhidden', 1);
}

if ($action === 'clear' || $action === 'confirm') {
    require_sesskey();

    if ($courseid < 1 || !isset($courses[$courseid])) {
        \core\notification::error(get_string('clearremindersinvalidcourse', 'local_homeschool'));
        redirect($listurl);
    }

    $course = $courses[$courseid];
    $listurl->param('id', $courseid);
    [$selecteddays, $activitycount] = $filter_clearable_days($course, $parse_selected_days());

    if (empty($selecteddays)) {
        \core\notification::error(get_string('clearremindersnoneselected', 'local_homeschool'));
        redirect($listurl);
    }

    if ($action === 'clear') {
        $result = \local_homeschool\local\day_scheduler::clear_course_day_reminders($course, $selecteddays);
        \core\notification::success(get_string('clearremindersdone', 'local_homeschool', $result->updated));
        redirect($listurl);
    }

    $continueurl = new moodle_url('/local/homeschool/clearreminders.php', [
        'id' => $courseid,
        'action' => 'clear',
        'days' => implode(',', $selecteddays),
    ]);
    if ($showhidden) {
        $continueurl->param('showhidden', 1);
    }

    $continue = new single_button(
        $continueurl,
        get_string('clearremindersconfirmbutton', 'local_homeschool'),
        'post',
    );
    $message = get_string('clearremindersconfirm', 'local_homeschool', (object) [
        'count' => $activitycount,
        'course' => format_string($course->fullname, true, ['context' => context_course::instance($course->id)]),
    ]);

    echo $OUTPUT->header();
    echo $OUTPUT->confirm($message, $continue, $listurl);
    echo $OUTPUT->footer();
    exit;
}

$renderable = new \local_homeschool\output\clear_reminders_page($courses, $courseid, $showhidden);
$renderer = $PAGE->get_renderer('local_homeschool');

$PAGE->requires->js_init_code(<<<'JS'
(function() {
    var courseSelect = document.getElementById('local-homeschool-clear-course');
    if (courseSelect && courseSelect.form) {
        courseSelect.addEventListener('change', function() {
            courseSelect.form.submit();
        });
    }

    var form = document.getElementById('local-homeschool-clear-reminders-form');
    if (!form) {
        return;
    }

    var syncSubmit = function() {
        var submit = document.getElementById('local-homeschool-clear-submit');
        if (!submit) {
            return;
        }
        submit.disabled = form.querySelectorAll('.local-homeschool-select-day:checked:not(:disabled)').length === 0;
    };

    var setAll = function(checked) {
        form.querySelectorAll('.local-homeschool-select-day:not(:disabled)').forEach(function(cb) {
            cb.checked = checked;
        });
        syncSubmit();
    };

    form.addEventListener('change', function(event) {
        if (event.target.classList.contains('local-homeschool-select-day')) {
            syncSubmit();
        }
    });

    form.addEventListener('click', function(event) {
        var selectAll = event.target.closest('[data-action="local-homeschool-selectall"]');
        var deselectAll = event.target.closest('[data-action="local-homeschool-deselectall"]');
        if (!selectAll && !deselectAll) {
            return;
        }
        event.preventDefault();
        setAll(!!selectAll);
    });

    syncSubmit();
})();
JS
);

echo $OUTPUT->header();
echo $renderer->render($renderable);
echo $OUTPUT->footer();
