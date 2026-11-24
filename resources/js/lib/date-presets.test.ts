import { describe, expect, it } from 'vite-plus/test';
import { reportDatePresets } from './date-presets';

describe('reportDatePresets', () => {
    it('uses the default presets when a report has none', () => {
        expect(reportDatePresets('2026-11-04', null)).toEqual(
            reportDatePresets('2026-11-04'),
        );
    });

    it('runs the next months from today to the end of the last whole month', () => {
        expect(
            reportDatePresets('2026-11-24', [
                'next-3-months',
                'next-6-months',
                'next-12-months',
            ]),
        ).toEqual([
            { label: 'Next 3 months', from: '2026-11-24', to: '2027-01-31' },
            { label: 'Next 6 months', from: '2026-11-24', to: '2027-04-30' },
            { label: 'Next 12 months', from: '2026-11-24', to: '2027-10-31' },
        ]);
    });
});
