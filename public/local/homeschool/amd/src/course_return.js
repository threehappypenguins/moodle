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
 * Append a Homeschool flow token to core's post-modedit course landing URL.
 *
 * Each modedit tab stores its flow token in session storage so concurrent edits of
 * the same activity can return to the correct day page.
 *
 * @module     local_homeschool/course_return
 * @copyright  2026 Sarah
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {String} Session storage key for the per-tab modedit flow token. */
const TAB_FLOW_STORAGE_KEY = 'local_homeschool_modedit_return';

/**
 * Remember the flow token for this modedit tab until the course landing page loads.
 *
 * @param {String} token Flow token from the modedit launch URL
 */
export const armModedit = (token) => {
    if (!token) {
        return;
    }
    sessionStorage.setItem(TAB_FLOW_STORAGE_KEY, token);
};

/**
 * @param {Array} landings Pending landing descriptors from the server
 * @param {String} flowparam Query parameter name for the flow token
 */
export const init = (landings, flowparam) => {
    if (!Array.isArray(landings) || landings.length === 0 || !flowparam) {
        return;
    }

    const token = sessionStorage.getItem(TAB_FLOW_STORAGE_KEY);
    if (!token) {
        return;
    }

    const landing = landings.find((entry) => entry.token === token);
    if (!landing) {
        return;
    }

    const hashmatch = /^#module-(\d+)$/.exec(window.location.hash || '');
    if (!hashmatch) {
        return;
    }

    const cmid = parseInt(hashmatch[1], 10);
    if (landing.cmid !== cmid) {
        return;
    }

    sessionStorage.removeItem(TAB_FLOW_STORAGE_KEY);

    const url = new URL(window.location.href);
    if (url.searchParams.get(flowparam) === landing.token) {
        return;
    }

    url.searchParams.set(flowparam, landing.token);
    window.location.replace(url.toString());
};
