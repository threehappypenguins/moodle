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
 * Tiny transparent text commands.
 *
 * @module      tiny_transparent/commands
 * @copyright   2026 Sarah
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getButtonImage} from 'editor_tiny/utils';
import {get_string as getString} from 'core/str';
import {
    component,
    buttonName,
    buttonIcon,
    formatName,
    className,
    shortcut,
} from './common';

/**
 * Register the transparent text formatter with the editor.
 *
 * @param {TinyMCE} editor
 */
const registerFormat = (editor) => {
    editor.formatter.register(formatName, {
        inline: 'span',
        classes: className,
        exact: true,
    });
};

/**
 * Keep a toggle control in sync with whether the current selection is transparent.
 *
 * @param {TinyMCE} editor
 * @returns {function(*): function(): *}
 */
const toggleActiveState = (editor) => (api) => {
    const updateState = () => {
        api.setActive(!editor.mode.isReadOnly() && editor.formatter.match(formatName));
    };
    updateState();
    const changed = editor.formatter.formatChanged(formatName, (state) => api.setActive(state));
    return () => changed.unbind();
};

/**
 * Get the setup function for the buttons.
 *
 * @returns {function(TinyMCE): void}
 */
export const getSetup = async() => {
    const [
        buttonText,
        buttonImage,
    ] = await Promise.all([
        getString('buttontitle', component),
        getButtonImage('icon', component),
    ]);

    return (editor) => {
        editor.on('PreInit', () => {
            registerFormat(editor);
        });

        editor.addCommand(formatName, () => {
            editor.formatter.toggle(formatName);
            editor.nodeChanged();
        });

        const toggleTransparent = () => {
            editor.execCommand(formatName);
        };

        editor.ui.registry.addIcon(buttonIcon, buttonImage.html);

        editor.ui.registry.addToggleButton(buttonName, {
            icon: buttonIcon,
            tooltip: buttonText,
            onAction: toggleTransparent,
            onSetup: toggleActiveState(editor),
        });

        editor.ui.registry.addToggleMenuItem(buttonName, {
            icon: buttonIcon,
            text: buttonText,
            shortcut,
            onAction: toggleTransparent,
            onSetup: toggleActiveState(editor),
        });

        editor.shortcuts.add(shortcut, buttonText, toggleTransparent);
    };
};
