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
 * Session-backed shift preview snapshots keyed by unpredictable tokens.
 *
 * Each preview stores its own snapshot so concurrent shift tabs cannot overwrite
 * one another before apply.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shift_preview {

    /** @var string Session key for the preview store. */
    public const SESSION_KEY = 'local_homeschool_shift_previews';

    /** @var string Apply form parameter naming a preview token. */
    public const TOKEN_PARAM = 'previewtoken';

    /** @var string Legacy single-preview session key. */
    private const LEGACY_SESSION_KEY = 'local_homeschool_shift_preview';

    /** @var int Preview availability window in seconds. */
    public const TTL = HOURSECS;

    /**
     * Store preview rows for a later apply request.
     *
     * @param int $userid
     * @param \stdClass $preview Result from day_scheduler::preview_shift()
     * @param \stdClass $params Parsed shift parameters (days, direction, etc.)
     * @return string Preview token for the apply form
     */
    public static function save(int $userid, \stdClass $preview, \stdClass $params): string {
        global $SESSION;

        $items = [];
        foreach ($preview->items as $item) {
            $items[] = (object) [
                'cmid' => (int) $item->cmid,
                'oldtimestamp' => (int) $item->oldtimestamp,
                'newtimestamp' => (int) $item->newtimestamp,
            ];
        }

        $token = random_string(32);
        $store = &self::get_store();
        $store['previews'][$token] = (object) [
            'userid' => $userid,
            'time' => time(),
            'days' => (int) $params->days,
            'direction' => (string) $params->direction,
            'items' => $items,
        ];

        unset($SESSION->{self::LEGACY_SESSION_KEY});

        return $token;
    }

    /**
     * Retrieve and discard the preview snapshot for a specific token.
     *
     * @param string $token Preview token from the apply form
     * @return \stdClass|null
     */
    public static function consume(string $token): ?\stdClass {
        global $USER;

        if ($token === '') {
            return null;
        }

        self::purge_expired();
        $store = &self::get_store();
        $data = $store['previews'][$token] ?? null;
        if (!$data || !self::is_valid($data, (int) $USER->id)) {
            if ($data) {
                unset($store['previews'][$token]);
            }
            return null;
        }

        unset($store['previews'][$token]);
        return $data;
    }

    /**
     * Discard stored preview data.
     *
     * @return void
     */
    public static function clear(): void {
        global $SESSION;

        unset($SESSION->{self::SESSION_KEY}, $SESSION->{self::LEGACY_SESSION_KEY});
    }

    /**
     * Remove expired preview snapshots.
     *
     * @return void
     */
    public static function purge_expired(): void {
        global $USER;

        $store = &self::get_store();
        foreach (array_keys($store['previews']) as $token) {
            if (!self::is_valid($store['previews'][$token], (int) $USER->id)) {
                unset($store['previews'][$token]);
            }
        }
    }

    /**
     * @param \stdClass $data
     * @param int $userid
     * @return bool
     */
    protected static function is_valid(\stdClass $data, int $userid): bool {
        if ((int) $data->userid !== $userid) {
            return false;
        }

        if (empty($data->time) || (time() - (int) $data->time) > self::TTL) {
            return false;
        }

        return !empty($data->items);
    }

    /**
     * @return array
     */
    protected static function &get_store(): array {
        global $SESSION;

        $sessionkey = self::SESSION_KEY;
        $store = $SESSION->{$sessionkey} ?? null;
        if (!is_array($store) || !isset($store['previews'])) {
            $store = [
                'previews' => [],
            ];
            $SESSION->{$sessionkey} = $store;
        }

        return $SESSION->{$sessionkey};
    }
}
