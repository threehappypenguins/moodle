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

require_login();

$context = context_system::instance();
require_capability('local/homeschool:view', $context);

$showhidden = (bool) optional_param('showhidden', 0, PARAM_BOOL);
$showotherformats = (bool) optional_param('showotherformats', 0, PARAM_BOOL);

$url = new moodle_url('/local/homeschool/index.php');
if ($showhidden) {
    $url->param('showhidden', 1);
}
if ($showotherformats) {
    $url->param('showotherformats', 1);
}
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('base');
$PAGE->set_primary_active_tab('local_homeschool');
$PAGE->set_title(get_string('dashboard', 'local_homeschool'));
$PAGE->set_heading(get_string('pluginname', 'local_homeschool'));

if (!\local_homeschool\local\requirements::daysections_available()) {
    \core\notification::error(get_string('missingdaysections', 'local_homeschool'));
}

$renderable = new \local_homeschool\output\dashboard($USER->id, $showhidden, $showotherformats);
$renderer = $PAGE->get_renderer('local_homeschool');

echo $OUTPUT->header();
echo $renderer->render($renderable);
echo $OUTPUT->footer();
