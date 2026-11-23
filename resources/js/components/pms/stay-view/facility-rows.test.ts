import { expect, test } from 'vite-plus/test';
import { buildFacilityRows, facilityCellView } from './facility-rows';

type Facility = App.Data.Facilities.FacilityTimelineRowData;
type Cell = App.Data.Facilities.FacilityDayCellData;

const hall: Facility = {
    id: 1,
    name: 'Riverside Hall',
    capacity: 1,
    single: true,
    unit: 'item',
};
const walk: Facility = {
    id: 2,
    name: 'Night Walk',
    capacity: 20,
    single: false,
    unit: 'people',
};

const cell = (over: Partial<Cell> = {}): Cell => ({
    facilityId: 1,
    date: '2026-10-20',
    booked: 1,
    full: false,
    bookings: 1,
    tentative: 0,
    label: 'WWF lunch',
    ...over,
});

test('a facility with 1 item names its booking, or counts them when there are more', () => {
    expect(facilityCellView(hall, undefined).text).toBe('');
    expect(facilityCellView(hall, cell()).text).toBe('WWF lunch');
    expect(
        facilityCellView(hall, cell({ bookings: 2, label: null })).text,
    ).toBe('2 booked');
});

test('any other facility shows the most booked at once of How many, and Full', () => {
    expect(facilityCellView(walk, undefined).text).toBe('0/20');
    expect(facilityCellView(walk, cell({ booked: 12, label: null })).text).toBe(
        '12/20',
    );

    const full = facilityCellView(
        walk,
        cell({ booked: 20, full: true, label: null }),
    );

    expect(full.text).toBe('20/20 Full');
    expect(full.full).toBe(true);
});

test('a day with only Tentative bookings is hatched; a mixed day says how many are tentative', () => {
    expect(
        facilityCellView(walk, cell({ booked: 0, bookings: 1, tentative: 1 }))
            .tentative,
    ).toBe(true);

    const mixed = facilityCellView(
        walk,
        cell({ booked: 5, bookings: 2, tentative: 1 }),
    );

    expect(mixed.tentative).toBe(false);
    expect(mixed.title).toContain('1 tentative');
});

test('the section is a heading then the facilities, and folds to the heading', () => {
    expect(buildFacilityRows([], new Set())).toEqual([]);
    expect(
        buildFacilityRows([hall, walk], new Set()).map((row) => row.key),
    ).toEqual(['group-facilities', 'facility-1', 'facility-2']);
    expect(
        buildFacilityRows([hall, walk], new Set(['facilities'])).map(
            (row) => row.key,
        ),
    ).toEqual(['group-facilities']);
});
