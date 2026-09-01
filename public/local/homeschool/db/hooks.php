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
 * Hook callbacks for local_homeschool.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\navigation\primary_extend::class,
        'callback' => [\local_homeschool\hook\primary_navigation::class, 'extend'],
        'priority' => 0,
    ],
    [
        'hook' => \core\hook\after_config::class,
        'callback' => [\local_homeschool\hook\course_return::class, 'after_config'],
        'priority' => 0,
    ],
    [
        'hook' => \core\hook\output\before_http_headers::class,
        'callback' => [\local_homeschool\hook\course_return::class, 'before_http_headers'],
        'priority' => 0,
    ],
];
