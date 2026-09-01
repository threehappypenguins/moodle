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
 * Arms Homeschool day-page return, then redirects into core modedit.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

require_login();
\local_homeschool\local\requirements::require_manage();
require_sesskey();

$day = required_param('day', PARAM_INT);
$showall = (bool) optional_param('showall', 0, PARAM_BOOL);
$showhidden = (bool) optional_param('showhidden', 0, PARAM_BOOL);
$rawgoto = required_param('goto', PARAM_RAW_TRIMMED);

if ($day < 1) {
    throw new moodle_exception('invaliddaynumber', 'local_homeschool');
}

$wwwroot = rtrim($CFG->wwwroot, '/');
if (str_starts_with($rawgoto, $wwwroot)) {
    $rawgoto = substr($rawgoto, strlen($wwwroot));
}
if ($rawgoto === '' || $rawgoto[0] !== '/') {
    throw new moodle_exception('invalidurl');
}

$gotourl = new moodle_url($rawgoto);
// Activity chooser links use course/mod.php; older flows may use course/modedit.php.
$allowedpaths = [
    (new moodle_url('/course/mod.php'))->get_path(),
    (new moodle_url('/course/modedit.php'))->get_path(),
];
if (!in_array($gotourl->get_path(), $allowedpaths, true)) {
    throw new moodle_exception('invalidurl');
}

\local_homeschool\local\return_context::arm($day, $showall, $showhidden);
redirect($gotourl);
