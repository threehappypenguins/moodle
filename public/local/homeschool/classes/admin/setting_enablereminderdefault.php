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

namespace local_homeschool\admin;

defined('MOODLE_INTERNAL') || die();

/**
 * Checkbox that requires hour and minute when enabling a default reminder time.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_enablereminderdefault extends \admin_setting_configcheckbox {

    /**
     * Require hour and minute selections when enabling.
     *
     * @param mixed $data
     * @return string Empty string on success, otherwise an error message
     */
    public function write_setting($data) {
        if ((string) $data === $this->yes && !self::submitted_time_is_complete()) {
            return get_string('reminderdefaultrequired', 'local_homeschool');
        }

        return parent::write_setting($data);
    }

    /**
     * Whether the submitted hour and minute form values are both set.
     *
     * @return bool
     */
    public static function submitted_time_is_complete(): bool {
        $hour = optional_param('s_local_homeschool_reminderhour', null, PARAM_RAW);
        $minute = optional_param('s_local_homeschool_reminderminute', null, PARAM_RAW);

        return $hour !== null && $hour !== '' && $minute !== null && $minute !== '';
    }

    /**
     * Whether the enable checkbox is checked in the submitted settings form.
     *
     * @return bool
     */
    public static function submitted_enable_is_on(): bool {
        return optional_param('s_local_homeschool_enablereminderdefault', '0', PARAM_RAW) === '1';
    }
}
