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
 * Each modedit launch arms its own flow token. The token is carried through modedit, recorded at
 * save time, and matched on the core course landing page so only that exact flow is consumed.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class return_context {

    /** @var string Session property name for the flow store. */
    public const SESSION_KEY = 'local_homeschool_returns';

    /** @var string Query param carrying a flow token through modedit and course return. */
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

        unset($SESSION->{self::LEGACY_SESSION_KEY});

        return $token;
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
     * Remove expired flows and stale modedit landing redirects from the session store.
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
     * Drop a flow without redirecting (e.g. after "Save and display").
     *
     * @param string $token
     * @return void
     */
    public static function discard_flow(string $token): void {
        if ($token !== '') {
            self::remove_flow($token);
        }
    }

    /**
     * Consume an armed return when the landing URL names a specific flow token.
     *
     * @param string $token Flow token from the landing URL
     * @param int $courseid Course id for the current landing request
     * @return \moodle_url|null
     */
    public static function consume_for_token(string $token, int $courseid): ?\moodle_url {
        if ($token === '' || $courseid < 1) {
            return null;
        }

        self::purge_expired();
        $flow = self::get_valid_flow($token);
        if (!$flow || (int) $flow['courseid'] !== $courseid) {
            return null;
        }

        self::remove_flow($token);
        return self::build_url($flow);
    }

    /**
     * Remember which course-module core will land on after a modedit save.
     *
     * @param \stdClass $fromform Submitted module form data
     * @param \stdClass $course Target course
     * @return void
     */
    public static function record_modedit_save_landing(\stdClass $fromform, \stdClass $course): void {
        $token = (string) ($fromform->{self::FLOW_PARAM} ?? '');
        if ($token === '') {
            return;
        }

        if (!empty($fromform->submitbutton)) {
            self::discard_flow($token);
            return;
        }

        if (empty($fromform->frontend)) {
            return;
        }

        $flow = self::get_valid_flow($token);
        if (!$flow || (int) $flow['courseid'] !== (int) $course->id) {
            return;
        }

        if (!empty($fromform->modulename) && plugin_supports('mod', $fromform->modulename, FEATURE_PUBLISHES_QUESTIONS)) {
            return;
        }

        if (empty($fromform->coursemodule)) {
            return;
        }

        $store = &self::get_store();
        $store['flows'][$token]['landcmid'] = (int) $fromform->coursemodule;
    }

    /**
     * Pending modedit landings that need a client-side flow token on the course URL.
     *
     * @param int $courseid
     * @return array<int, array{token: string, cmid: int}>
     */
    public static function get_client_landing_flows(int $courseid): array {
        if ($courseid < 1) {
            return [];
        }

        self::purge_expired();
        $store = self::get_store();
        $landings = [];

        foreach ($store['flows'] as $token => $flow) {
            if ((int) ($flow['courseid'] ?? 0) !== $courseid) {
                continue;
            }
            if (empty($flow['landcmid'])) {
                continue;
            }
            if (!self::is_flow_valid($flow)) {
                continue;
            }

            $landings[] = [
                'token' => (string) $token,
                'cmid' => (int) $flow['landcmid'],
            ];
        }

        return $landings;
    }

    /**
     * Redirect a modedit cancel to the core course URL with the flow token attached.
     *
     * @return bool True when a redirect was issued
     */
    public static function maybe_redirect_modedit_cancel(): bool {
        global $SCRIPT;

        if (CLI_SCRIPT || AJAX_SCRIPT || WS_SERVER) {
            return false;
        }

        $script = (string) $SCRIPT;
        if ($script !== '/course/modedit.php' && !str_ends_with($script, '/course/modedit.php')) {
            return false;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }

        if (!optional_param('cancel', 0, PARAM_RAW)) {
            return false;
        }

        $token = optional_param(self::FLOW_PARAM, '', PARAM_ALPHANUMEXT);
        if ($token === '' || !self::get_valid_flow($token)) {
            return false;
        }

        $update = optional_param('update', 0, PARAM_INT);
        $return = optional_param('return', 0, PARAM_BOOL);
        if ($return && $update > 0) {
            self::discard_flow($token);
            return false;
        }

        $returnoptions = optional_param_array('returnoptions', [], PARAM_INT);
        $add = optional_param('add', '', PARAM_ALPHANUM);

        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        if ($update > 0) {
            $cm = get_coursemodule_from_id('', $update, 0, false, IGNORE_MISSING);
            if (!$cm) {
                return false;
            }
            $course = get_course($cm->course);
            $url = course_get_url($course, $cm->sectionnum, $returnoptions);
            $url->set_anchor('module-' . $cm->id);
        } else if ($add !== '') {
            $courseid = optional_param('course', 0, PARAM_INT);
            $sectionnum = optional_param('section', 0, PARAM_INT);
            if ($courseid < 1) {
                return false;
            }
            $course = get_course($courseid);
            $url = course_get_url($course, $sectionnum, $returnoptions);
            $beforemod = optional_param('beforemod', 0, PARAM_INT);
            if ($beforemod > 0) {
                $url->set_anchor('module-' . $beforemod);
            }
        } else {
            return false;
        }

        $url->param(self::FLOW_PARAM, $token);
        redirect($url);
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
        unset($store['flows'][$token]);
    }

    /**
     * @return array
     */
    protected static function &get_store(): array {
        global $SESSION;

        if (empty($SESSION->{self::SESSION_KEY}) || !is_array($SESSION->{self::SESSION_KEY})) {
            $SESSION->{self::SESSION_KEY} = [
                'flows' => [],
            ];
        } else if (!isset($SESSION->{self::SESSION_KEY}['flows'])) {
            $SESSION->{self::SESSION_KEY} = [
                'flows' => [],
            ];
        }

        return $SESSION->{self::SESSION_KEY};
    }
}
