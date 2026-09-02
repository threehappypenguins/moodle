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
 * Upgrade steps for local_homeschool.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade local_homeschool.
 *
 * @param int|float $oldversion
 * @return bool
 */
function xmldb_local_homeschool_upgrade($oldversion) {
    if ($oldversion < 2026090100) {
        // Earlier builds used forced 23:59 select defaults; clear so admins must choose.
        unset_config('reminderhour', 'local_homeschool');
        unset_config('reminderminute', 'local_homeschool');
        unset_config('enablereminderdefault', 'local_homeschool');

        upgrade_plugin_savepoint(true, 2026090100, 'local', 'homeschool');
    }

    return true;
}
