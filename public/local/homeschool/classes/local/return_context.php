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
 * Each modedit launch arms its own flow token. The token is carried through modedit and appended
 * to the core course return URL so only that exact flow is consumed on landing.
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

    /** Maximum age of a ready course landing redirect (seconds). */
    private const LANDING_TTL = 60;

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

        $now = time();
        foreach (['readycreateredirects', 'readyupdateredirects'] as $key) {
            if (empty($store[$key]) || !is_array($store[$key])) {
                continue;
            }
            foreach (array_keys($store[$key]) as $id) {
                $time = (int) ($store[$key][$id]['time'] ?? 0);
                if ($time < 1 || ($now - $time) > self::LANDING_TTL) {
                    unset($store[$key][$id]);
                }
            }
        }

        if (empty($store['pendingupdateredirects']) || !is_array($store['pendingupdateredirects'])) {
            return;
        }
        foreach (array_keys($store['pendingupdateredirects']) as $cmid) {
            $time = (int) ($store['pendingupdateredirects'][$cmid]['time'] ?? 0);
            if ($time < 1 || ($now - $time) > self::LANDING_TTL) {
                unset($store['pendingupdateredirects'][$cmid]);
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
        self::remove_ready_redirects_for_token($token);
        return self::build_url($flow);
    }

    /**
     * Carry the Homeschool flow token on the core course return URL after modedit save.
     *
     * Does not redirect from coursemodule_edit_post_actions; navigation is deferred until
     * core has committed the module save and finished its post-save work.
     *
     * @param \stdClass $data Submitted module form data
     * @param \stdClass $course Target course
     * @return \stdClass
     */
    public static function prepare_modedit_course_return(\stdClass $data, \stdClass $course): \stdClass {
        $url = self::build_modedit_course_return_url($data, $course);
        if (!$url) {
            return $data;
        }

        $token = (string) ($data->{self::FLOW_PARAM} ?? '');
        if ($token === '') {
            return $data;
        }

        if (!empty($data->add)) {
            $cmid = (int) ($data->coursemodule ?? 0);
            if ($cmid > 0) {
                $store = &self::get_store();
                $store['readycreateredirects'][$cmid] = [
                    'url' => $url->out(false),
                    'courseid' => (int) $course->id,
                    'token' => $token,
                    'time' => time(),
                ];
            }
        } else if (!empty($data->coursemodule)) {
            $store = &self::get_store();
            $store['pendingupdateredirects'][(int) $data->coursemodule] = [
                'url' => $url->out(false),
                'courseid' => (int) $course->id,
                'token' => $token,
                'time' => time(),
            ];
        }

        return $data;
    }

    /**
     * Mark a pending update redirect ready after update_moduleinfo has fully completed.
     *
     * Observers must not redirect; course landing handles navigation once core is done.
     *
     * @param int $cmid
     * @return void
     */
    public static function mark_update_redirect_ready(int $cmid): void {
        if ($cmid < 1) {
            return;
        }

        $store = &self::get_store();
        if (empty($store['pendingupdateredirects'][$cmid])) {
            return;
        }

        $store['readyupdateredirects'][$cmid] = $store['pendingupdateredirects'][$cmid];
        unset($store['pendingupdateredirects'][$cmid]);
    }

    /**
     * Redirect a course landing after modedit update when a Homeschool flow is ready.
     *
     * @param int $courseid
     * @param string $token Flow token from the landing URL
     * @return bool True when a redirect was issued
     */
    public static function maybe_redirect_pending_update_landing(int $courseid, string $token): bool {
        if ($courseid < 1 || $token === '') {
            return false;
        }

        self::purge_expired();
        $store = &self::get_store();
        if (empty($store['readyupdateredirects']) || !is_array($store['readyupdateredirects'])) {
            return false;
        }

        foreach ($store['readyupdateredirects'] as $cmid => $pending) {
            if ((int) ($pending['courseid'] ?? 0) !== $courseid) {
                continue;
            }
            if (($pending['token'] ?? '') !== $token) {
                continue;
            }

            unset($store['readyupdateredirects'][$cmid]);
            redirect(new \moodle_url($pending['url']));
        }

        return false;
    }

    /**
     * Redirect a course landing after modedit create when a Homeschool flow is ready.
     *
     * @param int $courseid
     * @param string $token Flow token from the landing URL
     * @return bool True when a redirect was issued
     */
    public static function maybe_redirect_pending_create_landing(int $courseid, string $token): bool {
        if ($courseid < 1 || $token === '') {
            return false;
        }

        self::purge_expired();
        $store = &self::get_store();
        if (empty($store['readycreateredirects']) || !is_array($store['readycreateredirects'])) {
            return false;
        }

        foreach ($store['readycreateredirects'] as $cmid => $pending) {
            if ((int) ($pending['courseid'] ?? 0) !== $courseid) {
                continue;
            }
            if (($pending['token'] ?? '') !== $token) {
                continue;
            }

            unset($store['readycreateredirects'][$cmid]);
            redirect(new \moodle_url($pending['url']));
        }

        return false;
    }

    /**
     * Course return URL for a new activity save, with the flow token attached.
     *
     * @param \stdClass $data Submitted module form data
     * @param \stdClass $course Target course
     * @return \moodle_url|null
     */
    public static function get_create_return_url(\stdClass $data, \stdClass $course): ?\moodle_url {
        if (empty($data->add)) {
            return null;
        }

        return self::build_modedit_course_return_url($data, $course);
    }

    /**
     * Whether a create redirect is ready for course landing after modedit save.
     *
     * @param int $cmid
     * @return bool
     */
    public static function has_ready_create_redirect(int $cmid): bool {
        $store = self::get_store();
        return !empty($store['readycreateredirects'][$cmid]);
    }

    /**
     * Whether an update redirect is queued for a course module.
     *
     * @param int $cmid
     * @return bool
     */
    public static function has_pending_update_redirect(int $cmid): bool {
        $store = self::get_store();
        return !empty($store['pendingupdateredirects'][$cmid]);
    }

    /**
     * Whether an update redirect is ready for course landing after modedit save.
     *
     * @param int $cmid
     * @return bool
     */
    public static function has_ready_update_redirect(int $cmid): bool {
        $store = self::get_store();
        return !empty($store['readyupdateredirects'][$cmid]);
    }

    /**
     * @param \stdClass $data Submitted module form data
     * @param \stdClass $course Target course
     * @return \moodle_url|null
     */
    protected static function build_modedit_course_return_url(\stdClass $data, \stdClass $course): ?\moodle_url {
        $token = $data->{self::FLOW_PARAM} ?? '';
        if ($token === '') {
            return null;
        }

        if (!self::get_valid_flow($token)) {
            return null;
        }

        if (!empty($data->submitbutton)) {
            self::discard_flow($token);
            return null;
        }

        if (empty($data->frontend)) {
            return null;
        }

        if (!empty($data->modulename) && plugin_supports('mod', $data->modulename, FEATURE_PUBLISHES_QUESTIONS)) {
            return null;
        }

        if (!isset($data->section)) {
            return null;
        }

        $url = course_get_url($course, $data->section, self::extract_return_options($data));
        if (!empty($data->coursemodule)) {
            $url->set_anchor('module-' . $data->coursemodule);
        }
        $url->param(self::FLOW_PARAM, $token);

        return $url;
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
     * @param \stdClass $data
     * @return array
     */
    protected static function extract_return_options(\stdClass $data): array {
        $returnoptions = [];
        foreach ((array) $data as $key => $value) {
            if (preg_match('/^returnoptions\[(.+)\]$/', (string) $key, $matches)) {
                $returnoptions[$matches[1]] = (int) $value;
            }
        }
        return $returnoptions;
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
     * Drop any ready create/update landing redirects for a consumed flow token.
     *
     * @param string $token
     * @return void
     */
    protected static function remove_ready_redirects_for_token(string $token): void {
        if ($token === '') {
            return;
        }

        $store = &self::get_store();
        foreach (['readycreateredirects', 'readyupdateredirects'] as $key) {
            if (empty($store[$key]) || !is_array($store[$key])) {
                continue;
            }
            foreach (array_keys($store[$key]) as $id) {
                if (($store[$key][$id]['token'] ?? '') === $token) {
                    unset($store[$key][$id]);
                }
            }
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
                'readycreateredirects' => [],
                'pendingupdateredirects' => [],
                'readyupdateredirects' => [],
            ];
        } else {
            if (!isset($SESSION->{self::SESSION_KEY}['flows'])) {
                $SESSION->{self::SESSION_KEY} = [
                    'flows' => [],
                    'readycreateredirects' => [],
                    'pendingupdateredirects' => [],
                    'readyupdateredirects' => [],
                ];
            } else {
                $SESSION->{self::SESSION_KEY} += [
                    'readycreateredirects' => [],
                    'pendingupdateredirects' => [],
                    'readyupdateredirects' => [],
                ];
            }
        }

        return $SESSION->{self::SESSION_KEY};
    }
}
