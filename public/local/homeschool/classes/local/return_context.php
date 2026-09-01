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
 * Each modedit launch from the day page arms its own flow token so concurrent editors for
 * different days (or the same course) do not overwrite one another.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class return_context {

    /** @var string Session property name for the flow store. */
    public const SESSION_KEY = 'local_homeschool_returns';

    /** @var string Query param carrying a flow token through modedit. */
    public const FLOW_PARAM = 'local_homeschool_return';

    /** @var string Legacy single-slot session key (pre concurrent flows). */
    private const LEGACY_SESSION_KEY = 'local_homeschool_return';

    /** Maximum age of an armed return before it is ignored (seconds). */
    public const TTL = 7200;

    /**
     * Remember which Homeschool day page to return to after modedit.
     *
     * @param int $daynumber
     * @param int $courseid Course the modedit flow was launched for
     * @param bool $showall
     * @param bool $showhidden
     * @return string Flow token to append to the modedit URL
     */
    public static function arm(int $daynumber, int $courseid, bool $showall = false, bool $showhidden = false): string {
        global $SESSION;

        if ($daynumber < 1 || $courseid < 1) {
            return '';
        }

        $token = random_string(32);
        $store = &self::get_store();
        $store['flows'][$token] = [
            'day' => $daynumber,
            'courseid' => $courseid,
            'showall' => $showall ? 1 : 0,
            'showhidden' => $showhidden ? 1 : 0,
            'time' => time(),
        ];

        if (!isset($store['course_order'][$courseid])) {
            $store['course_order'][$courseid] = [];
        }
        $store['course_order'][$courseid][] = $token;
        $store['course_active'][$courseid] = $token;

        unset($SESSION->{self::LEGACY_SESSION_KEY});

        return $token;
    }

    /**
     * Mark a flow as the active modedit session for its course.
     *
     * Called when modedit loads with a flow token in the URL.
     *
     * @param string $token
     * @return void
     */
    public static function touch_flow(string $token): void {
        $flow = self::get_valid_flow($token);
        if (!$flow) {
            return;
        }

        $store = &self::get_store();
        $store['course_active'][(int) $flow['courseid']] = $token;
    }

    /**
     * Forget all pending Homeschool returns.
     *
     * @return void
     */
    public static function clear(): void {
        global $SESSION;

        unset($SESSION->{self::SESSION_KEY}, $SESSION->{self::LEGACY_SESSION_KEY});
    }

    /**
     * Remove expired flows from the session store.
     *
     * @return void
     */
    public static function purge_expired(): void {
        $store = &self::get_store();
        foreach (array_keys($store['flows']) as $token) {
            if (!self::is_flow_valid($store['flows'][$token])) {
                self::remove_flow($token);
            }
        }
    }

    /**
     * Whether any non-expired return flows are pending.
     *
     * @return bool
     */
    public static function has_pending(): bool {
        self::purge_expired();
        $store = self::get_store();
        return !empty($store['flows']);
    }

    /**
     * Drop the active modedit flow for a course after "Save and display".
     *
     * @param int $cmid Course-module id from the activity view page
     * @return void
     */
    public static function clear_active_for_module(int $cmid): void {
        if ($cmid < 1) {
            return;
        }

        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        self::clear_active_for_course((int) $cm->course);
    }

    /**
     * Consume the oldest pending return for a course landing page.
     *
     * @param int $courseid Course id for the current landing request
     * @return \moodle_url|null
     */
    public static function consume_for_course(int $courseid): ?\moodle_url {
        if ($courseid < 1) {
            return null;
        }

        self::purge_expired();
        $store = &self::get_store();

        if (empty($store['course_order'][$courseid])) {
            return null;
        }

        while (!empty($store['course_order'][$courseid])) {
            $token = array_shift($store['course_order'][$courseid]);
            $flow = $store['flows'][$token] ?? null;
            if (!$flow || !self::is_flow_valid($flow) || (int) $flow['courseid'] !== $courseid) {
                self::remove_flow($token);
                continue;
            }

            self::remove_flow($token);
            return self::build_url($flow);
        }

        return null;
    }

    /**
     * @param int $courseid
     * @return void
     */
    protected static function clear_active_for_course(int $courseid): void {
        $store = &self::get_store();
        $token = $store['course_active'][$courseid] ?? null;
        if (!$token) {
            return;
        }

        self::remove_flow($token);
    }

    /**
     * @param array $flow
     * @return \moodle_url
     */
    protected static function build_url(array $flow): \moodle_url {
        $url = new \moodle_url('/local/homeschool/day.php', ['day' => (int) $flow['day']]);
        if (!empty($flow['showall'])) {
            $url->param('showall', 1);
        }
        if (!empty($flow['showhidden'])) {
            $url->param('showhidden', 1);
        }
        return $url;
    }

    /**
     * @param string $token
     * @return array|null
     */
    protected static function get_valid_flow(string $token): ?array {
        if ($token === '') {
            return null;
        }

        $store = self::get_store();
        $flow = $store['flows'][$token] ?? null;
        if (!$flow || !self::is_flow_valid($flow)) {
            if ($flow) {
                self::remove_flow($token);
            }
            return null;
        }

        return $flow;
    }

    /**
     * @param array $flow
     * @return bool
     */
    protected static function is_flow_valid(array $flow): bool {
        $day = (int) ($flow['day'] ?? 0);
        $courseid = (int) ($flow['courseid'] ?? 0);
        $time = (int) ($flow['time'] ?? 0);

        return $day > 0 && $courseid > 0 && $time > 0 && (time() - $time) <= self::TTL;
    }

    /**
     * @param string $token
     * @return void
     */
    protected static function remove_flow(string $token): void {
        $store = &self::get_store();
        if (!isset($store['flows'][$token])) {
            return;
        }

        $courseid = (int) ($store['flows'][$token]['courseid'] ?? 0);
        unset($store['flows'][$token]);

        if ($courseid > 0 && isset($store['course_order'][$courseid])) {
            $store['course_order'][$courseid] = array_values(array_filter(
                $store['course_order'][$courseid],
                static fn(string $queued): bool => $queued !== $token,
            ));
            if ($store['course_order'][$courseid] === []) {
                unset($store['course_order'][$courseid]);
            }
        }

        if ($courseid > 0 && (($store['course_active'][$courseid] ?? null) === $token)) {
            unset($store['course_active'][$courseid]);
        }
    }

    /**
     * @return array
     */
    protected static function &get_store(): array {
        global $SESSION;

        if (empty($SESSION->{self::SESSION_KEY}) || !is_array($SESSION->{self::SESSION_KEY})) {
            $SESSION->{self::SESSION_KEY} = [
                'flows' => [],
                'course_order' => [],
                'course_active' => [],
            ];
        }

        return $SESSION->{self::SESSION_KEY};
    }
}
