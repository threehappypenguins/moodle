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
 * Shift timeline reminders across multiple day sections.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

require_login();

\local_homeschool\local\return_context::clear();

$context = context_system::instance();
require_capability('local/homeschool:manage', $context);

if (!\local_homeschool\local\requirements::daysections_available()) {
    throw new moodle_exception('missingdaysections', 'local_homeschool');
}

$action = optional_param('action', '', PARAM_ALPHA);

$url = new moodle_url('/local/homeschool/shift.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('base');
$PAGE->set_primary_active_tab('local_homeschool');
$PAGE->set_title(get_string('shifttitle', 'local_homeschool'));
$PAGE->set_heading(get_string('shifttitle', 'local_homeschool'));

$courses = \local_homeschool\local\course_repository::get_managed_daysections_courses($USER->id);
$maxday = max(1, \local_homeschool\local\course_repository::get_max_day_number($courses));

/**
 * Parse shift parameters from request data.
 *
 * @param bool $alldays
 * @param int $fromday
 * @param int $today
 * @param string $direction
 * @param int $days
 * @param int $maxday
 * @return \stdClass|null null when invalid
 */
$parse_shift_params = static function(
    bool $alldays,
    int $fromday,
    int $today,
    string $direction,
    int $days,
    int $maxday,
): ?\stdClass {
    if ($days < 1 || $days > 365) {
        return null;
    }

    if (!$alldays) {
        if ($fromday < 1 || $fromday > $maxday || $today < 1 || $today > $maxday || $fromday > $today) {
            return null;
        }
    } else {
        $fromday = 1;
        $today = $maxday;
    }

    if (!in_array($direction, ['forward', 'backward'], true)) {
        return null;
    }

    $dayoffset = ($direction === 'backward') ? -$days : $days;

    return (object) [
        'alldays' => $alldays,
        'fromday' => $fromday,
        'today' => $today,
        'direction' => $direction,
        'days' => $days,
        'dayoffset' => $dayoffset,
    ];
};

if ($action === 'undo') {
    require_sesskey();
    $result = \local_homeschool\local\shift_undo::apply();
    if ($result->updated > 0) {
        \core\notification::success(get_string('shiftundone', 'local_homeschool', $result->updated));
    } else {
        \core\notification::error(get_string('shiftundofailed', 'local_homeschool'));
    }
    redirect($url);
}

if ($action === 'apply') {
    require_sesskey();

    $params = $parse_shift_params(
        (bool) optional_param('alldays', 0, PARAM_BOOL),
        optional_param('fromday', 0, PARAM_INT),
        optional_param('today', 0, PARAM_INT),
        optional_param('direction', 'forward', PARAM_ALPHA),
        optional_param('days', 0, PARAM_INT),
        $maxday,
    );

    if (!$params) {
        \core\notification::error(get_string('shiftinvalidparams', 'local_homeschool'));
        redirect($url);
    }

    $preview = \local_homeschool\local\day_scheduler::preview_shift(
        $courses,
        $params->fromday,
        $params->today,
        $params->dayoffset,
        $params->alldays,
    );

    if ($preview->shiftcount === 0) {
        \core\notification::error(get_string('shiftnothingtoapply', 'local_homeschool'));
        redirect($url);
    }

    $cmids = array_map(static function($item) {
        return (int) $item->cmid;
    }, $preview->items);

    $result = \local_homeschool\local\day_scheduler::shift_by_offset($cmids, $params->dayoffset);

    if ($result->updated > 0) {
        $summary = get_string('shiftappliedsummary', 'local_homeschool', (object) [
            'count' => $result->updated,
            'days' => $params->days,
            'direction' => get_string('shiftdirection' . $params->direction, 'local_homeschool'),
        ]);
        \local_homeschool\local\shift_undo::save($USER->id, $result->snapshots, $summary);
        \core\notification::success($summary);
    } else {
        \core\notification::error(get_string('shiftapplyfailed', 'local_homeschool'));
    }

    redirect($url);
}

$dayoptions = [];
for ($i = 1; $i <= $maxday; $i++) {
    $dayoptions[$i] = get_string('daytitle', 'local_homeschool', $i);
}

$form = new \local_homeschool\form\shift_schedule_form($url, [
    'maxday' => $maxday,
    'dayoptions' => $dayoptions,
]);

$preview = null;
if ($data = $form->get_data()) {
    $params = $parse_shift_params(
        !empty($data->alldays),
        (int) ($data->fromday ?? 1),
        (int) ($data->today ?? $maxday),
        (string) ($data->direction ?? 'forward'),
        (int) ($data->days ?? 0),
        $maxday,
    );

    if ($params) {
        $preview = \local_homeschool\local\day_scheduler::preview_shift(
            $courses,
            $params->fromday,
            $params->today,
            $params->dayoffset,
            $params->alldays,
        );
    }
}

$formhtml = $form->render();
$undo = \local_homeschool\local\shift_undo::get_available();

$renderable = new \local_homeschool\output\shift_page($formhtml, $preview, $undo);
$renderer = $PAGE->get_renderer('local_homeschool');

echo $OUTPUT->header();
echo $renderer->render($renderable);
echo $OUTPUT->footer();
