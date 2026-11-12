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
 * Clear reminders page interactions.
 *
 * @module     local_homeschool/clearreminders
 * @copyright  2026 Sarah
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Bind course select auto-submit and day checkbox helpers.
 */
export const init = () => {
    const courseSelect = document.getElementById('local-homeschool-clear-course');
    if (courseSelect && courseSelect.form) {
        courseSelect.addEventListener('change', () => {
            courseSelect.form.submit();
        });
    }

    const form = document.getElementById('local-homeschool-clear-reminders-form');
    if (!form) {
        return;
    }

    const syncSubmit = () => {
        const submit = document.getElementById('local-homeschool-clear-submit');
        if (!submit) {
            return;
        }
        submit.disabled = form.querySelectorAll('.local-homeschool-select-day:checked:not(:disabled)').length === 0;
    };

    const setAll = (checked) => {
        form.querySelectorAll('.local-homeschool-select-day:not(:disabled)').forEach((cb) => {
            cb.checked = checked;
        });
        syncSubmit();
    };

    form.addEventListener('change', (event) => {
        if (event.target.classList.contains('local-homeschool-select-day')) {
            syncSubmit();
        }
    });

    form.addEventListener('click', (event) => {
        const selectAll = event.target.closest('[data-action="local-homeschool-selectall"]');
        const deselectAll = event.target.closest('[data-action="local-homeschool-deselectall"]');
        if (!selectAll && !deselectAll) {
            return;
        }
        event.preventDefault();
        setAll(!!selectAll);
    });

    syncSubmit();
};
