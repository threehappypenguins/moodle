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

defined('MOODLE_INTERNAL') || die();

/**
 * Session-backed snapshot from the last shift preview awaiting apply.
 *
 * Apply uses this snapshot instead of recomputing the selection from live data.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shift_preview {

    /** @var string Session key for preview payload. */
    public const SESSION_KEY = 'local_homeschool_shift_preview';

    /** @var int Preview availability window in seconds. */
    public const TTL = HOURSECS;

    /**
     * Store preview rows for a later apply request.
     *
     * @param int $userid
     * @param \stdClass $preview Result from day_scheduler::preview_shift()
     * @param \stdClass $params Parsed shift parameters (days, direction, etc.)
     * @return void
     */
    public static function save(int $userid, \stdClass $preview, \stdClass $params): void {
        global $SESSION;

        $items = [];
        foreach ($preview->items as $item) {
            $items[] = (object) [
                'cmid' => (int) $item->cmid,
                'oldtimestamp' => (int) $item->oldtimestamp,
                'newtimestamp' => (int) $item->newtimestamp,
            ];
        }

        $SESSION->{self::SESSION_KEY} = (object) [
            'userid' => $userid,
            'time' => time(),
            'days' => (int) $params->days,
            'direction' => (string) $params->direction,
            'items' => $items,
        ];
    }

    /**
     * Preview payload for the current user, if any and not expired.
     *
     * @return \stdClass|null
     */
    public static function get_available(): ?\stdClass {
        global $SESSION, $USER;

        if (empty($SESSION->{self::SESSION_KEY})) {
            return null;
        }

        $data = $SESSION->{self::SESSION_KEY};
        if ((int) $data->userid !== (int) $USER->id) {
            self::clear();
            return null;
        }

        if (empty($data->time) || (time() - (int) $data->time) > self::TTL) {
            self::clear();
            return null;
        }

        if (empty($data->items)) {
            self::clear();
            return null;
        }

        return $data;
    }

    /**
     * Discard stored preview data.
     *
     * @return void
     */
    public static function clear(): void {
        global $SESSION;
        unset($SESSION->{self::SESSION_KEY});
    }
}
