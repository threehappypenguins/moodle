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

global $CFG;

require_once($CFG->dirroot . '/local/homeschool/tests/base_testcase.php');

/**
 * Regression tests for calendar-day shifting across DST boundaries.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_homeschool\local\day_scheduler
 */
final class shift_dst_test extends \local_homeschool\base_testcase {

    /** @var string Non-UTC timezone with US DST rules. */
    private const TIMEZONE = 'America/New_York';

    /**
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $user = $this->getDataGenerator()->create_user();
        $user->timezone = self::TIMEZONE;
        $this->setUser($user);
    }

    /**
     * Build a unix timestamp for a local wall-clock datetime in the user's timezone.
     *
     * @param string $localdatetime
     * @return int
     */
    protected function local_timestamp(string $localdatetime): int {
        $tz = \core_date::get_user_timezone_object();
        return (new \DateTime($localdatetime, $tz))->getTimestamp();
    }

    /**
     * Format a unix timestamp as local wall-clock datetime in the user's timezone.
     *
     * @param int $timestamp
     * @return string
     */
    protected function local_datetime(int $timestamp): string {
        $date = new \DateTime('@' . $timestamp);
        $date->setTimezone(\core_date::get_user_timezone_object());
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Invoke the protected calendar-day shift helper.
     *
     * @param int $timestamp
     * @param int $dayoffset
     * @return int
     */
    protected function shift_days(int $timestamp, int $dayoffset): int {
        $method = new \ReflectionMethod(day_scheduler::class, 'shift_timestamp_by_days');
        return $method->invoke(null, $timestamp, $dayoffset);
    }

    /**
     * Shifting across spring-forward keeps the same local time of day when it exists.
     */
    public function test_shift_preserves_wall_clock_across_spring_forward(): void {
        $before = $this->local_timestamp('2026-03-07 15:00:00');
        $after = $this->shift_days($before, 1);

        $this->assertSame('2026-03-08 15:00:00', $this->local_datetime($after));
    }

    /**
     * Shifting across fall-back keeps the same local time of day.
     */
    public function test_shift_preserves_wall_clock_across_fall_back(): void {
        $before = $this->local_timestamp('2026-10-31 15:00:00');
        $after = $this->shift_days($before, 2);

        $this->assertSame('2026-11-02 15:00:00', $this->local_datetime($after));
    }

    /**
     * Spring-forward creates a gap; PHP advances a nonexistent local time to the next valid instant.
     */
    public function test_shift_spring_forward_nonexistent_local_time(): void {
        $before = $this->local_timestamp('2026-03-07 02:30:00');
        $after = $this->shift_days($before, 1);

        // March 8 02:30 does not exist in America/New_York; DateTime rolls forward one hour.
        $this->assertSame('2026-03-08 03:30:00', $this->local_datetime($after));
    }

    /**
     * Fall-back repeats the 01:00 hour; calendar-day arithmetic keeps wall-clock fields stable.
     */
    public function test_shift_fall_back_ambiguous_local_time(): void {
        $before = $this->local_timestamp('2026-10-31 01:30:00');
        $after = $this->shift_days($before, 1);

        $this->assertSame('2026-11-01 01:30:00', $this->local_datetime($after));
    }

    /**
     * Round-trip shifting across a spring-forward week restores the original local datetime.
     */
    public function test_shift_round_trip_across_spring_forward_week(): void {
        $original = $this->local_timestamp('2026-03-05 09:00:00');
        $shifted = $this->shift_days($original, 7);
        $restored = $this->shift_days($shifted, -7);

        $this->assertSame('2026-03-05 09:00:00', $this->local_datetime($original));
        $this->assertSame('2026-03-12 09:00:00', $this->local_datetime($shifted));
        $this->assertSame('2026-03-05 09:00:00', $this->local_datetime($restored));
    }

    /**
     * Round-trip shifting across a fall-back week restores the original local datetime.
     */
    public function test_shift_round_trip_across_fall_back_week(): void {
        $original = $this->local_timestamp('2026-10-28 09:00:00');
        $shifted = $this->shift_days($original, 7);
        $restored = $this->shift_days($shifted, -7);

        $this->assertSame('2026-10-28 09:00:00', $this->local_datetime($original));
        $this->assertSame('2026-11-04 09:00:00', $this->local_datetime($shifted));
        $this->assertSame('2026-10-28 09:00:00', $this->local_datetime($restored));
    }
}
