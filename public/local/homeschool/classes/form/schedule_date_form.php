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
 * Bulk timeline reminder date for selected day activities.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule_date_form extends \moodleform {

    /** @var string HTML id for bulk date form (used by activity selection checkboxes). */
    public const FORM_ID = 'local-homeschool-bulk-date-form';

    /**
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->updateAttributes(['id' => self::FORM_ID]);
        $daynumber = (int) ($this->_customdata['daynumber'] ?? 0);

        $mform->addElement('hidden', 'day', $daynumber);
        $mform->setType('day', PARAM_INT);
        $mform->addElement('hidden', 'action', 'scheduledate');
        $mform->setType('action', PARAM_ALPHA);
        if (!empty($this->_customdata['showall'])) {
            $mform->addElement('hidden', 'showall', 1);
            $mform->setType('showall', PARAM_BOOL);
        }
        if (!empty($this->_customdata['showhidden'])) {
            $mform->addElement('hidden', 'showhidden', 1);
            $mform->setType('showhidden', PARAM_BOOL);
        }

        $mform->addElement(
            'date_time_selector',
            'scheduledate',
            get_string('scheduledate', 'local_homeschool'),
            array_merge(
                ['optional' => false],
                \local_homeschool\local\reminder_time::get_datetime_selector_options(),
            ),
        );
        $mform->addHelpButton('scheduledate', 'scheduledate', 'local_homeschool');

        $buttonarray = [];
        $buttonarray[] = $mform->createElement(
            'submit',
            'submitbutton',
            get_string('applydate', 'local_homeschool'),
        );
        $buttonarray[] = $mform->createElement(
            'submit',
            'cleardates',
            get_string('cleardateselected', 'local_homeschool'),
        );
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
    }
}
