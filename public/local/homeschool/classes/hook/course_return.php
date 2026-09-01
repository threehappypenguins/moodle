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
     * @param before_http_headers $hook
     * @return void
     */
    public static function before_http_headers(before_http_headers $hook): void {
        global $SCRIPT;

        if (during_initial_install() || CLI_SCRIPT || AJAX_SCRIPT || WS_SERVER) {
            return;
        }

        $script = (string) $SCRIPT;
        $islanding = false;
        foreach (self::COURSE_LANDING_SCRIPTS as $suffix) {
            if ($script === $suffix || str_ends_with($script, $suffix)) {
                $islanding = true;
                break;
            }
        }
        if (!$islanding) {
            return;
        }

        $url = return_context::consume();
        if ($url) {
            redirect($url);
        }
    }
}
