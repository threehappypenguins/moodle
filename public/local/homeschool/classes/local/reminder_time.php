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
 * Optional default hour/minute for new timeline reminder dates.
 *
 * Disabled by default. When enabled via plugin settings with a chosen time,
 * Homeschool date pickers use that time instead of Moodle's built-in default.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reminder_time {

    /**
     * Whether a custom default reminder time is enabled and fully configured.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        if (!(bool) get_config('local_homeschool', 'enablereminderdefault')) {
            return false;
        }

        return self::has_configured_time();
    }

    /**
     * Whether hour and minute are both stored (not the empty “choose” value).
     *
     * @return bool
     */
    public static function has_configured_time(): bool {
        $hour = get_config('local_homeschool', 'reminderhour');
        $minute = get_config('local_homeschool', 'reminderminute');

        return $hour !== false && $hour !== '' && $minute !== false && $minute !== '';
    }

    /**
     * Configured default hour (0–23).
     *
     * @return int
     */
    public static function get_default_hour(): int {
        return max(0, min(23, (int) get_config('local_homeschool', 'reminderhour')));
    }

    /**
     * Configured default minute (0–59).
     *
     * @return int
     */
    public static function get_default_minute(): int {
        return max(0, min(59, (int) get_config('local_homeschool', 'reminderminute')));
    }

    /**
     * Unix timestamp for a calendar day at the configured default time.
     *
     * Uses the current user's timezone via make_timestamp / userdate.
     *
     * @param int|null $daytimestamp Any timestamp on the desired day (null = today)
     * @return int
     */
    public static function get_default_timestamp(?int $daytimestamp = null): int {
        if ($daytimestamp === null || $daytimestamp <= 0) {
            $daytimestamp = time();
        }

        return make_timestamp(
            (int) userdate($daytimestamp, '%Y'),
            (int) userdate($daytimestamp, '%m'),
            (int) userdate($daytimestamp, '%d'),
            self::get_default_hour(),
            self::get_default_minute(),
        );
    }

    /**
     * Options to merge into a date_time_selector element.
     *
     * Empty when the custom default is disabled so Moodle keeps its usual default.
     *
     * @return array
     */
    public static function get_datetime_selector_options(): array {
        if (!self::is_enabled()) {
            return [];
        }

        return [
            'defaulttime' => self::get_default_timestamp(),
        ];
    }

    /**
     * Hour and minute for a newly chosen calendar day (no existing reminder).
     *
     * When the custom default is disabled, uses the current user time so behaviour
     * matches Moodle's date_time_selector (defaulttime 0 → time()).
     *
     * @return array{0: int, 1: int} [hour, minute]
     */
    public static function get_new_reminder_hour_minute(): array {
        if (!self::is_enabled()) {
            $now = time();
            return [
                (int) userdate($now, '%H'),
                (int) userdate($now, '%M'),
            ];
        }

        return [self::get_default_hour(), self::get_default_minute()];
    }
}
