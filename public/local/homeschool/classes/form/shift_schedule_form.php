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

namespace local_homeschool\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Shift timeline reminders across a day range.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shift_schedule_form extends \moodleform {

    /**
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $maxday = max(1, (int) ($this->_customdata['maxday'] ?? 1));
        $dayoptions = $this->_customdata['dayoptions'] ?? [];

        if (empty($dayoptions)) {
            for ($i = 1; $i <= $maxday; $i++) {
                $dayoptions[$i] = get_string('daytitle', 'local_homeschool', $i);
            }
        }

        $mform->addElement('header', 'rangeshdr', get_string('shiftrange', 'local_homeschool'));

        $mform->addElement('advcheckbox', 'alldays', get_string('shiftalldays', 'local_homeschool'));
        $mform->addHelpButton('alldays', 'shiftalldays', 'local_homeschool');

        $mform->addElement('select', 'fromday', get_string('shiftfromday', 'local_homeschool'), $dayoptions);
        $mform->addElement('select', 'today', get_string('shifttoday', 'local_homeschool'), $dayoptions);
        $mform->setDefault('fromday', 1);
        $mform->setDefault('today', $maxday);

        $mform->disabledIf('fromday', 'alldays', 'checked');
        $mform->disabledIf('today', 'alldays', 'checked');

        $mform->addElement('header', 'offsethdr', get_string('shiftoffset', 'local_homeschool'));

        $directionoptions = [
            'forward' => get_string('shiftforward', 'local_homeschool'),
            'backward' => get_string('shiftbackward', 'local_homeschool'),
        ];
        $mform->addElement('select', 'direction', get_string('shiftdirection', 'local_homeschool'), $directionoptions);
        $mform->setDefault('direction', 'forward');

        $mform->addElement('text', 'days', get_string('shiftdaycount', 'local_homeschool'), ['size' => 4]);
        $mform->setType('days', PARAM_INT);
        $mform->setDefault('days', 1);
        $mform->addRule('days', null, 'required', null, 'client');
        $mform->addRule('days', null, 'numeric', null, 'client');
        $mform->addHelpButton('days', 'shiftdaycount', 'local_homeschool');

        $this->add_action_buttons(true, get_string('shiftpreview', 'local_homeschool'));
    }

    /**
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $maxday = max(1, (int) ($this->_customdata['maxday'] ?? 1));

        $days = (int) ($data['days'] ?? 0);
        if ($days < 1) {
            $errors['days'] = get_string('shiftdaycountinvalid', 'local_homeschool');
        } else if ($days > 365) {
            $errors['days'] = get_string('shiftdaycountmax', 'local_homeschool');
        }

        if (empty($data['alldays'])) {
            $fromday = (int) ($data['fromday'] ?? 0);
            $today = (int) ($data['today'] ?? 0);
            if ($fromday < 1 || $fromday > $maxday) {
                $errors['fromday'] = get_string('invaliddaynumber', 'local_homeschool');
            }
            if ($today < 1 || $today > $maxday) {
                $errors['today'] = get_string('invaliddaynumber', 'local_homeschool');
            }
            if ($fromday > 0 && $today > 0 && $fromday > $today) {
                $errors['today'] = get_string('shiftrangeinvalid', 'local_homeschool');
            }
        }

        return $errors;
    }
}
