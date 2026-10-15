import { expect, test } from 'vite-plus/test';
import { paginationPage, paginationUrl } from './pagination-navigation';

test('accepts whole page numbers within the available range', () => {
    expect(paginationPage('1', 24)).toBe(1);
    expect(paginationPage('12', 24)).toBe(12);
    expect(paginationPage('24', 24)).toBe(24);
});

test('rejects empty, fractional, signed, nonnumeric and out-of-range pages', () => {
    for (const value of [
        '',
        ' ',
        '0',
        '25',
        '-1',
        '1.5',
        '2e1',
        '+2',
        'abc',
        '9007199254740992',
    ]) {
        expect(paginationPage(value, 24)).toBeNull();
    }
});

test('changes only the page while preserving the server URL and filters', () => {
    const base =
        'https://estatell.test/p/ES101/reports/activity-log?filter%5Bsearch%5D=checked+in&sort=-created_at&page=2#entries';
    const first = new URL(paginationUrl(base, 1));
    const last = new URL(paginationUrl(base, 24));

    expect(first.pathname).toBe('/p/ES101/reports/activity-log');
    expect(first.searchParams.get('page')).toBe('1');
    expect(last.searchParams.get('page')).toBe('24');
    expect(last.searchParams.get('filter[search]')).toBe('checked in');
    expect(last.searchParams.get('sort')).toBe('-created_at');
    expect(last.hash).toBe('#entries');
});
