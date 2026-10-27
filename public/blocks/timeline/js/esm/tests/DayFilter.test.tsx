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
 * Jest tests for the DayFilter dropdown.
 *
 * @module     block_timeline/tests/DayFilter
 * @copyright  Meirza Arson <meirza.arson@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {act, render, screen, waitFor, fireEvent} from '@testing-library/react';
import DayFilter from '../src/nav/DayFilter';

async function renderFilter(...args: Parameters<typeof render>) {
    let result!: ReturnType<typeof render>;
    await act(async() => {
        result = render(...args);
    });
    return result;
}

beforeEach(() => {
    (globalThis as any).mockString('ariadayfilter', 'block_timeline', 'Filter by date due');
    (globalThis as any).mockString('ariadayfilterbutton', 'block_timeline', 'All: filter timeline by date');
    (globalThis as any).mockString('ariadayfilteroption', 'block_timeline', 'option');
    (globalThis as any).mockString('all', 'core', 'All');
    (globalThis as any).mockString('overdue', 'block_timeline', 'Overdue');
    (globalThis as any).mockString('next7days', 'block_timeline', 'Next 7 days');
    (globalThis as any).mockString('next30days', 'block_timeline', 'Next 30 days');
    (globalThis as any).mockString('next3months', 'block_timeline', 'Next 3 months');
    (globalThis as any).mockString('next6months', 'block_timeline', 'Next 6 months');
    (globalThis as any).mockString('duedate', 'block_timeline', 'Due date');
});

// Every option shares the same mocked aria-label ("option"), which overrides its
// accessible name for role queries — so items are located by data-filtername instead.
function optionFor(container: HTMLElement, filtername: string): HTMLElement {
    const el = container.querySelector(`[data-filtername="${filtername}"]`);
    if (!el) {
        throw new Error(`No option found for filtername "${filtername}"`);
    }
    return el as HTMLElement;
}

describe('DayFilter', () => {
    it('shows the active filter label in the toggle button', async() => {
        await renderFilter(<DayFilter activeFilter="overdue" onChange={jest.fn()} />);

        await waitFor(() => {
            expect(screen.getByRole('button')).toHaveTextContent('Overdue');
        });
    });

    it('marks the active option with aria-current and a highlighted class', async() => {
        const {container} = await renderFilter(<DayFilter activeFilter="next7days" onChange={jest.fn()} />);

        const active = optionFor(container, 'next7days');
        expect(active).toHaveAttribute('aria-current', 'true');
        expect(active.className).toContain('active');
    });

    it('does not mark inactive options as current', async() => {
        const {container} = await renderFilter(<DayFilter activeFilter="next7days" onChange={jest.fn()} />);

        expect(optionFor(container, 'next30days')).not.toHaveAttribute('aria-current');
    });

    it('calls onChange with the clicked option\'s filter name', async() => {
        const onChange = jest.fn();
        const {container} = await renderFilter(<DayFilter activeFilter="all" onChange={onChange} />);

        fireEvent.click(optionFor(container, 'overdue'));

        expect(onChange).toHaveBeenCalledWith('overdue');
    });

    it('exposes the collapsed state on the toggle before the dropdown is first opened', async() => {
        await renderFilter(<DayFilter activeFilter="all" onChange={jest.fn()} />);

        const toggle = screen.getByRole('button');
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        expect(toggle).toHaveAttribute('aria-controls', 'menudayfilter');
    });

    it('leaves the expanded state Bootstrap set alone when the component re-renders', async() => {
        const {rerender} = await renderFilter(<DayFilter activeFilter="all" onChange={jest.fn()} />);

        // Stand in for Bootstrap's dropdown JS, which owns the attribute once the menu opens.
        const toggle = screen.getByRole('button');
        toggle.setAttribute('aria-expanded', 'true');

        await act(async() => {
            rerender(<DayFilter activeFilter="overdue" onChange={jest.fn()} />);
        });

        expect(toggle).toHaveAttribute('aria-expanded', 'true');
    });

    it('renders all top-level and grouped date-range options', async() => {
        const {container} = await renderFilter(<DayFilter activeFilter="all" onChange={jest.fn()} />);

        expect(optionFor(container, 'all')).toHaveTextContent('All');
        expect(optionFor(container, 'overdue')).toHaveTextContent('Overdue');
        expect(optionFor(container, 'next7days')).toHaveTextContent('Next 7 days');
        expect(optionFor(container, 'next30days')).toHaveTextContent('Next 30 days');
        expect(optionFor(container, 'next3months')).toHaveTextContent('Next 3 months');
        expect(optionFor(container, 'next6months')).toHaveTextContent('Next 6 months');
    });

    it('leads the toggle accessible name with the visible selection (WCAG 2.5.3)', async() => {
        await renderFilter(<DayFilter activeFilter="all" onChange={jest.fn()} />);

        // Someone driving the page by voice says the words they can see, so the visible text
        // has to be in the accessible name, and lead it.
        await waitFor(() => {
            expect(screen.getByRole('button', {name: /^All\b/})).toBeInTheDocument();
        });
    });

    it('names the toggle from a single string rather than two concatenated halves', async() => {
        await renderFilter(<DayFilter activeFilter="all" onChange={jest.fn()} />);

        const toggle = screen.getByRole('button');
        await waitFor(() => {
            expect(toggle).toHaveAttribute('aria-label', 'All: filter timeline by date');
        });

        // The qualifier must not also sit inside the button as hidden text: that would append it
        // to the name a second time, and leave each half to be translated out of context.
        expect(toggle.textContent).toBe('All');
    });

    it('gives the dropdown menu an accessible name', async() => {
        await renderFilter(<DayFilter activeFilter="all" onChange={jest.fn()} />);

        await waitFor(() => {
            expect(screen.getByRole('menu', {name: 'Filter by date due'})).toBeInTheDocument();
        });
    });
});
