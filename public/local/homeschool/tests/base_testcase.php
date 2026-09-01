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

namespace local_homeschool;

defined('MOODLE_INTERNAL') || die();

use local_homeschool\local\shift_undo;

/**
 * Base testcase for local_homeschool with consistent cleanup between tests.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_testcase extends \advanced_testcase {

    /**
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->cleanup_homeschool_test_state();
    }

    /**
     * @return void
     */
    protected function tearDown(): void {
        $this->cleanup_homeschool_test_state();
        parent::tearDown();
    }

    /**
     * Clear session-backed Homeschool state that survives outside DB transactions.
     *
     * @return void
     */
    protected function cleanup_homeschool_test_state(): void {
        shift_undo::clear();
    }

    /**
     * Enable completion site-wide using config so resetAfterTest rolls it back.
     *
     * @return void
     */
    protected function enable_completion_globally(): void {
        set_config('enablecompletion', COMPLETION_ENABLED);
    }
}
