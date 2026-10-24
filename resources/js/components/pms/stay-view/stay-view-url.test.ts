import { expect, test } from 'vite-plus/test';
import {
    queryString,
    shiftWindow,
    viewQuery,
    windowContains,
    windowFromToday,
    windowLength,
} from './stay-view-url';

const none: App.Data.StayView.StayViewFiltersData = {
    roomGroupIds: [],
    roomTypeIds: [],
    statuses: [],
    search: '',
    groupBy: 'room_group',
};

test('counts both ends of the window', () => {
    expect(windowLength({ from: '2026-10-14', to: '2026-10-27' })).toBe(14);
    expect(windowLength({ from: '2026-10-31', to: '2026-11-02' })).toBe(3);
});

test('shifts by its own length, across a month end', () => {
    const window = { from: '2026-10-14', to: '2026-10-27' };

    expect(shiftWindow(window, 1)).toEqual({
        from: '2026-10-28',
        to: '2026-11-10',
    });
    expect(shiftWindow(window, -1)).toEqual({
        from: '2026-09-30',
        to: '2026-10-13',
    });
});

test('starts a window of the same length today', () => {
    expect(
        windowFromToday('2026-11-01', { from: '2026-10-14', to: '2026-10-27' }),
    ).toEqual({ from: '2026-11-01', to: '2026-11-14' });
    expect(
        windowContains({ from: '2026-10-14', to: '2026-10-27' }, '2026-10-27'),
    ).toBe(true);
    expect(
        windowContains({ from: '2026-10-14', to: '2026-10-27' }, '2026-10-28'),
    ).toBe(false);
});

test('leaves out empty filters and the default grouping', () => {
    expect(viewQuery({ from: '2026-10-14', to: '2026-10-27' }, none)).toEqual({
        from: '2026-10-14',
        to: '2026-10-27',
    });
});

test('writes filters, search and grouping to the query', () => {
    const query = viewQuery(
        { from: '2026-10-14', to: '2026-10-27' },
        {
            roomGroupIds: [1, 3],
            roomTypeIds: [10],
            statuses: ['dirty'],
            search: ' Ahmad ',
            groupBy: 'none',
        },
    );

    expect(query).toEqual({
        from: '2026-10-14',
        to: '2026-10-27',
        'groups[]': ['1', '3'],
        'types[]': ['10'],
        'statuses[]': ['dirty'],
        q: 'Ahmad',
        group_by: 'none',
    });
    expect(queryString(query)).toBe(
        '?from=2026-10-14&to=2026-10-27&groups%5B%5D=1&groups%5B%5D=3&types%5B%5D=10&statuses%5B%5D=dirty&q=Ahmad&group_by=none',
    );
    expect(queryString({})).toBe('');
});
