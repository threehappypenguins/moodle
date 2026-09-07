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
 * Tiny transparent text configuration.
 *
 * @module      tiny_transparent/configuration
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {buttonName} from './common';
import {addToolbarButton, addMenubarItem} from 'editor_tiny/utils';

/**
 * Add the transparent text control to the toolbar and Format menu.
 *
 * @param {object} instanceConfig
 * @returns {object}
 */
export const configure = (instanceConfig) => {
    // Place a dedicated toolbar button with the other inline formats, and also add it to the Format menu.
    return {
        toolbar: addToolbarButton(instanceConfig.toolbar, 'formatting', buttonName, 'italic'),
        menu: addMenubarItem(instanceConfig.menu, 'format', buttonName, 'codeformat'),
    };
};
