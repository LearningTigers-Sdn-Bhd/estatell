import { expect, test } from 'vite-plus/test';
import {
    emptyRestrictionChoices,
    hasRestrictionChanges,
    restrictionsProblem,
    restrictionsToPayload,
} from './restriction-choices';

test('starts with nothing to change', () => {
    const choices = emptyRestrictionChoices();

    expect(hasRestrictionChanges(choices)).toBe(false);
    expect(restrictionsToPayload(choices)).toEqual({});
    expect(restrictionsProblem(choices)).toBeNull();
});

test('sends only the restrictions set to change', () => {
    expect(
        restrictionsToPayload({
            ...emptyRestrictionChoices(),
            stopSell: 'on',
            closedToDeparture: 'off',
        }),
    ).toEqual({ stop_sell: true, closed_to_departure: false });
});

test('sets and removes stay limits', () => {
    const payload = restrictionsToPayload({
        ...emptyRestrictionChoices(),
        minStay: { mode: 'set', value: ' 3 ' },
        maxStay: { mode: 'clear', value: '9' },
    });

    expect(payload).toEqual({ min_stay: 3, max_stay: null });
});

test('any change counts, including removing a limit', () => {
    expect(
        hasRestrictionChanges({
            ...emptyRestrictionChoices(),
            maxStay: { mode: 'clear', value: '' },
        }),
    ).toBe(true);
    expect(
        hasRestrictionChanges({
            ...emptyRestrictionChoices(),
            closedToArrival: 'on',
        }),
    ).toBe(true);
});

test('catches a stay limit with no number and a max below the min', () => {
    expect(
        restrictionsProblem({
            ...emptyRestrictionChoices(),
            minStay: { mode: 'set', value: '' },
        }),
    ).toContain('min stay');
    expect(
        restrictionsProblem({
            ...emptyRestrictionChoices(),
            maxStay: { mode: 'set', value: '0' },
        }),
    ).toContain('max stay');
    expect(
        restrictionsProblem({
            ...emptyRestrictionChoices(),
            minStay: { mode: 'set', value: '5' },
            maxStay: { mode: 'set', value: '2' },
        }),
    ).toBe('The max stay cannot be below the min stay.');
    expect(
        restrictionsProblem({
            ...emptyRestrictionChoices(),
            minStay: { mode: 'set', value: '2' },
            maxStay: { mode: 'clear', value: '' },
        }),
    ).toBeNull();
});
