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

namespace tool_recyclebin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Tests for the interaction between asynchronous course deletion and the category recycle bin.
 *
 * @package    tool_recyclebin
 * @copyright  2026 Matt Porritt <matt.porritt@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(category_bin::class)]
#[CoversFunction('delete_course')]
final class async_course_deletion_test extends \advanced_testcase {
    /**
     * A course deleted asynchronously must leave exactly one restorable copy in the category bin.
     */
    public function test_async_deletion_stores_a_single_item(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('categorybinenable', 1, 'tool_recyclebin');
        set_config('enablecourseasyncdeletion', 1, 'moodlecourse');

        $course = $this->getDataGenerator()->create_course(['visible' => 1]);
        $this->assertEquals(0, $DB->count_records('tool_recyclebin_category'));

        // A manager deletes the course. Async is preferred, so this only queues the ad hoc task.
        // The recycle bin copy is taken now, at click time, while the course's real visibility is
        // still intact - not later at cron time, once the course has been marked for deletion.
        delete_course($course, false);
        $this->assertTrue($DB->record_exists('course', ['id' => $course->id]));
        $this->assertEquals(1, $DB->count_records('tool_recyclebin_category'));

        // Cron runs the queued \core_course\task\course_async_deletion task. The task calls
        // delete_course() with $showfeedback = true, so swallow the progress output it prints.
        ob_start();
        $this->run_all_adhoc_tasks();
        ob_end_clean();

        // The course row is now really gone.
        $this->assertFalse($DB->record_exists('course', ['id' => $course->id]));

        // There must still be exactly one restorable copy - the deferred cron-time deletion must not
        // have taken a second backup.
        $this->assertEquals(1, $DB->count_records('tool_recyclebin_category'));

        // And that copy must really restore.
        $recyclebin = new \tool_recyclebin\category_bin($course->category);
        $items = $recyclebin->get_items();
        $this->assertCount(1, $items);

        // The restored course must keep the visibility it had before deletion, not the hidden
        // "marked for deletion" state that delete_course() applies internally.
        $recyclebin->restore_item(reset($items));
        $restored = $DB->get_record('course', ['shortname' => $course->shortname], '*', MUST_EXIST);
        $this->assertEquals(1, $restored->visible);
    }

    /**
     * A course deleted synchronously (async deletion disabled) must still leave exactly one copy.
     */
    public function test_sync_deletion_stores_a_single_item(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('categorybinenable', 1, 'tool_recyclebin');
        set_config('enablecourseasyncdeletion', 0, 'moodlecourse');

        $course = $this->getDataGenerator()->create_course();
        $this->assertEquals(0, $DB->count_records('tool_recyclebin_category'));

        ob_start();
        delete_course($course, true);
        ob_end_clean();

        $this->assertFalse($DB->record_exists('course', ['id' => $course->id]));
        $this->assertEquals(1, $DB->count_records('tool_recyclebin_category'));
    }

    /**
     * Restoring a course deleted synchronously must preserve its original visibility.
     */
    public function test_sync_deletion_preserves_restored_visibility(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('categorybinenable', 1, 'tool_recyclebin');
        set_config('enablecourseasyncdeletion', 0, 'moodlecourse');

        $course = $this->getDataGenerator()->create_course(['visible' => 1]);

        ob_start();
        delete_course($course, true);
        ob_end_clean();

        $recyclebin = new \tool_recyclebin\category_bin($course->category);
        $items = $recyclebin->get_items();
        $this->assertCount(1, $items);

        $recyclebin->restore_item(reset($items));
        $restored = $DB->get_record('course', ['shortname' => $course->shortname], '*', MUST_EXIST);
        $this->assertEquals(1, $restored->visible);
    }
}
