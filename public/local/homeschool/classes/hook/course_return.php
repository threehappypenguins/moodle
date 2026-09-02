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

use core\hook\after_config;
use core\hook\output\before_http_headers;
use local_homeschool\local\return_context;

/**
 * After modedit save/cancel, bounce course landing pages back to the Homeschool day page.
 *
 * Consumption requires the flow token on the landing URL so unrelated course visits and
 * concurrent editors cannot steal one another's return targets.
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
     * @param after_config $hook
     * @return void
     */
    public static function after_config(after_config $hook): void {
        if (during_initial_install()) {
            return;
        }

        return_context::maybe_redirect_modedit_cancel();
    }

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

        if (self::is_course_landing_script($script)) {
            $courseid = self::get_landing_course_id();
            if ($courseid < 1) {
                return;
            }

            $token = optional_param(return_context::FLOW_PARAM, '', PARAM_ALPHANUMEXT);
            if ($token === '') {
                if (return_context::maybe_redirect_pending_update_landing($courseid)) {
                    return;
                }
                return_context::maybe_redirect_pending_create_landing($courseid);
                return;
            }

            $url = return_context::consume_for_token($token, $courseid);
            if ($url) {
                redirect($url);
            }
            return;
        }

        return_context::purge_expired();
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
