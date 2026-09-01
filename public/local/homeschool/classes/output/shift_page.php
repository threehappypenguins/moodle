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

namespace local_homeschool\output;

use local_homeschool\local\day_scheduler;
use renderable;
use renderer_base;
use templatable;

/**
 * Schedule shift page renderable.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shift_page implements renderable, templatable {

    /** @var string */
    protected $formhtml;

    /** @var \stdClass|null */
    protected $preview;

    /** @var \stdClass|null */
    protected $undo;

    /**
     * @param string $formhtml Rendered shift form HTML
     * @param \stdClass|null $preview Preview result from day_scheduler::preview_shift()
     * @param \stdClass|null $undo Available undo payload
     */
    public function __construct(string $formhtml, ?\stdClass $preview, ?\stdClass $undo) {
        $this->formhtml = $formhtml;
        $this->preview = $preview;
        $this->undo = $undo;
    }

    /**
     * @param renderer_base $output
     * @return \stdClass
     */
    public function export_for_template(renderer_base $output): \stdClass {
        $data = (object) [
            'dashboardurl' => (new \moodle_url('/local/homeschool/index.php'))->out(false),
            'shifturl' => (new \moodle_url('/local/homeschool/shift.php'))->out(false),
            'formhtml' => $this->formhtml,
            'sesskey' => sesskey(),
            'haspreview' => false,
            'hasundo' => !empty($this->undo),
        ];

        if ($this->undo) {
            $data->undosummary = $this->undo->summary;
            $data->undocount = count($this->undo->snapshots);
        }

        if (!$this->preview) {
            return $data;
        }

        $preview = $this->preview;
        $limit = day_scheduler::SHIFT_PREVIEW_LIMIT;
        $items = array_slice($preview->items, 0, $limit);
        $hidden = max(0, count($preview->items) - count($items));

        $rows = [];
        foreach ($items as $item) {
            $rows[] = (object) [
                'sectionname' => $item->sectionname,
                'coursename' => $item->coursename,
                'activityname' => $item->activityname,
                'olddateformatted' => $item->olddateformatted,
                'newdateformatted' => $item->newdateformatted,
            ];
        }

        $skippedparts = [];
        if ($preview->skippednodate > 0) {
            $skippedparts[] = get_string('shiftskippednodate', 'local_homeschool', $preview->skippednodate);
        }
        if ($preview->skippednocompletion > 0) {
            $skippedparts[] = get_string('shiftskippednocompletion', 'local_homeschool', $preview->skippednocompletion);
        }
        if ($preview->skippedpermission > 0) {
            $skippedparts[] = get_string('shiftskippedpermission', 'local_homeschool', $preview->skippedpermission);
        }
        if ($preview->skippeddeleted > 0) {
            $skippedparts[] = get_string('shiftskippeddeleted', 'local_homeschool', $preview->skippeddeleted);
        }

        $absdays = abs((int) $preview->dayoffset);
        $directionkey = $preview->dayoffset < 0 ? 'backward' : 'forward';

        $data->haspreview = true;
        $data->previewsummary = get_string('shiftpreviewsummary', 'local_homeschool', (object) [
            'count' => $preview->shiftcount,
            'days' => $absdays,
            'direction' => get_string('shiftdirection' . $directionkey, 'local_homeschool'),
        ]);
        $data->previewrows = $rows;
        $data->previewhidden = $hidden;
        $data->previewhiddennote = $hidden > 0
            ? get_string('shiftpreviewtruncated', 'local_homeschool', $hidden)
            : '';
        $data->hasskipped = !empty($skippedparts);
        $data->skippedsummary = implode(' ', $skippedparts);
        $data->canapply = $preview->shiftcount > 0;
        $data->applyalldays = !empty($preview->alldays) ? 1 : 0;
        $data->applyfromday = (int) $preview->fromday;
        $data->applytoday = (int) $preview->today;
        $data->applydirection = $preview->dayoffset < 0 ? 'backward' : 'forward';
        $data->applydays = $absdays;

        return $data;
    }
}
