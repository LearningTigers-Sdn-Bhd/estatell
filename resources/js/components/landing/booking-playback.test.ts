import { expect, test } from 'vite-plus/test';
import { bookingPlaybackDelay } from './booking-playback';

test('playback waits at least eight seconds, and a minute after a manual pick', () => {
    expect(bookingPlaybackDelay(1_000, 0, true)).toBe(8_000);
    expect(bookingPlaybackDelay(1_000, 61_000, true)).toBe(60_000);
    expect(bookingPlaybackDelay(60_999, 61_000, true)).toBe(8_000);
    expect(bookingPlaybackDelay(70_000, 61_000, true)).toBe(8_000);
});

test('playback does not schedule while it cannot play', () => {
    expect(bookingPlaybackDelay(1_000, 61_000, false)).toBeNull();
    expect(bookingPlaybackDelay(70_000, 0, false)).toBeNull();
});
