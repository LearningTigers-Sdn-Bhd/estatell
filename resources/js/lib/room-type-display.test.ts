import { expect, test } from 'vite-plus/test';
import { roomTypeColorClass, roomTypeShortLabel } from './room-type-display';

test('a room type takes the category colour of its place', () => {
    expect(roomTypeColorClass(0)).toBe(
        'bg-category-1 text-category-1-foreground',
    );
    expect(roomTypeColorClass(11)).toBe(
        'bg-category-12 text-category-12-foreground',
    );
});

test('the 13th room type starts the colours again', () => {
    expect(roomTypeColorClass(12)).toBe(roomTypeColorClass(0));
    expect(roomTypeColorClass(25)).toBe(roomTypeColorClass(1));
});

test('the short label takes the first letter of each word without Room', () => {
    expect(roomTypeShortLabel('Standard Queen Room')).toBe('SQ');
    expect(roomTypeShortLabel('Standard Double Single Room')).toBe('SDS');
    expect(roomTypeShortLabel('Triple-Sharing Queen + Single Room')).toBe(
        'TSQ+S',
    );
    expect(roomTypeShortLabel('6-Bed Mixed Dorm')).toBe('6BMD');
    expect(roomTypeShortLabel('deluxe king room')).toBe('DK');
});

test('a name that is only Room keeps its first letter', () => {
    expect(roomTypeShortLabel('Room')).toBe('R');
});
