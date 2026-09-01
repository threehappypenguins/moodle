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
 * Undo lasts 30 minutes, or until a snapshotted activity's reminder date no
 * longer matches the shifted value (including edits made outside Homeschool).
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

    /** @var int Maximum course-module ids per IN() lookup (Oracle limit is 1,000). */
    private const MAX_CMIDS_PER_QUERY = 500;

    /**
     * Store undo snapshots, replacing any previous undo for this user.
     *
     * @param int $userid
     * @param \stdClass[] $snapshots Each entry: cmid, timestamp (pre-shift), shifted (post-shift)
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
     * Undo payload for the current user, if any and not expired or invalidated.
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

        if (!self::snapshots_match_current_values($data->snapshots ?? [])) {
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
     * @return \stdClass result with updated and skipped counts
     */
    public static function apply(): \stdClass {
        $data = self::get_available();
        if (!$data || empty($data->snapshots)) {
            return (object) [
                'updated' => 0,
                'skipped' => 0,
            ];
        }

        return self::restore_snapshots($data->snapshots);
    }

    /**
     * Restore reminder dates from validated undo snapshots.
     *
     * Each row is written only when completionexpected still matches the snapshotted
     * post-shift value, so manual edits after validation are not overwritten.
     *
     * @param \stdClass[] $snapshots
     * @return \stdClass result with updated and skipped counts
     */
    protected static function restore_snapshots(array $snapshots): \stdClass {
        self::clear();

        $timestampsbycmid = [];
        $requiredoldbycmid = [];
        foreach ($snapshots as $snapshot) {
            $cmid = (int) $snapshot->cmid;
            $timestampsbycmid[$cmid] = (int) $snapshot->timestamp;
            $requiredoldbycmid[$cmid] = (int) $snapshot->shifted;
        }

        $apply = day_scheduler::apply_timestamps($timestampsbycmid, false, $requiredoldbycmid);

        return (object) [
            'updated' => $apply->updated,
            'skipped' => $apply->skipped + $apply->skippedchanged,
        ];
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

    /**
     * True when every snapshot still has its post-shift completionexpected value.
     *
     * Detects reminder edits made outside Homeschool (e.g. core activity settings)
     * that never call invalidate_for_cmids().
     *
     * @param \stdClass[] $snapshots
     * @return bool
     */
    protected static function snapshots_match_current_values(array $snapshots): bool {
        if (empty($snapshots)) {
            return false;
        }

        $expectedbycmid = [];
        foreach ($snapshots as $snapshot) {
            if (!isset($snapshot->cmid) || !isset($snapshot->shifted)) {
                return false;
            }
            $expectedbycmid[(int) $snapshot->cmid] = (int) $snapshot->shifted;
        }

        $cmids = array_keys($expectedbycmid);
        $records = self::get_completionexpected_by_cmid($cmids);

        foreach ($expectedbycmid as $cmid => $shifted) {
            if (!isset($records[$cmid])) {
                return false;
            }
            if ((int) $records[$cmid]->completionexpected !== $shifted) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fetch completionexpected for many course modules using bounded IN() clauses.
     *
     * @param int[] $cmids
     * @return \stdClass[] keyed by course-module id
     */
    protected static function get_completionexpected_by_cmid(array $cmids): array {
        global $DB;

        if (empty($cmids)) {
            return [];
        }

        $records = [];
        foreach (array_chunk($cmids, self::MAX_CMIDS_PER_QUERY) as $chunk) {
            $records += $DB->get_records_list(
                'course_modules',
                'id',
                $chunk,
                '',
                'id,completionexpected',
            );
        }

        return $records;
    }
}
