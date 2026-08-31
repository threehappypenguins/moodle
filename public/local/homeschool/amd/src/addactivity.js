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
 * Open Moodle's activity chooser for a selected course/day from Homeschool review.
 *
 * @module     local_homeschool/addactivity
 * @copyright  2026 Sarah
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as ChooserDialogue from 'core_courseformat/local/activitychooser/dialogue';
import Config from 'core/config';
import Notification from 'core/notification';
import Pending from 'core/pending';
import * as Repository from 'core_courseformat/local/activitychooser/repository';

/**
 * Wrap chooser module links so they arm a Homeschool review return before modedit.
 *
 * @param {Array} modules
 * @param {Number} day
 * @param {Boolean} showall
 * @return {Array}
 */
const wrapModuleLinksForReturn = (modules, day, showall) => {
    if (!Array.isArray(modules) || day < 1) {
        return modules;
    }

    return modules.map((module) => {
        const params = new URLSearchParams({
            sesskey: Config.sesskey,
            day: String(day),
            goto: module.link,
        });
        if (showall) {
            params.set('showall', '1');
        }
        return {
            ...module,
            link: `${Config.wwwroot}/local/homeschool/modedit_bridge.php?${params.toString()}`,
        };
    });
};

/**
 * @method init
 */
export const init = () => {
    const root = document.querySelector('.local-homeschool-add-activity');
    if (!root) {
        return;
    }

    const select = root.querySelector('#local-homeschool-add-course');
    const button = root.querySelector('[data-action="local-homeschool-open-chooser"]');
    const missingHelp = root.querySelector('.local-homeschool-add-missing');
    if (!select || !button) {
        return;
    }

    const day = parseInt(root.dataset.day || '0', 10);
    const showall = root.dataset.showall === '1';

    const syncControls = () => {
        const option = select.options[select.selectedIndex];
        const missing = !!(option && option.dataset.missingsection === '1');
        const ready = !!(option && option.value && !option.disabled && !missing);
        button.disabled = !ready;
        if (missingHelp) {
            missingHelp.hidden = !missing;
            if (missing && option.dataset.courseurl) {
                const link = missingHelp.querySelector('a');
                if (link) {
                    link.href = option.dataset.courseurl;
                }
            }
        }
    };

    select.addEventListener('change', syncControls);
    syncControls();

    button.addEventListener('click', async(event) => {
        event.preventDefault();
        const option = select.options[select.selectedIndex];
        if (!option || !option.value || option.disabled || option.dataset.missingsection === '1') {
            return;
        }

        const pending = new Pending('local_homeschool/addactivity:open');
        try {
            const courseId = parseInt(option.value, 10);
            const sectionId = option.dataset.sectionId;
            const sectionNum = parseInt(option.dataset.sectionNum, 10);
            let returnOptions = {};
            try {
                returnOptions = JSON.parse(option.dataset.returnoptions || '{}');
            } catch (e) {
                returnOptions = {};
            }

            const footerDataPromise = Repository.getModalFooterData(courseId, sectionNum);
            const modulesDataPromise = Repository.getSectionModulesData(
                courseId,
                sectionId,
                returnOptions,
                null,
            ).then((modules) => wrapModuleLinksForReturn(modules, day, showall));
            await ChooserDialogue.displayActivityChooserModal(footerDataPromise, modulesDataPromise);
        } catch (error) {
            Notification.exception(error);
        } finally {
            pending.resolve();
        }
    });
};
