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

namespace local_homeschool\local;

/**
 * Session stash so modedit "Save and return to course" can land on the Homeschool day page.
 *
 * Core modedit only redirects via course_get_url(); there is no arbitrary return URL.
 * We arm this context when the activity chooser launches modedit from the day page, then
 * intercept the subsequent course/section page load and redirect once.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class return_context {

    /** @var string Session property name. */
    public const SESSION_KEY = 'local_homeschool_return';

    /** Maximum age of an armed return before it is ignored (seconds). */
    public const TTL = 7200;

    /**
     * Remember which Homeschool day page to return to after modedit.
     *
     * @param int $daynumber
     * @param bool $showall
     * @return void
     */
    public static function arm(int $daynumber, bool $showall = false): void {
        global $SESSION;

        if ($daynumber < 1) {
            return;
        }

        $SESSION->{self::SESSION_KEY} = [
            'day' => $daynumber,
            'showall' => $showall ? 1 : 0,
            'time' => time(),
        ];
    }

    /**
     * Forget any pending Homeschool return.
     *
     * @return void
     */
    public static function clear(): void {
        global $SESSION;

        unset($SESSION->{self::SESSION_KEY});
    }

    /**
     * Build the day page URL if a recent return is armed; otherwise null.
     *
     * Does not clear the session value.
     *
     * @return \moodle_url|null
     */
    public static function get_url(): ?\moodle_url {
        global $SESSION;

        if (empty($SESSION->{self::SESSION_KEY}) || !is_array($SESSION->{self::SESSION_KEY})) {
            return null;
        }

        $data = $SESSION->{self::SESSION_KEY};
        $day = (int) ($data['day'] ?? 0);
        $time = (int) ($data['time'] ?? 0);

        if ($day < 1 || $time < 1 || (time() - $time) > self::TTL) {
            self::clear();
            return null;
        }

        $url = new \moodle_url('/local/homeschool/day.php', ['day' => $day]);
        if (!empty($data['showall'])) {
            $url->param('showall', 1);
        }
        return $url;
    }

    /**
     * Consume an armed return URL (clear session and return the URL).
     *
     * @return \moodle_url|null
     */
    public static function consume(): ?\moodle_url {
        $url = self::get_url();
        if ($url) {
            self::clear();
        }
        return $url;
    }
}
