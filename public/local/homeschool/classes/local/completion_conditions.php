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
 * Automatic completion condition helpers for the review UI.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_conditions {

    /**
     * Available boolean conditions for a course module.
     *
     * Ordered like the activity settings form: view, module rules, then grade.
     *
     * @param \cm_info $cm
     * @return \stdClass[] each: name, label, enabled, type (core|custom)
     */
    public static function get_available(\cm_info $cm): array {
        global $USER, $DB;

        $byname = [];

        if (plugin_supports('mod', $cm->modname, FEATURE_COMPLETION_TRACKS_VIEWS, true)) {
            $byname['completionview'] = (object) [
                'name' => 'completionview',
                'label' => get_string('completionview_desc', 'completion'),
                'enabled' => !empty($cm->completionview),
                'type' => 'core',
            ];
        }

        $classname = '\\mod_' . $cm->modname . '\\completion\\custom_completion';
        $sortorder = [
            'completionview',
            'completionusegrade',
            'completionpassgrade',
        ];
        $descriptions = [];

        if (class_exists($classname)) {
            try {
                /** @var \core_completion\activity_custom_completion $custom */
                $custom = new $classname($cm, (int) $USER->id);
                $descriptions = $custom->get_custom_rule_descriptions();
                $sortorder = $custom->get_sort_order();
            } catch (\Throwable $e) {
                $descriptions = [];
            }

            foreach ($classname::get_defined_custom_rules() as $rule) {
                if (!self::is_boolean_custom_rule($cm, $rule)) {
                    continue;
                }
                $enabled = false;
                $customdata = (array) $cm->get_custom_data();
                if (array_key_exists($rule, $customdata['customcompletionrules'] ?? [])) {
                    $enabled = !empty($customdata['customcompletionrules'][$rule]);
                } else if ($DB->get_manager()->field_exists($cm->modname, $rule)) {
                    $enabled = (bool) $DB->get_field($cm->modname, $rule, ['id' => $cm->instance]);
                }

                $byname[$rule] = (object) [
                    'name' => $rule,
                    'label' => $descriptions[$rule] ?? $rule,
                    'enabled' => $enabled,
                    'type' => 'custom',
                ];
            }
        }

        if (self::supports_grade_condition($cm)) {
            $usegrade = !is_null($cm->completiongradeitemnumber);
            $byname['completionusegrade'] = (object) [
                'name' => 'completionusegrade',
                'label' => get_string('completionusegrade_desc', 'completion'),
                'enabled' => $usegrade,
                'type' => 'core',
                'haspassgrade' => true,
                'passgrade' => !empty($cm->completionpassgrade),
            ];
        }

        $conditions = [];
        foreach ($sortorder as $name) {
            if (isset($byname[$name])) {
                $conditions[] = $byname[$name];
                unset($byname[$name]);
            }
        }
        // Any remaining conditions not listed in sort order (append after known order).
        foreach ($byname as $condition) {
            $conditions[] = $condition;
        }

        return $conditions;
    }

    /**
     * Whether at least one condition is enabled.
     *
     * @param \stdClass[] $conditions
     * @return bool
     */
    public static function has_enabled(array $conditions): bool {
        foreach ($conditions as $condition) {
            if (!empty($condition->enabled)) {
                return true;
            }
            if (!empty($condition->haspassgrade) && !empty($condition->passgrade) && !empty($condition->enabled)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read posted requirement fields into a normalized state.
     *
     * @param \cm_info $cm
     * @return array{completionview:int,completiongradeitemnumber:?int,completionpassgrade:int,custom:array<string,int>}
     */
    public static function read_posted_state(\cm_info $cm): array {
        $available = self::get_available($cm);
        $custom = [];
        $completionview = (int) $cm->completionview;
        $gradeitemnumber = $cm->completiongradeitemnumber;
        $passgrade = (int) ($cm->completionpassgrade ?? 0);

        $names = [];
        foreach ($available as $condition) {
            $names[$condition->name] = $condition;
        }

        if (isset($names['completionview'])) {
            $completionview = optional_param('requirement_completionview', 0, PARAM_BOOL) ? 1 : 0;
        }

        if (isset($names['completionusegrade'])) {
            $usegrade = optional_param('requirement_completionusegrade', 0, PARAM_BOOL);
            if ($usegrade) {
                $gradeitemnumber = is_null($cm->completiongradeitemnumber) ? 0 : (int) $cm->completiongradeitemnumber;
                $passgrade = optional_param('requirement_completionpassgrade', 0, PARAM_BOOL) ? 1 : 0;
            } else {
                $gradeitemnumber = null;
                $passgrade = 0;
            }
        }

        foreach ($available as $condition) {
            if ($condition->type !== 'custom') {
                continue;
            }
            $custom[$condition->name] = optional_param('requirement_' . $condition->name, 0, PARAM_BOOL) ? 1 : 0;
        }

        return [
            'completionview' => $completionview,
            'completiongradeitemnumber' => $gradeitemnumber,
            'completionpassgrade' => $passgrade,
            'custom' => $custom,
        ];
    }

    /**
     * Whether the posted/current automatic rules include at least one condition.
     *
     * @param array $state from read_posted_state()
     * @return bool
     */
    public static function state_has_condition(array $state): bool {
        if (!empty($state['completionview'])) {
            return true;
        }
        if (!is_null($state['completiongradeitemnumber'])) {
            return true;
        }
        foreach ($state['custom'] as $enabled) {
            if (!empty($enabled)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Persist condition fields for a course module.
     *
     * @param \cm_info $cm
     * @param array $state from read_posted_state()
     * @return bool true if anything changed
     */
    public static function apply(\cm_info $cm, array $state): bool {
        global $DB;

        $changed = false;

        $fields = [
            'completionview' => (int) $state['completionview'],
            'completionpassgrade' => (int) $state['completionpassgrade'],
        ];

        foreach ($fields as $field => $value) {
            if ((int) ($cm->$field ?? 0) !== $value) {
                $DB->set_field('course_modules', $field, $value, ['id' => $cm->id]);
                $changed = true;
            }
        }

        $newgrade = $state['completiongradeitemnumber'];
        $oldgrade = $cm->completiongradeitemnumber;
        if ((string) $oldgrade !== (string) $newgrade) {
            // Null must be written explicitly.
            $DB->set_field('course_modules', 'completiongradeitemnumber', $newgrade, ['id' => $cm->id]);
            $changed = true;
        }

        foreach ($state['custom'] as $rule => $enabled) {
            if (!$DB->get_manager()->field_exists($cm->modname, $rule)) {
                continue;
            }
            $current = (int) $DB->get_field($cm->modname, $rule, ['id' => $cm->instance]);
            if ($current !== (int) $enabled) {
                $DB->set_field($cm->modname, $rule, (int) $enabled, ['id' => $cm->instance]);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @param \cm_info $cm
     * @return bool
     */
    protected static function supports_grade_condition(\cm_info $cm): bool {
        if (!plugin_supports('mod', $cm->modname, FEATURE_GRADE_HAS_GRADE, false)) {
            return false;
        }
        // Only simple single-item grade completion in the review UI.
        $component = 'mod_' . $cm->modname;
        if (!class_exists('\core_grades\component_gradeitems')) {
            return true;
        }
        $itemnames = \core_grades\component_gradeitems::get_itemname_mapping_for_component($component);
        return count($itemnames) <= 1;
    }

    /**
     * Only expose custom rules that are simple on/off flags.
     *
     * @param \cm_info $cm
     * @param string $rule
     * @return bool
     */
    protected static function is_boolean_custom_rule(\cm_info $cm, string $rule): bool {
        global $DB;

        $customdata = (array) $cm->get_custom_data();
        if (array_key_exists($rule, $customdata['customcompletionrules'] ?? [])) {
            $value = $customdata['customcompletionrules'][$rule];
            if (is_array($value)) {
                return false;
            }
        }

        if (!$DB->get_manager()->field_exists($cm->modname, $rule)) {
            // Still allow showing rules that only live in customdata as 0/1.
            $value = $customdata['customcompletionrules'][$rule] ?? 0;
            return !is_array($value);
        }

        $columns = $DB->get_columns($cm->modname);
        $type = $columns[$rule]->meta_type ?? '';
        return in_array($type, ['I', 'N', 'B'], true);
    }
}
