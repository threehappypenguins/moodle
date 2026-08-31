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
 * Library functions for local_homeschool.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Extend site navigation with a link to the homeschool dashboard.
 *
 * @param global_navigation $nav
 * @return void
 */
function local_homeschool_extend_navigation(global_navigation $nav): void {
    if (!\local_homeschool\local\requirements::user_can_view()) {
        return;
    }

    $nav->add(
        get_string('navigationlink', 'local_homeschool'),
        new moodle_url('/local/homeschool/index.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_homeschool',
        new pix_icon('i/home', ''),
    );
}
