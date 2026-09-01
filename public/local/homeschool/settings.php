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

/**
 * Admin settings for local_homeschool.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_homeschool', get_string('pluginname', 'local_homeschool'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_heading(
        'local_homeschool/general',
        get_string('pluginname', 'local_homeschool'),
        get_string('settingsintro', 'local_homeschool'),
    ));

    $settings->add(new admin_setting_configtext(
        'local_homeschool/studentrole',
        get_string('studentrole', 'local_homeschool'),
        get_string('studentrole_desc', 'local_homeschool'),
        'student',
        PARAM_ALPHANUMEXT,
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_homeschool/showchildsurname',
        get_string('showchildsurname', 'local_homeschool'),
        get_string('showchildsurname_desc', 'local_homeschool'),
        0,
    ));

    $choosedots = get_string('choosedots');
    $houroptions = ['' => $choosedots];
    for ($i = 0; $i <= 23; $i++) {
        $houroptions[$i] = sprintf('%02d', $i);
    }
    $minuteoptions = ['' => $choosedots];
    for ($i = 0; $i <= 59; $i++) {
        $minuteoptions[$i] = sprintf('%02d', $i);
    }

    $settings->add(new admin_setting_heading(
        'local_homeschool/reminderdefaults',
        get_string('reminderdefaults', 'local_homeschool'),
        get_string('reminderdefaults_desc', 'local_homeschool'),
    ));

    $settings->add(new \local_homeschool\admin\setting_enablereminderdefault(
        'local_homeschool/enablereminderdefault',
        get_string('enablereminderdefault', 'local_homeschool'),
        get_string('enablereminderdefault_desc', 'local_homeschool'),
        0,
    ));

    $reminderhour = new admin_setting_configselect(
        'local_homeschool/reminderhour',
        get_string('reminderhour', 'local_homeschool'),
        get_string('reminderhour_desc', 'local_homeschool'),
        '',
        $houroptions,
    );
    $reminderhour->set_validate_function(static function(string $data): string {
        if (\local_homeschool\admin\setting_enablereminderdefault::submitted_enable_is_on() && $data === '') {
            return get_string('reminderdefaultrequired', 'local_homeschool');
        }
        return '';
    });
    $settings->add($reminderhour);
    $settings->hide_if('local_homeschool/reminderhour', 'local_homeschool/enablereminderdefault', 'notchecked');

    $reminderminute = new admin_setting_configselect(
        'local_homeschool/reminderminute',
        get_string('reminderminute', 'local_homeschool'),
        get_string('reminderminute_desc', 'local_homeschool'),
        '',
        $minuteoptions,
    );
    $reminderminute->set_validate_function(static function(string $data): string {
        if (\local_homeschool\admin\setting_enablereminderdefault::submitted_enable_is_on() && $data === '') {
            return get_string('reminderdefaultrequired', 'local_homeschool');
        }
        return '';
    });
    $settings->add($reminderminute);
    $settings->hide_if('local_homeschool/reminderminute', 'local_homeschool/enablereminderdefault', 'notchecked');
}
