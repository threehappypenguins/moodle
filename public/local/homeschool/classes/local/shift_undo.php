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
 * Session-backed undo for the most recent schedule shift.
 *
 * Undo lasts 30 minutes, or until a snapshotted activity's reminder date is
 * changed outside of shift apply / undo restore.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shift_undo {

    /** @var string Session key for undo payload. */
    public const SESSION_KEY = 'local_homeschool_shift_undo';

    /** @var int Undo availability window in seconds. */
    public const TTL = 30 * MINSECS;

    /**
     * Store undo snapshots, replacing any previous undo for this user.
     *
     * @param int $userid
     * @param \stdClass[] $snapshots Each entry: cmid, timestamp
     * @param string $summary Human-readable description for the undo banner
     * @return void
     */
    public static function save(int $userid, array $snapshots, string $summary): void {
        global $SESSION;

        if (empty($snapshots)) {
            self::clear();
            return;
        }

        $SESSION->{self::SESSION_KEY} = (object) [
            'userid' => $userid,
            'time' => time(),
            'summary' => $summary,
            'snapshots' => array_values($snapshots),
        ];
    }

    /**
     * Undo payload for the current user, if any and not expired.
     *
     * @return \stdClass|null
     */
    public static function get_available(): ?\stdClass {
        global $SESSION, $USER;

        if (empty($SESSION->{self::SESSION_KEY})) {
            return null;
        }

        $data = $SESSION->{self::SESSION_KEY};
        if ((int) $data->userid !== (int) $USER->id) {
            self::clear();
            return null;
        }

        if (empty($data->time) || (time() - (int) $data->time) > self::TTL) {
            self::clear();
            return null;
        }

        return $data;
    }

    /**
     * Discard undo if any of the given activities are part of the last shift.
     *
     * @param int[] $cmids
     * @return void
     */
    public static function invalidate_for_cmids(array $cmids): void {
        $data = self::get_available();
        if (!$data || empty($data->snapshots) || empty($cmids)) {
            return;
        }

        $snapcmids = [];
        foreach ($data->snapshots as $snapshot) {
            $snapcmids[(int) $snapshot->cmid] = true;
        }

        foreach ($cmids as $cmid) {
            if (isset($snapcmids[(int) $cmid])) {
                self::clear();
                return;
            }
        }
    }

    /**
     * Restore reminder dates from the stored undo payload.
     *
     * @return \stdClass result with updated count
     */
    public static function apply(): \stdClass {
        $data = self::get_available();
        $result = (object) [
            'updated' => 0,
            'skipped' => 0,
        ];

        if (!$data || empty($data->snapshots)) {
            return $result;
        }

        // Capture and clear first so restore writes do not re-invalidate.
        self::clear();

        foreach ($data->snapshots as $snapshot) {
            $apply = day_scheduler::apply_to_activities(
                [(int) $snapshot->cmid],
                (int) $snapshot->timestamp,
                false,
            );
            if ($apply->updated > 0) {
                $result->updated++;
            } else {
                $result->skipped++;
            }
        }

        return $result;
    }

    /**
     * Discard stored undo data.
     *
     * @return void
     */
    public static function clear(): void {
        global $SESSION;
        unset($SESSION->{self::SESSION_KEY});
    }
}
