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

use core_component;
use core_plugin_manager;

/**
 * Plugin dependency and access checks.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class requirements {

    /** @var string Course format frankenstyle component required by this plugin. */
    public const REQUIRED_FORMAT = 'format_daysections';

    /**
     * Whether the Day sections course format is installed and upgraded.
     *
     * Course format classes are not autoloaded, so we must not use class_exists().
     *
     * @return bool
     */
    public static function daysections_available(): bool {
        $plugin = core_plugin_manager::instance()->get_plugin_info(self::REQUIRED_FORMAT);
        return $plugin !== null && $plugin->is_installed_and_upgraded();
    }

    /**
     * Whether the Day sections format plugin exists on disk.
     *
     * @return bool
     */
    public static function daysections_present_on_disk(): bool {
        return core_component::get_plugin_directory('format', 'daysections') !== null;
    }

    /**
     * Whether the current user may open the homeschool dashboard.
     *
     * @return bool
     */
    public static function user_can_view(): bool {
        $context = \context_system::instance();
        return has_capability('local/homeschool:view', $context);
    }

    /**
     * Whether the current user may manage homeschool scheduling.
     *
     * @return bool
     */
    public static function user_can_manage(): bool {
        $context = \context_system::instance();
        return has_capability('local/homeschool:manage', $context);
    }
}
