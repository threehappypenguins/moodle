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

namespace local_homeschool\hook;

use core\hook\navigation\primary_extend;
use local_homeschool\local\requirements;

/**
 * Adds the homeschool dashboard to primary navigation (Moodle 5.x).
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class primary_navigation {

    /**
     * Hook callback for primary navigation.
     *
     * @param primary_extend $hook
     * @return void
     */
    public static function extend(primary_extend $hook): void {
        if (!requirements::user_can_view()) {
            return;
        }

        $primaryview = $hook->get_primaryview();
        $primaryview->add(
            get_string('navigationlink', 'local_homeschool'),
            new \moodle_url('/local/homeschool/index.php'),
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_homeschool',
            new \pix_icon('i/home', ''),
        );
    }
}
