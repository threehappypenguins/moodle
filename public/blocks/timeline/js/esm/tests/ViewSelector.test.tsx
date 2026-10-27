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
 * Jest tests for the ViewSelector (sort-by-dates / sort-by-courses) dropdown.
 *
 * @module     block_timeline/tests/ViewSelector
 * @copyright  Meirza Arson <meirza.arson@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {act, render, screen, waitFor, fireEvent} from '@testing-library/react';
import ViewSelector from '../src/nav/ViewSelector';

async function renderSelector(...args: Parameters<typeof render>) {
    let result!: ReturnType<typeof render>;
    await act(async() => {
        result = render(...args);
    });
    return result;
}

// Every option shares the same mocked aria-label, which overrides its accessible
// name for role queries — so items are located by data-filtername instead.
function optionFor(container: HTMLElement, filtername: string): HTMLElement {
    const el = container.querySelector(`[data-filtername="${filtername}"]`);
    if (!el) {
        throw new Error(`No option found for filtername "${filtername}"`);
    }
    return el as HTMLElement;
}

beforeEach(() => {
    (globalThis as any).mockString('ariaviewselector', 'block_timeline', 'Sort by');
    (globalThis as any).mockString('ariaviewselectorbutton', 'block_timeline', 'Sort by dates: sort timeline items');
    (globalThis as any).mockString('ariaviewselectoroption', 'block_timeline', 'option');
    (globalThis as any).mockString('sortbydates', 'block_timeline', 'Sort by dates');
    (globalThis as any).mockString('sortbycourses', 'block_timeline', 'Sort by courses');
});

describe('ViewSelector', () => {
    it('shows the active order label in the toggle button', async() => {
        await renderSelector(<ViewSelector activeOrder="sortbydates" onChange={jest.fn()} />);

        await waitFor(() => {
            expect(screen.getByRole('button')).toHaveTextContent('Sort by dates');
        });
    });

    it('marks the active option with aria-current', async() => {
        const {container} = await renderSelector(<ViewSelector activeOrder="sortbycourses" onChange={jest.fn()} />);

        expect(optionFor(container, 'sortbycourses')).toHaveAttribute('aria-current', 'true');
        expect(optionFor(container, 'sortbydates')).not.toHaveAttribute('aria-current');
    });

    it('calls onChange with the clicked option\'s order name', async() => {
        const onChange = jest.fn();
        const {container} = await renderSelector(<ViewSelector activeOrder="sortbydates" onChange={onChange} />);

        fireEvent.click(optionFor(container, 'sortbycourses'));

        expect(onChange).toHaveBeenCalledWith('sortbycourses');
    });

    it('renders both sort options', async() => {
        const {container} = await renderSelector(<ViewSelector activeOrder="sortbydates" onChange={jest.fn()} />);

        expect(optionFor(container, 'sortbydates')).toHaveTextContent('Sort by dates');
        expect(optionFor(container, 'sortbycourses')).toHaveTextContent('Sort by courses');
    });

    it('marks the dropdown up as a menu, not a tablist', async() => {
        const {container} = await renderSelector(<ViewSelector activeOrder="sortbydates" onChange={jest.fn()} />);

        expect(container.querySelector('[role="menu"]')).toBeInTheDocument();
        expect(container.querySelector('[role="tablist"], [role="tab"]')).not.toBeInTheDocument();
        expect(optionFor(container, 'sortbydates')).toHaveAttribute('role', 'menuitem');
        expect(optionFor(container, 'sortbycourses')).toHaveAttribute('role', 'menuitem');
    });

    it('leaves no option referring to a panel that does not exist', async() => {
        const {container} = await renderSelector(<ViewSelector activeOrder="sortbydates" onChange={jest.fn()} />);

        for (const name of ['sortbydates', 'sortbycourses']) {
            const option = optionFor(container, name);
            expect(option).not.toHaveAttribute('aria-controls');
            expect(option).toHaveAttribute('href', '#');
        }
    });

    it('exposes the collapsed state on the toggle before the dropdown is first opened', async() => {
        await renderSelector(<ViewSelector activeOrder="sortbydates" onChange={jest.fn()} />);

        const toggle = screen.getByRole('button');
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        expect(toggle).toHaveAttribute('aria-controls', 'menusortby');
        expect(document.getElementById('menusortby')).toBeInTheDocument();
    });

    it('leaves the expanded state Bootstrap set alone when the component re-renders', async() => {
        const {rerender} = await renderSelector(<ViewSelector activeOrder="sortbydates" onChange={jest.fn()} />);

        // Stand in for Bootstrap's dropdown JS, which owns the attribute once the menu opens.
        const toggle = screen.getByRole('button');
        toggle.setAttribute('aria-expanded', 'true');

        await act(async() => {
            rerender(<ViewSelector activeOrder="sortbycourses" onChange={jest.fn()} />);
        });

        expect(toggle).toHaveAttribute('aria-expanded', 'true');
    });

    it('leads the toggle accessible name with the visible selection (WCAG 2.5.3)', async() => {
        await renderSelector(<ViewSelector activeOrder="sortbydates" onChange={jest.fn()} />);

        // Someone driving the page by voice says the words they can see, so the visible text
        // has to be in the accessible name, and lead it.
        await waitFor(() => {
            expect(screen.getByRole('button', {name: /^Sort by dates\b/})).toBeInTheDocument();
        });
    });

    it('names the toggle from a single string rather than two concatenated halves', async() => {
        await renderSelector(<ViewSelector activeOrder="sortbydates" onChange={jest.fn()} />);

        const toggle = screen.getByRole('button');
        await waitFor(() => {
            expect(toggle).toHaveAttribute('aria-label', 'Sort by dates: sort timeline items');
        });

        // The qualifier must not also sit inside the button as hidden text: that would append it
        // to the name a second time, and leave each half to be translated out of context.
        expect(toggle.textContent).toBe('Sort by dates');
    });

    it('gives the dropdown menu an accessible name', async() => {
        await renderSelector(<ViewSelector activeOrder="sortbydates" onChange={jest.fn()} />);

        await waitFor(() => {
            expect(screen.getByRole('menu', {name: 'Sort by'})).toBeInTheDocument();
        });
    });
});
