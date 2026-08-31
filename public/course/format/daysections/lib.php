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
 * Main class for the Day sections course format.
 *
 * @package   format_daysections
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/format/topics/lib.php');

use core\lang_string;
use core_courseformat\local\linearnavigationsettings;

/**
 * Day sections course format.
 *
 * Extends the topics format but restores numbered default section names
 * (e.g. "Day 1", "Day 2") using a configurable prefix.
 *
 * @package    format_daysections
 * @copyright  2026 Sarah
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class format_daysections extends format_topics {

    /**
     * Returns whether this course format uses activity indentation.
     *
     * @return bool
     */
    public function uses_indentation(): bool {
        $indentation = get_config('format_daysections', 'indentation');
        if ($indentation !== false) {
            return (bool) $indentation;
        }
        return parent::uses_indentation();
    }

    /**
     * Returns the default section name for this course format.
     *
     * Section 0 uses section0name. Other sections use the configured prefix plus the section number.
     *
     * @param int|stdClass $section Section object from database or just field course_sections section
     * @return string The default value for the section name.
     */
    public function get_default_section_name($section) {
        $section = $this->get_section($section);
        if ($section->sectionnum == 0) {
            return get_string('section0name', 'format_daysections');
        }

        return $this->get_section_prefix() . ' ' . $section->sectionnum;
    }

    /**
     * Returns the generic name for sections in this course format.
     *
     * @return string
     */
    public function get_generic_section_name() {
        return $this->get_section_prefix();
    }

    /**
     * Returns the prefix used in default section names.
     *
     * Priority: per-course format option, site admin setting, language string.
     *
     * @return string
     */
    protected function get_section_prefix(): string {
        $options = $this->get_format_options();
        if (!empty($options['sectionprefix']) && trim($options['sectionprefix']) !== '') {
            return trim($options['sectionprefix']);
        }

        $configprefix = get_config('format_daysections', 'sectionprefix');
        if ($configprefix !== false && trim($configprefix) !== '') {
            return trim($configprefix);
        }

        return get_string('sectionname', 'format_daysections');
    }

    /**
     * Definitions of the additional options that this course format uses for course.
     *
     * Built independently of format_topics to avoid that format's static option cache.
     *
     * @param bool $foreditform
     * @return array of options
     */
    public function course_format_options($foreditform = false) {
        $courseconfig = get_config('moodlecourse');
        $defaultprefix = get_config('format_daysections', 'sectionprefix');

        $courseformatoptions = [
            'hiddensections' => [
                'default' => $courseconfig->hiddensections,
                'type' => PARAM_INT,
            ],
            'coursedisplay' => [
                'default' => $courseconfig->coursedisplay,
                'type' => PARAM_INT,
            ],
            'sectionprefix' => [
                'default' => ($defaultprefix !== false) ? $defaultprefix : '',
                'type' => PARAM_TEXT,
            ],
        ];

        $courseformatoptions = array_merge(
            linearnavigationsettings::get_course_format_options_default($this->get_format()),
            $courseformatoptions,
        );

        if ($foreditform && !isset($courseformatoptions['coursedisplay']['label'])) {
            $hiddensectionslist = new \core\output\choicelist();
            $hiddensectionslist->set_allow_empty(false);
            $hiddensectionslist->add_option(
                1,
                new lang_string('hiddensectionsinvisible'),
                [
                    'description' => new lang_string('hiddensectionsinvisible_description'),
                ],
            );
            $hiddensectionslist->add_option(
                0,
                new lang_string('hiddensectionscollapsed'),
                [
                    'description' => new lang_string('hiddensectionscollapsed_description'),
                ],
            );

            $courseformatoptionsedit = [
                'hiddensections' => [
                    'label' => new lang_string('hiddensections'),
                    'element_type' => 'choicedropdown',
                    'element_attributes' => [
                        $hiddensectionslist,
                    ],
                ],
                'coursedisplay' => [
                    'label' => new lang_string('coursedisplay'),
                    'element_type' => 'select',
                    'element_attributes' => [
                        [
                            COURSE_DISPLAY_SINGLEPAGE => new lang_string('coursedisplay_single'),
                            COURSE_DISPLAY_MULTIPAGE => new lang_string('coursedisplay_multi'),
                        ],
                    ],
                    'help' => 'coursedisplay',
                    'help_component' => 'moodle',
                ],
                'sectionprefix' => [
                    'label' => new lang_string('sectionprefix', 'format_daysections'),
                    'help' => 'sectionprefix',
                    'help_component' => 'format_daysections',
                    'element_type' => 'text',
                    'element_attributes' => [
                        ['size' => 30, 'maxlength' => 255],
                    ],
                ],
            ];

            $courseformatoptions = array_merge_recursive(
                $courseformatoptions,
                linearnavigationsettings::get_course_format_options_edit_form($this->get_format()),
            );
            $courseformatoptions = array_merge_recursive($courseformatoptions, $courseformatoptionsedit);
        }

        return $courseformatoptions;
    }

    /**
     * Callback used in WS core_course_edit_section when teacher performs an AJAX action on a section.
     *
     * @param section_info|stdClass $section
     * @param string $action
     * @param int $sr
     * @return null|array any data for the Javascript post-processor (must be json-encodeable)
     */
    public function section_action($section, $action, $sr) {
        global $PAGE;

        if ($section->section && ($action === 'setmarker' || $action === 'removemarker')) {
            require_capability('moodle/course:setcurrentsection', context_course::instance($this->courseid));
            if ($action === 'setmarker') {
                $sectioninfo = get_fast_modinfo($this->courseid)->get_section_info($section->section);
                \core_courseformat\formatactions::section($this->courseid)->set_marker($sectioninfo, true);
            } else {
                \core_courseformat\formatactions::section($this->courseid)->remove_all_markers();
            }
            return null;
        }

        $rv = parent::section_action($section, $action, $sr);
        $renderer = $this->get_renderer($PAGE);

        if (!($section instanceof section_info)) {
            $modinfo = course_modinfo::instance($this->courseid);
            $section = $modinfo->get_section_info($section->section);
        }
        $elementclass = $this->get_output_classname('content\\section\\availability');
        $availability = new $elementclass($this, $section);

        $rv['section_availability'] = $renderer->render($availability);
        return $rv;
    }

    /**
     * Return the plugin configs for external functions.
     *
     * @return array the list of configuration settings
     */
    public function get_config_for_external() {
        $formatoptions = $this->get_format_options();
        $indentation = get_config('format_daysections', 'indentation');
        if ($indentation !== false) {
            $formatoptions['indentation'] = $indentation;
        }
        return $formatoptions;
    }
}

/**
 * Implements callback inplace_editable() allowing to edit values in-place.
 *
 * @param string $itemtype
 * @param int $itemid
 * @param mixed $newvalue
 * @return core\output\inplace_editable|null
 */
function format_daysections_inplace_editable($itemtype, $itemid, $newvalue) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/course/lib.php');
    if ($itemtype === 'sectionname' || $itemtype === 'sectionnamenl') {
        $section = $DB->get_record_sql(
            'SELECT s.* FROM {course_sections} s JOIN {course} c ON s.course = c.id WHERE s.id = ? AND c.format = ?',
            [$itemid, 'daysections'],
            MUST_EXIST,
        );
        return course_get_format($section->course)->inplace_editable_update_section_name($section, $itemtype, $newvalue);
    }
    return null;
}
