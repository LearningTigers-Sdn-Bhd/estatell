import { expect, test } from 'vite-plus/test';
import { deriveBusinessDateView } from './business-date';

const businessDate = (date: string): App.Data.NightAudit.BusinessDateData => ({
    date,
    status: 'open',
    timeZone: 'Asia/Kuala_Lumpur',
    auditTime: '00:00',
    serverNow: '2026-10-28T16:48:00Z',
});

/** 29 Oct 2026, 00:48 in Kuala Lumpur. */
const now = Date.UTC(2026, 9, 28, 16, 48);

test('a business date after the calendar date is ahead, not in sync', () => {
    expect(
        deriveBusinessDateView(businessDate('2026-10-30'), now),
    ).toMatchObject({
        state: 'ahead',
        daysBehind: 0,
    });
});

test('the business date of today is in sync and yesterday is audit pending', () => {
    expect(deriveBusinessDateView(businessDate('2026-10-29'), now).state).toBe(
        'in-sync',
    );
    expect(deriveBusinessDateView(businessDate('2026-10-28'), now).state).toBe(
        'audit-pending',
    );
});
