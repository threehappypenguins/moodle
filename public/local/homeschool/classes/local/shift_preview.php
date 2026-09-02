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
 * Shift preview snapshots keyed by unpredictable tokens.
 *
 * Preview metadata is indexed in the session; item snapshots live in a TTL application
 * cache so large course sets do not bloat session storage.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shift_preview {

    /** @var string Session key for preview metadata index. */
    public const SESSION_KEY = 'local_homeschool_shift_previews';

    /** @var string Apply form parameter naming a preview token. */
    public const TOKEN_PARAM = 'previewtoken';

    /** @var string Legacy single-preview session key. */
    private const LEGACY_SESSION_KEY = 'local_homeschool_shift_preview';

    /** @var string Cache area storing preview item snapshots. */
    private const CACHE_AREA = 'shiftpreviews';

    /** @var int Preview availability window in seconds. */
    public const TTL = HOURSECS;

    /** @var int Maximum retained preview snapshots per user in a session. */
    public const MAX_PER_USER = 10;

    /**
     * Store preview rows for a later apply request.
     *
     * @param int $userid
     * @param \stdClass $preview Result from day_scheduler::preview_shift()
     * @param \stdClass $params Parsed shift parameters (days, direction, etc.)
     * @return string Preview token for the apply form, or empty when nothing to apply
     */
    public static function save(int $userid, \stdClass $preview, \stdClass $params): string {
        global $SESSION;

        $items = self::build_snapshot_items($preview);
        if ($items === []) {
            return '';
        }

        self::purge_expired();
        self::enforce_user_cap($userid);

        $token = random_string(32);
        self::get_items_cache()->set($token, $items);

        $store = &self::get_store();
        $store['previews'][$token] = (object) [
            'userid' => $userid,
            'time' => time(),
            'days' => (int) $params->days,
            'direction' => (string) $params->direction,
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
        if (!$data || !self::is_valid_metadata($data, (int) $USER->id)) {
            self::delete_preview($token);
            return null;
        }

        $items = self::get_items_cache()->get($token);
        if (!is_array($items) || $items === []) {
            self::delete_preview($token);
            return null;
        }

        unset($store['previews'][$token]);
        self::get_items_cache()->delete($token);

        $data->items = $items;
        return $data;
    }

    /**
     * Discard stored preview data.
     *
     * @return void
     */
    public static function clear(): void {
        global $SESSION;

        $store = self::get_store();
        foreach (array_keys($store['previews']) as $token) {
            self::get_items_cache()->delete($token);
        }

        unset($SESSION->{self::SESSION_KEY}, $SESSION->{self::LEGACY_SESSION_KEY});
    }

    /**
     * Remove expired preview snapshots.
     *
     * @return void
     */
    public static function purge_expired(): void {
        $store = &self::get_store();
        foreach (array_keys($store['previews']) as $token) {
            if (self::is_expired($store['previews'][$token])) {
                self::delete_preview($token);
            }
        }
    }

    /**
     * Drop the oldest previews for a user when the session cap is reached.
     *
     * @param int $userid
     * @return void
     */
    protected static function enforce_user_cap(int $userid): void {
        $store = &self::get_store();
        $usertokens = [];

        foreach ($store['previews'] as $token => $data) {
            if ((int) $data->userid !== $userid || self::is_expired($data)) {
                continue;
            }
            $usertokens[$token] = (int) $data->time;
        }

        asort($usertokens);
        while (count($usertokens) >= self::MAX_PER_USER) {
            $oldesttoken = array_key_first($usertokens);
            self::delete_preview($oldesttoken);
            unset($usertokens[$oldesttoken]);
        }
    }

    /**
     * @param \stdClass $preview
     * @return \stdClass[]
     */
    protected static function build_snapshot_items(\stdClass $preview): array {
        $items = [];
        foreach ($preview->items as $item) {
            $items[] = (object) [
                'cmid' => (int) $item->cmid,
                'sectionnum' => (int) ($item->sectionnum ?? 0),
                'oldtimestamp' => (int) $item->oldtimestamp,
                'newtimestamp' => (int) $item->newtimestamp,
            ];
        }

        return $items;
    }

    /**
     * @param string $token
     * @return void
     */
    protected static function delete_preview(string $token): void {
        $store = &self::get_store();
        unset($store['previews'][$token]);
        self::get_items_cache()->delete($token);
    }

    /**
     * @return \cache_application
     */
    protected static function get_items_cache(): \cache_application {
        return \cache::make('local_homeschool', self::CACHE_AREA);
    }

    /**
     * @param \stdClass $data
     * @return bool
     */
    protected static function is_expired(\stdClass $data): bool {
        return empty($data->time) || (time() - (int) $data->time) > self::TTL;
    }

    /**
     * @param \stdClass $data
     * @param int $userid
     * @return bool
     */
    protected static function is_valid_metadata(\stdClass $data, int $userid): bool {
        if ((int) $data->userid !== $userid) {
            return false;
        }

        return !self::is_expired($data);
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
