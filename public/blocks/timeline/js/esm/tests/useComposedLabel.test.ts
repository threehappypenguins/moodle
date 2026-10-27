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
 * Jest tests for the useComposedLabel hook.
 *
 * @module     block_timeline/tests/useComposedLabel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {renderHook, waitFor} from '@testing-library/react';
import * as stringUtils from '@moodle/lms/core/stringUtils';
import {useComposedLabel} from '../src/common/useComposedLabel';

describe('useComposedLabel', () => {
    jest.spyOn(stringUtils, 'getString');

    it('substitutes the active option label into the name string', async() => {
        (globalThis as any).mockString('sortbydates', 'block_timeline', 'Sort by dates');
        (globalThis as any).mockString(
            'ariaviewselectorbutton', 'block_timeline', 'Sort by dates: sort timeline items'
        );

        const {result} = renderHook(() => useComposedLabel('ariaviewselectorbutton', 'sortbydates'));

        // The mocked getString ignores the `param` value, so assert on the wiring (that the name
        // is asked for with the option's label as {$a}) rather than on the substituted output.
        // Handing the whole phrase to one string is the point: it is what gives a translator the
        // context and the word order.
        await waitFor(() => expect(result.current).toBe('Sort by dates: sort timeline items'));
        expect(stringUtils.getString).toHaveBeenCalledWith(
            'ariaviewselectorbutton', 'block_timeline', 'Sort by dates'
        );
    });

    it('reads the active option label from its own component when given one', async() => {
        (globalThis as any).mockString('all', 'core', 'All');
        (globalThis as any).mockString('ariadayfilterbutton', 'block_timeline', 'All: filter timeline by date');

        renderHook(() => useComposedLabel('ariadayfilterbutton', 'all', 'core'));

        await waitFor(() => expect(stringUtils.getString).toHaveBeenCalledWith('all', 'core'));
    });

    it('recomposes the name when the selection changes', async() => {
        (globalThis as any).mockString('sortbydates', 'block_timeline', 'Sort by dates');
        (globalThis as any).mockString('sortbycourses', 'block_timeline', 'Sort by courses');
        (globalThis as any).mockString('ariaviewselectorbutton', 'block_timeline', 'Composed name');

        const {rerender} = renderHook(
            ({labelKey}) => useComposedLabel('ariaviewselectorbutton', labelKey),
            {initialProps: {labelKey: 'sortbydates'}}
        );
        await waitFor(() => expect(stringUtils.getString).toHaveBeenCalledWith('sortbydates', 'block_timeline'));

        (stringUtils.getString as jest.Mock).mockClear();
        rerender({labelKey: 'sortbycourses'});

        await waitFor(() => expect(stringUtils.getString).toHaveBeenCalledWith('sortbycourses', 'block_timeline'));
    });
});
