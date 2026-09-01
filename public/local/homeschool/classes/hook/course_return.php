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

namespace local_homeschool\hook;

use core\hook\output\before_http_headers;
use local_homeschool\local\return_context;

/**
 * After modedit save/cancel, bounce course landing pages back to the Homeschool day page.
 *
 * "Save and display" lands on /mod/.../view.php instead; clear armed return there so a
 * later unrelated course visit is not redirected. Preserve armed return only during modedit.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_return {

    /**
     * Scripts where core modedit may land after "Save and return to course" or cancel.
     */
    private const COURSE_LANDING_SCRIPTS = [
        '/course/view.php',
        '/course/section.php',
    ];

    /**
     * Scripts that are part of the active modedit flow (do not clear armed return yet).
     */
    private const MODEDIT_FLOW_SCRIPTS = [
        '/course/modedit.php',
        '/course/mod.php',
    ];

    /**
     * @param before_http_headers $hook
     * @return void
     */
    public static function before_http_headers(before_http_headers $hook): void {
        global $SCRIPT;

        if (during_initial_install() || CLI_SCRIPT || AJAX_SCRIPT || WS_SERVER) {
            return;
        }

        $script = (string) $SCRIPT;

        if (self::is_modedit_flow_script($script)) {
            return;
        }

        if (self::is_course_landing_script($script)) {
            $courseid = self::get_landing_course_id();
            if ($courseid < 1) {
                return;
            }

            $url = return_context::consume_for_course($courseid);
            if ($url) {
                redirect($url);
            }
            return;
        }

        // Modedit finished at a non-course destination (e.g. activity view after "Save and display").
        if (return_context::get_url() !== null) {
            return_context::clear();
        }
    }

    /**
     * Whether the current script is a course landing page modedit may return to.
     *
     * @param string $script
     * @return bool
     */
    protected static function is_course_landing_script(string $script): bool {
        foreach (self::COURSE_LANDING_SCRIPTS as $suffix) {
            if ($script === $suffix || str_ends_with($script, $suffix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the current script is part of the active modedit flow.
     *
     * @param string $script
     * @return bool
     */
    protected static function is_modedit_flow_script(string $script): bool {
        foreach (self::MODEDIT_FLOW_SCRIPTS as $suffix) {
            if ($script === $suffix || str_ends_with($script, $suffix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve the course id for the current course landing script.
     *
     * @return int
     */
    protected static function get_landing_course_id(): int {
        global $DB, $SCRIPT;

        $script = (string) $SCRIPT;
        if ($script === '/course/view.php' || str_ends_with($script, '/course/view.php')) {
            return optional_param('id', 0, PARAM_INT);
        }

        if ($script === '/course/section.php' || str_ends_with($script, '/course/section.php')) {
            $sectionid = optional_param('id', 0, PARAM_INT);
            if ($sectionid < 1) {
                return 0;
            }
            return (int) $DB->get_field('course_sections', 'course', ['id' => $sectionid], IGNORE_MISSING);
        }

        return 0;
    }
}
