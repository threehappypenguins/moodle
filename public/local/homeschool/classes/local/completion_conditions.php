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
 * Automatic completion condition helpers for the day page UI.
 *
 * @package   local_homeschool
 * @copyright 2026 Sarah
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_conditions {

    /**
     * Available conditions for a course module.
     *
     * Ordered like the activity settings form: view, module rules, then grade.
     *
     * @param \cm_info $cm
     * @return \stdClass[]
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
                'valuetype' => 'bool',
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
                $definition = self::describe_custom_rule($cm, $rule, $descriptions);
                if ($definition === null) {
                    continue;
                }
                $byname[$rule] = $definition;
            }
        }

        if (self::supports_grade_condition($cm)) {
            $usegrade = !is_null($cm->completiongradeitemnumber);
            $gradeitem = self::get_completion_grade_item($cm);
            $canrequirepassgrade = self::grade_item_has_pass($gradeitem);
            $grade = (object) [
                'name' => 'completionusegrade',
                'label' => get_string('completionusegrade_desc', 'completion'),
                'enabled' => $usegrade,
                'type' => 'core',
                'valuetype' => 'bool',
                'haspassgrade' => true,
                'canrequirepassgrade' => $canrequirepassgrade,
                'passgrade' => $canrequirepassgrade && !empty($cm->completionpassgrade),
                'hasexhausted' => false,
                'exhausted' => false,
                'exhaustedlabel' => '',
            ];

            // Quiz nests "passing grade or all attempts" under the pass-grade choice.
            if ($cm->modname === 'quiz' && $DB->get_manager()->field_exists('quiz', 'completionattemptsexhausted')) {
                $exhausted = false;
                $customdata = (array) $cm->get_custom_data();
                $composite = $customdata['customcompletionrules']['completionpassorattemptsexhausted'] ?? null;
                if (is_array($composite)) {
                    $exhausted = !empty($composite['completionattemptsexhausted']);
                } else {
                    $exhausted = (bool) $DB->get_field('quiz', 'completionattemptsexhausted', ['id' => $cm->instance]);
                }
                $grade->hasexhausted = true;
                $grade->exhausted = $exhausted;
                $grade->exhaustedlabel = get_string('completionattemptsexhausted', 'quiz');
            }

            $byname['completionusegrade'] = $grade;
        }

        $conditions = [];
        foreach ($sortorder as $name) {
            if (isset($byname[$name])) {
                $conditions[] = $byname[$name];
                unset($byname[$name]);
            }
        }
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

            if (!empty($names['completionusegrade']->hasexhausted)) {
                $exhausted = ($usegrade && $passgrade && optional_param('requirement_completionattemptsexhausted', 0, PARAM_BOOL))
                    ? 1 : 0;
                $custom['completionattemptsexhausted'] = $exhausted;
            }
        }

        foreach ($available as $condition) {
            if ($condition->type !== 'custom') {
                continue;
            }
            if (($condition->valuetype ?? 'bool') === 'int') {
                $enabled = optional_param('requirement_' . $condition->name . '_enabled', 0, PARAM_BOOL);
                $value = optional_param('requirement_' . $condition->name, 1, PARAM_INT);
                $custom[$condition->name] = $enabled ? max(1, $value) : 0;
            } else {
                $custom[$condition->name] = optional_param('requirement_' . $condition->name, 0, PARAM_BOOL) ? 1 : 0;
            }
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
        foreach ($state['custom'] as $name => $value) {
            // Exhausted only applies with a passing grade, which already counts above.
            if ($name === 'completionattemptsexhausted') {
                continue;
            }
            if (!empty($value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate posted/custom completion state before persisting.
     *
     * @param \cm_info $cm
     * @param array $state from read_posted_state() or snapshot_state()
     * @return string|null error message, or null if valid
     */
    public static function validate_posted_state(\cm_info $cm, array $state): ?string {
        if (!is_null($state['completiongradeitemnumber'])) {
            if (!self::supports_grade_condition($cm)) {
                return get_string(
                    'nogradeitem',
                    'completion',
                    format_string($cm->name, true, ['context' => \context_module::instance($cm->id)]),
                );
            }
        }

        if (!empty($state['completionpassgrade'])) {
            $gradeitem = self::get_completion_grade_item($cm);
            if (!self::grade_item_has_pass($gradeitem)) {
                return get_string('activitygradetopassnotset', 'completion');
            }
        }

        foreach ($state['custom'] as $rule => $value) {
            if (empty($value)) {
                continue;
            }
            if (!self::is_integer_custom_rule($cm, $rule)) {
                continue;
            }
            $error = self::validate_integer_custom_rule($cm, $rule, (int) $value);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
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
            $DB->set_field('course_modules', 'completiongradeitemnumber', $newgrade, ['id' => $cm->id]);
            $changed = true;
        }

        foreach ($state['custom'] as $rule => $value) {
            if (!$DB->get_manager()->field_exists($cm->modname, $rule)) {
                continue;
            }
            $current = (int) $DB->get_field($cm->modname, $rule, ['id' => $cm->instance]);
            if ($current !== (int) $value) {
                $DB->set_field($cm->modname, $rule, (int) $value, ['id' => $cm->instance]);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Snapshot current condition values for use when switching to automatic without a POST body.
     *
     * @param \cm_info $cm
     * @return array
     */
    public static function snapshot_state(\cm_info $cm): array {
        $custom = [];
        foreach (self::get_available($cm) as $condition) {
            if ($condition->type !== 'custom') {
                continue;
            }
            if (($condition->valuetype ?? 'bool') === 'int') {
                $custom[$condition->name] = !empty($condition->enabled) ? (int) ($condition->value ?? 1) : 0;
            } else {
                $custom[$condition->name] = !empty($condition->enabled) ? 1 : 0;
            }
            if (!empty($condition->hasexhausted)) {
                $custom['completionattemptsexhausted'] = !empty($condition->exhausted) ? 1 : 0;
            }
        }

        return [
            'completionview' => (int) $cm->completionview,
            'completiongradeitemnumber' => $cm->completiongradeitemnumber,
            'completionpassgrade' => (int) ($cm->completionpassgrade ?? 0),
            'custom' => $custom,
        ];
    }

    /**
     * @param \cm_info $cm
     * @return bool
     */
    protected static function supports_grade_condition(\cm_info $cm): bool {
        if (!plugin_supports('mod', $cm->modname, FEATURE_GRADE_HAS_GRADE, false)) {
            return false;
        }
        $component = 'mod_' . $cm->modname;
        if (!class_exists('\core_grades\component_gradeitems')) {
            return self::completion_grade_item_is_active(self::get_completion_grade_item($cm));
        }
        $itemnames = \core_grades\component_gradeitems::get_itemname_mapping_for_component($component);
        if (count($itemnames) > 1) {
            return false;
        }
        return self::completion_grade_item_is_active(self::get_completion_grade_item($cm));
    }

    /**
     * Fetch the grade item used for completion grade rules (single-item modules).
     *
     * @param \cm_info $cm
     * @param int $itemnumber
     * @return \grade_item|null
     */
    protected static function get_completion_grade_item(\cm_info $cm, int $itemnumber = 0): ?\grade_item {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $item = \grade_item::fetch([
            'courseid' => $cm->course,
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
            'itemnumber' => $itemnumber,
        ]);

        return $item ?: null;
    }

    /**
     * Whether a grade item can satisfy "receive a grade" completion.
     *
     * @param \grade_item|null $item
     * @return bool
     */
    protected static function completion_grade_item_is_active(?\grade_item $item): bool {
        if (!$item) {
            return false;
        }

        return in_array((int) $item->gradetype, [GRADE_TYPE_VALUE, GRADE_TYPE_SCALE], true);
    }

    /**
     * Whether the grade item has a non-zero grade to pass.
     *
     * @param \grade_item|null $item
     * @return bool
     */
    protected static function grade_item_has_pass(?\grade_item $item): bool {
        if (!$item) {
            return false;
        }

        $gradepass = unformat_float($item->gradepass);
        return $gradepass !== false && $gradepass != 0;
    }

    /**
     * Build a custom rule definition for the day page UI, or null to skip.
     *
     * @param \cm_info $cm
     * @param string $rule
     * @param array $descriptions from custom_completion::get_custom_rule_descriptions()
     * @return \stdClass|null
     */
    protected static function describe_custom_rule(\cm_info $cm, string $rule, array $descriptions): ?\stdClass {
        global $DB;

        // Composite quiz rule is edited via grade + nested exhausted checkbox.
        if ($rule === 'completionpassorattemptsexhausted') {
            return null;
        }

        $customdata = (array) $cm->get_custom_data();
        $raw = $customdata['customcompletionrules'][$rule] ?? null;

        if (is_array($raw)) {
            return null;
        }

        if ($rule === 'completionminattempts' || self::is_integer_custom_rule($cm, $rule)) {
            $value = 0;
            if ($raw !== null && $raw !== '') {
                $value = (int) $raw;
            } else if ($DB->get_manager()->field_exists($cm->modname, $rule)) {
                $value = (int) $DB->get_field($cm->modname, $rule, ['id' => $cm->instance]);
            }

            $limits = self::get_integer_custom_rule_limits($cm, $rule);

            return (object) [
                'name' => $rule,
                'label' => self::custom_rule_label($cm, $rule, $descriptions),
                'enabled' => $value > 0,
                'type' => 'custom',
                'valuetype' => 'int',
                'value' => $value > 0 ? $value : 1,
                'min' => $limits['min'],
                'max' => $limits['max'],
            ];
        }

        if (!self::is_boolean_custom_rule($cm, $rule)) {
            return null;
        }

        $enabled = false;
        if ($raw !== null) {
            $enabled = !empty($raw);
        } else if ($DB->get_manager()->field_exists($cm->modname, $rule)) {
            $enabled = (bool) $DB->get_field($cm->modname, $rule, ['id' => $cm->instance]);
        }

        return (object) [
            'name' => $rule,
            'label' => self::custom_rule_label($cm, $rule, $descriptions),
            'enabled' => $enabled,
            'type' => 'custom',
            'valuetype' => 'bool',
        ];
    }

    /**
     * Prefer settings-form language strings over student-facing detail strings.
     *
     * @param \cm_info $cm
     * @param string $rule
     * @param array $descriptions
     * @return string
     */
    protected static function custom_rule_label(\cm_info $cm, string $rule, array $descriptions): string {
        $manager = get_string_manager();
        foreach ([$cm->modname, 'mod_' . $cm->modname] as $component) {
            if ($manager->string_exists($rule, $component)) {
                return get_string($rule, $component);
            }
        }

        // Avoid student detail strings like "Make attempts: 0" as admin labels.
        if (isset($descriptions[$rule]) && !str_contains($rule, 'completionminattempts')) {
            return $descriptions[$rule];
        }

        return $rule;
    }

    /**
     * Min/max bounds for integer custom rules, aligned with core mod_form validation.
     *
     * @param \cm_info $cm
     * @param string $rule
     * @return array{min:int,max:?int}
     */
    protected static function get_integer_custom_rule_limits(\cm_info $cm, string $rule): array {
        $limits = [
            'min' => 1,
            'max' => null,
        ];

        if ($cm->modname === 'quiz' && $rule === 'completionminattempts') {
            global $DB;
            $attempts = (int) $DB->get_field('quiz', 'attempts', ['id' => $cm->instance]);
            if ($attempts > 0) {
                $limits['max'] = $attempts;
            }
        }

        return $limits;
    }

    /**
     * Validate one integer custom rule value.
     *
     * @param \cm_info $cm
     * @param string $rule
     * @param int $value enabled value (0 when disabled)
     * @return string|null error message, or null if valid
     */
    protected static function validate_integer_custom_rule(\cm_info $cm, string $rule, int $value): ?string {
        if ($value <= 0) {
            return null;
        }

        $limits = self::get_integer_custom_rule_limits($cm, $rule);
        if ($value < $limits['min']) {
            return get_string('invalidcompletioncondition', 'local_homeschool', $limits['min']);
        }
        if ($limits['max'] !== null && $value > $limits['max']) {
            if ($cm->modname === 'quiz' && $rule === 'completionminattempts') {
                return get_string('completionminattemptserror', 'quiz');
            }
            return get_string('invalidcompletionconditionmax', 'local_homeschool', $limits['max']);
        }

        return null;
    }

    /**
     * Integer-valued custom rules (checkbox + number), e.g. quiz minimum attempts.
     *
     * @param \cm_info $cm
     * @param string $rule
     * @return bool
     */
    protected static function is_integer_custom_rule(\cm_info $cm, string $rule): bool {
        // Count / minimum-threshold rules edited as checkbox + integer in core mod forms.
        return in_array($rule, [
            'completionminattempts',
            'completionentries',
            'completionposts',
            'completiondiscussions',
            'completionreplies',
        ], true);
    }

    /**
     * Only expose custom rules that are simple on/off flags.
     *
     * Duration, score, and status-threshold rules are excluded so they are neither
     * shown as checkboxes nor rewritten to 0/1 on save.
     *
     * @param \cm_info $cm
     * @param string $rule
     * @return bool
     */
    protected static function is_boolean_custom_rule(\cm_info $cm, string $rule): bool {
        global $DB;

        if (self::is_integer_custom_rule($cm, $rule)) {
            return false;
        }

        // Non-boolean integers / special controls (duration, score, status bitmask).
        if (in_array($rule, [
            'completiontimespent',
            'completionscorerequired',
            'completionstatusrequired',
        ], true)) {
            return false;
        }

        $customdata = (array) $cm->get_custom_data();
        if (array_key_exists($rule, $customdata['customcompletionrules'] ?? [])) {
            $value = $customdata['customcompletionrules'][$rule];
            if (is_array($value)) {
                return false;
            }
        }

        if (!$DB->get_manager()->field_exists($cm->modname, $rule)) {
            $value = $customdata['customcompletionrules'][$rule] ?? 0;
            return !is_array($value);
        }

        $columns = $DB->get_columns($cm->modname);
        $type = $columns[$rule]->meta_type ?? '';
        // Integer DB columns that are not known count/duration/score rules are on/off flags.
        return in_array($type, ['I', 'N', 'B'], true);
    }
}
