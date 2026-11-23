import { expect, test } from 'vite-plus/test';
import { forStepReady } from './timeline-book-facility-step';

test('Next needs a picked reservation, or a picked event', () => {
    expect(forStepReady('reservation', null, '')).toBe(false);
    expect(forStepReady('reservation', 12, '')).toBe(true);
    expect(forStepReady('event', 12, '')).toBe(false);
    expect(forStepReady('event', null, '7')).toBe(true);
});
