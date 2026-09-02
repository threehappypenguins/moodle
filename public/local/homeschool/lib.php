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

/**
 * Carry the Homeschool return flow token through modedit form submissions.
 *
 * @param moodleform_mod $formwrapper
 * @param MoodleQuickForm $mform
 * @return void
 */
function local_homeschool_coursemodule_standard_elements($formwrapper, $mform): void {
    $mform->addElement('hidden', \local_homeschool\local\return_context::FLOW_PARAM, '');
    $mform->setType(\local_homeschool\local\return_context::FLOW_PARAM, PARAM_ALPHANUMEXT);
}

/**
 * Default the hidden flow token from the modedit launch URL.
 *
 * @param moodleform_mod $formwrapper
 * @param MoodleQuickForm $mform
 * @return void
 */
function local_homeschool_coursemodule_definition_after_data($formwrapper, $mform): void {
    $token = optional_param(\local_homeschool\local\return_context::FLOW_PARAM, '', PARAM_ALPHANUMEXT);
    if ($token !== '') {
        $mform->setDefault(\local_homeschool\local\return_context::FLOW_PARAM, $token);
    }
}

/**
 * Record which course-module a modedit save will land on for Homeschool return flows.
 *
 * @param stdClass $moduleinfo
 * @param stdClass $course
 * @return stdClass
 */
function local_homeschool_coursemodule_edit_post_actions($moduleinfo, $course) {
    \local_homeschool\local\return_context::record_modedit_save_landing($moduleinfo, $course);
    return $moduleinfo;
}
