import { expect, test } from 'vite-plus/test';
import {
    businessDateMovedOnPage,
    businessDatePollInterval,
    deriveBusinessDateView,
} from './business-date';

const businessDate = (date: string): App.Data.NightAudit.BusinessDateData => ({
    date,
    status: 'open',
    timeZone: 'Asia/Kuala_Lumpur',
    auditTime: '00:00',
    serverNow: '2026-10-28T16:48:00Z',
});

/** 29 Oct 2026, 00:48 in Kuala Lumpur. */
const now = Date.UTC(2026, 9, 28, 16, 48);

test('a running audit is checked more often than a due one, and a settled date is not checked', () => {
    expect(businessDatePollInterval('auditing')).toBe(5_000);
    expect(businessDatePollInterval('audit-pending')).toBe(15_000);
    expect(businessDatePollInterval('overdue')).toBe(15_000);
    expect(businessDatePollInterval('in-sync')).toBeNull();
    expect(businessDatePollInterval('ahead')).toBeNull();
    expect(businessDatePollInterval('not-live')).toBeNull();
});

test('the page data is out of date only when the date moves on the same page', () => {
    const page = '/p/ES1105/dashboard';

    expect(
        businessDateMovedOnPage(
            { url: page, date: '2026-10-22' },
            { url: page, date: '2026-10-23' },
        ),
    ).toBe(true);
    expect(
        businessDateMovedOnPage(
            { url: page, date: '2026-10-22' },
            { url: page, date: '2026-10-22' },
        ),
    ).toBe(false);
    expect(
        businessDateMovedOnPage(
            { url: page, date: '2026-10-22' },
            { url: '/p/ES1103/dashboard', date: '2026-11-04' },
        ),
    ).toBe(false);
    expect(
        businessDateMovedOnPage(
            { url: page, date: null },
            { url: page, date: '2026-10-23' },
        ),
    ).toBe(false);
});

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
