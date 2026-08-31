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
 * Confirm and delete activities from Homeschool day review.
 *
 * @module     local_homeschool/deleteactivity
 * @copyright  2026 Sarah
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';
import ModalDeleteCancel from 'core/modal_delete_cancel';
import ModalEvents from 'core/modal_events';
import Notification from 'core/notification';

/**
 * Selected activity checkboxes.
 *
 * @param {HTMLElement} root
 * @return {HTMLInputElement[]}
 */
const getSelectedCheckboxes = (root) => {
    return Array.from(root.querySelectorAll('.local-homeschool-select-cm:checked'));
};

/**
 * Enable bulk delete when at least one activity is selected.
 *
 * @param {HTMLElement} root
 */
const syncBulkDeleteButton = (root) => {
    const button = root.querySelector('[data-action="local-homeschool-delete-selected"]');
    if (!button) {
        return;
    }
    button.disabled = getSelectedCheckboxes(root).length === 0;
};

/**
 * Submit a POST delete request.
 *
 * @param {Object} params
 * @param {string} params.reviewurl
 * @param {string} params.sesskey
 * @param {string} params.day
 * @param {string} params.showall
 * @param {number[]} params.cmids
 */
const submitDelete = ({reviewurl, sesskey, day, showall, cmids}) => {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = reviewurl;
    form.className = 'd-none';

    const fields = {
        sesskey,
        action: 'delete',
        day,
    };
    if (showall === '1') {
        fields.showall = '1';
    }

    Object.entries(fields).forEach(([name, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    });

    if (cmids.length === 1) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'cmid';
        input.value = String(cmids[0]);
        form.appendChild(input);
    } else {
        cmids.forEach((cmid) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'cmids[]';
            input.value = String(cmid);
            form.appendChild(input);
        });
    }

    document.body.appendChild(form);
    form.submit();
};

/**
 * Show Moodle's delete/cancel confirmation for one activity.
 *
 * @param {HTMLElement} button
 * @return {Promise<void>}
 */
const confirmAndDeleteSingle = async(button) => {
    const modal = await ModalDeleteCancel.create({
        title: getString('cmdelete_title', 'core_courseformat'),
        body: getString('cmdelete_info', 'core_courseformat', {
            type: button.dataset.modname,
            name: button.dataset.activityname,
        }),
        show: true,
        removeOnClose: true,
    });

    modal.getRoot().on(ModalEvents.delete, (e) => {
        e.preventDefault();
        modal.destroy();
        submitDelete({
            reviewurl: button.dataset.reviewurl,
            sesskey: button.dataset.sesskey,
            day: button.dataset.day,
            showall: button.dataset.showall,
            cmids: [parseInt(button.dataset.cmid, 10)],
        });
    });
};

/**
 * Show Moodle's delete/cancel confirmation for selected activities.
 *
 * @param {HTMLElement} button
 * @param {HTMLElement} root
 * @return {Promise<void>}
 */
const confirmAndDeleteSelected = async(button, root) => {
    const cmids = getSelectedCheckboxes(root).map((checkbox) => parseInt(checkbox.value, 10));
    if (cmids.length === 0) {
        return;
    }

    const modal = await ModalDeleteCancel.create({
        title: getString('cmsdelete_title', 'core_courseformat'),
        body: getString('cmsdelete_info', 'core_courseformat', {
            count: cmids.length,
        }),
        show: true,
        removeOnClose: true,
    });

    modal.getRoot().on(ModalEvents.delete, (e) => {
        e.preventDefault();
        modal.destroy();
        submitDelete({
            reviewurl: button.dataset.reviewurl,
            sesskey: button.dataset.sesskey,
            day: button.dataset.day,
            showall: button.dataset.showall,
            cmids,
        });
    });
};

/**
 * @return {void}
 */
export const init = () => {
    const root = document.querySelector('.local-homeschool-review');
    if (!root || root.dataset.deleteActivityBound) {
        return;
    }
    root.dataset.deleteActivityBound = '1';

    syncBulkDeleteButton(root);

    root.addEventListener('change', (event) => {
        if (event.target.matches('.local-homeschool-select-cm')) {
            syncBulkDeleteButton(root);
        }
    });

    root.addEventListener('click', (event) => {
        const bulkButton = event.target.closest('[data-action="local-homeschool-delete-selected"]');
        if (bulkButton && root.contains(bulkButton)) {
            event.preventDefault();
            confirmAndDeleteSelected(bulkButton, root).catch(Notification.exception);
            return;
        }

        const button = event.target.closest('[data-action="local-homeschool-delete-cm"]');
        if (!button || !root.contains(button)) {
            return;
        }
        event.preventDefault();
        confirmAndDeleteSingle(button).catch(Notification.exception);
    });
};
