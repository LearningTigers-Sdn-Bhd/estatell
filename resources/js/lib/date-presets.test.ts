import { describe, expect, it } from 'vite-plus/test';
import { reportDatePresets } from './date-presets';

describe('reportDatePresets', () => {
    it('uses the default presets when a report has none', () => {
        expect(reportDatePresets('2026-11-04', null)).toEqual(
            reportDatePresets('2026-11-04'),
        );
    });
});
