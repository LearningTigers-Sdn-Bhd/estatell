import { expect, test } from 'vite-plus/test';
import { depositHoursHint } from './deposit-hours';

test('typed hours convert to days and leftover hours', () => {
    expect(depositHoursHint('72', 48)).toBe('3 days after booking.');
    expect(depositHoursHint('24', 48)).toBe('1 day after booking.');
    expect(depositHoursHint('36', 48)).toBe('1 day 12 hours after booking.');
    expect(depositHoursHint('25', 48)).toBe('1 day 1 hour after booking.');
    expect(depositHoursHint('12', 48)).toBe('12 hours after booking.');
    expect(depositHoursHint('1', 48)).toBe('1 hour after booking.');
});

test('an empty field shows the property default', () => {
    expect(depositHoursHint('', 72)).toBe(
        'Property default: 3 days after booking.',
    );
    expect(depositHoursHint('', null)).toBe(
        'Leave empty to follow the property default.',
    );
});

test('a value outside the accepted hours asks for a valid one', () => {
    for (const value of ['0', '-5', '1.5', '8761']) {
        expect(depositHoursHint(value, 72)).toBe(
            'Enter whole hours, from 1 to 8760.',
        );
    }
});
