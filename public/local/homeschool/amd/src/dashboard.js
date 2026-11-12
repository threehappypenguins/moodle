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
 * Homeschool dashboard page interactions.
 *
 * @module     local_homeschool/dashboard
 * @copyright  2026 Sarah
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Bind dashboard day picker and filter checkboxes.
 */
export const init = () => {
    const daySelect = document.getElementById('local-homeschool-dashboard-day');
    if (daySelect && daySelect.dataset.dayurl) {
        daySelect.addEventListener('change', () => {
            if (!daySelect.value) {
                return;
            }
            const url = new URL(daySelect.dataset.dayurl, window.location.origin);
            url.searchParams.set('day', daySelect.value);
            window.location.href = url.toString();
        });
    }

    ['local-homeschool-showhidden', 'local-homeschool-showotherformats'].forEach((id) => {
        const checkbox = document.getElementById(id);
        if (!checkbox || !checkbox.form) {
            return;
        }
        checkbox.addEventListener('change', () => {
            checkbox.form.submit();
        });
    });
};
