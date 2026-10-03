import { expect, test } from 'vite-plus/test';
import { errorTabs, firstErrorTab } from './booking-tabs';

test('each error goes to the tab it is shown on', () => {
    expect(
        errorTabs({
            'lines.0.room_id': 'x',
            'guests.1.full_name': 'x',
            'lines.0.discount_id': 'x',
            'lines.2.add_ons': 'x',
            notes: 'x',
        }),
    ).toEqual({ rooms: true, discounts: true, 'add-ons': true, notes: true });
});

test('errors outside the tabs do not mark one', () => {
    const tabs = errorTabs({ arrival_date: 'x', form: 'x', source: 'x' });

    expect(tabs).toEqual({
        rooms: false,
        discounts: false,
        'add-ons': false,
        notes: false,
    });
    expect(firstErrorTab(tabs)).toBeNull();
});

test('the first tab with an error opens, left to right', () => {
    expect(
        firstErrorTab(errorTabs({ notes: 'x', 'lines.0.add_ons': 'x' })),
    ).toBe('add-ons');
    expect(
        firstErrorTab(errorTabs({ notes: 'x', 'lines.0.adults': 'x' })),
    ).toBe('rooms');
});
