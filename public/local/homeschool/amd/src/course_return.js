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
 * Core redirects to course/view.php#module-{cmid} without query params. This module
 * matches the hash against flows recorded at save time and reloads with the token
 * so the server hook can redirect to the Homeschool day page.
 *
 * @module     local_homeschool/course_return
 * @copyright  2026 Sarah
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @param {Array} landings Pending landing descriptors from the server
 * @param {String} flowparam Query parameter name for the flow token
 */
export const init = (landings, flowparam) => {
    if (!Array.isArray(landings) || landings.length === 0 || !flowparam) {
        return;
    }

    const hashmatch = /^#module-(\d+)$/.exec(window.location.hash || '');
    if (!hashmatch) {
        return;
    }

    const cmid = parseInt(hashmatch[1], 10);
    const landing = landings.find((entry) => entry.cmid === cmid);
    if (!landing || !landing.token) {
        return;
    }

    const url = new URL(window.location.href);
    if (url.searchParams.get(flowparam) === landing.token) {
        return;
    }

    url.searchParams.set(flowparam, landing.token);
    window.location.replace(url.toString());
};
