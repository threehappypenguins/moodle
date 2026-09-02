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
 * Normalize activity-chooser launch URLs for Homeschool modedit flows.
 *
 * Chooser links target /course/mod.php, which rebuilds a fixed-parameter redirect to
 * modedit and drops plugin params such as the Homeschool return token. Rewriting to
 * modedit.php here preserves those params without another core hop.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modedit_launch {

    /** @var string */
    private const MOD_PATH = '/course/mod.php';

    /** @var string */
    private const MODEDIT_PATH = '/course/modedit.php';

    /** @var string[] mod.php add params translated or dropped when building modedit. */
    private const MOD_ADD_CONSUMED = [
        'id',
        'add',
        'type',
        'return',
        'beforemod',
        'section',
        'sectionid',
        'returnoptions',
        'sesskey',
        'sr',
    ];

    /** @var string[] mod.php update params translated or dropped when building modedit. */
    private const MOD_UPDATE_CONSUMED = [
        'update',
        'return',
        'returnoptions',
        'sesskey',
        'sr',
    ];

    /**
     * Whether a launch URL targets mod.php or modedit.php.
     *
     * @param \moodle_url $url
     * @return bool
     */
    public static function is_supported_launch_url(\moodle_url $url): bool {
        return self::is_mod_url($url) || self::is_modedit_url($url);
    }

    /**
     * Build a modedit_bridge URL that arms a day-page return before launching modedit.
     *
     * @param int $day
     * @param \moodle_url $gotourl mod.php or modedit.php launch target
     * @param bool $showall
     * @param bool $showhidden
     * @return \moodle_url
     */
    public static function build_bridge_launch_url(
        int $day,
        \moodle_url $gotourl,
        bool $showall = false,
        bool $showhidden = false,
    ): \moodle_url {
        $params = [
            'sesskey' => sesskey(),
            'day' => $day,
            'goto' => $gotourl->out(false),
        ];
        if ($showall) {
            $params['showall'] = 1;
        }
        if ($showhidden) {
            $params['showhidden'] = 1;
        }

        return new \moodle_url('/local/homeschool/modedit_bridge.php', $params);
    }

    /**
     * Bridge URL for editing an existing activity and returning to the day page.
     *
     * @param int $cmid
     * @param int $day
     * @param bool $showall
     * @param bool $showhidden
     * @return \moodle_url
     */
    public static function build_edit_launch_url(
        int $cmid,
        int $day,
        bool $showall = false,
        bool $showhidden = false,
    ): \moodle_url {
        $modedit = new \moodle_url('/course/modedit.php', [
            'update' => $cmid,
            'return' => 0,
        ]);

        return self::build_bridge_launch_url($day, $modedit, $showall, $showhidden);
    }

    /**
     * Rewrite /course/mod.php launch URLs to equivalent /course/modedit.php URLs.
     *
     * @param \moodle_url $url Launch URL from the activity chooser or bridge
     * @return \moodle_url
     */
    public static function normalize_url(\moodle_url $url): \moodle_url {
        if (self::is_modedit_url($url)) {
            return $url;
        }
        if (!self::is_mod_url($url)) {
            throw new \moodle_exception('invalidurl');
        }

        $add = $url->param('add');
        if ($add !== null && $add !== '') {
            return self::translate_add($url, (string) $add);
        }

        $update = (int) $url->param('update');
        if ($update > 0) {
            return self::translate_update($url, $update);
        }

        throw new \moodle_exception('invalidurl');
    }

    /**
     * @param \moodle_url $url
     * @param string $add
     * @return \moodle_url
     */
    private static function translate_add(\moodle_url $url, string $add): \moodle_url {
        $courseid = (int) $url->param('course');
        if ($courseid < 1) {
            $courseid = (int) $url->param('id');
        }
        if ($courseid < 1) {
            throw new \moodle_exception('invalidurl');
        }

        $params = [
            'add' => $add,
            'course' => $courseid,
        ];
        foreach (['type', 'return', 'beforemod', 'section', 'sectionid'] as $key) {
            if ($url->param($key) !== null) {
                $params[$key] = $url->param($key);
            }
        }
        $returnoptions = $url->param('returnoptions');
        if ($returnoptions !== null) {
            $params['returnoptions'] = $returnoptions;
        }

        $target = new \moodle_url(self::MODEDIT_PATH, $params);
        self::copy_extra_params($url, $target, self::MOD_ADD_CONSUMED);
        return $target;
    }

    /**
     * @param \moodle_url $url
     * @param int $update
     * @return \moodle_url
     */
    private static function translate_update(\moodle_url $url, int $update): \moodle_url {
        $params = ['update' => $update];
        if ($url->param('return') !== null) {
            $params['return'] = $url->param('return');
        }
        $returnoptions = $url->param('returnoptions');
        if ($returnoptions !== null) {
            $params['returnoptions'] = $returnoptions;
        }

        $target = new \moodle_url(self::MODEDIT_PATH, $params);
        self::copy_extra_params($url, $target, self::MOD_UPDATE_CONSUMED);
        return $target;
    }

    /**
     * Copy plugin-specific params (e.g. the Homeschool flow token) onto the modedit URL.
     *
     * @param \moodle_url $from
     * @param \moodle_url $to
     * @param string[] $consumed
     * @return void
     */
    private static function copy_extra_params(\moodle_url $from, \moodle_url $to, array $consumed): void {
        foreach ($from->params() as $key => $value) {
            if (in_array($key, $consumed, true)) {
                continue;
            }
            if ($to->param($key) === null) {
                $to->param($key, $value);
            }
        }
    }

    /**
     * @param \moodle_url $url
     * @return bool
     */
    private static function is_mod_url(\moodle_url $url): bool {
        return $url->compare(new \moodle_url(self::MOD_PATH), URL_MATCH_BASE);
    }

    /**
     * @param \moodle_url $url
     * @return bool
     */
    private static function is_modedit_url(\moodle_url $url): bool {
        return $url->compare(new \moodle_url(self::MODEDIT_PATH), URL_MATCH_BASE);
    }
}