<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Test page which renders the YUI exception dialogue.
 *
 * The dialogue is normally only reachable by making an AJAX request fail, which is not something a test can arrange
 * reliably, so this renders it directly instead. The dialogue keeps its file, line and stack trace rows hidden
 * unless developer debugging is on, and those rows are most of what there is to look at, so this turns it on for
 * its own request rather than depending on the level the site happens to be running at.
 *
 * @copyright 2026 Jun Pataleta <jun@moodle.com>
 * @package   core
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

require_once(__DIR__ . '/../../../../config.php');

defined('BEHAT_SITE_RUNNING') || die();

global $CFG, $PAGE, $OUTPUT;

require_login();

// Show the file, line and stack trace rows. Set on $CFG directly, including the derived flag the dialogue reads,
// so that it holds for this request whatever level the site or the test run has debugging at.
$CFG->debug = DEBUG_DEVELOPER;
$CFG->debugdeveloper = true;

$PAGE->set_url('/lib/tests/behat/fixtures/yui_exception_dialogue_testpage.php');
$PAGE->set_context(core\context\system::instance());
$PAGE->set_title('YUI exception dialogue fixture');

// The stack is parsed by M.core.exception with the pattern "call@url:line", which is what splits each frame into the
// separate file, line and call elements the dialogue styles individually. Two frames, so that each of those three
// is present more than once.
$backtrace = 'fixture_inner_call@' . $CFG->wwwroot . '/lib/setuplib.php:123' . "\n"
    . 'fixture_outer_call@' . $CFG->wwwroot . '/lib/classes/router.php:456';

$encodedbacktrace = json_encode($backtrace);

$PAGE->requires->js_amd_inline(<<<JS
    require(['core/notification'], function(Notification) {
        M.util.js_pending('fixture/yui_exception_dialogue');
        Notification.exception({
            name: 'Fixture exception',
            message: 'A fixture exception, rendered so that the dialogue can be inspected.',
            fileName: 'lib/tests/behat/fixtures/yui_exception_dialogue_testpage.php',
            lineNumber: 123,
            backtrace: {$encodedbacktrace}
        });

        // The dialogue is rendered here deliberately, but Behat fails any step which finds a node marked
        // data-rel="fatalerror", since everywhere else that marker means the page has broken. Drop it once the
        // dialogue is up. Behat waits for the pending key before it looks for exceptions, so this is not a race.
        var clearfatalerrormarker = function() {
            var node = document.querySelector('.moodle-exception[data-rel="fatalerror"]');
            if (!node) {
                setTimeout(clearfatalerrormarker, 50);
                return;
            }
            node.removeAttribute('data-rel');
            M.util.js_complete('fixture/yui_exception_dialogue');
        };
        clearfatalerrormarker();
    });
JS);

echo $OUTPUT->header();
echo $OUTPUT->footer();
