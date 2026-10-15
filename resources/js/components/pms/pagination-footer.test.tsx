import { createElement } from 'react';
import type { ComponentProps } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { expect, test, vi } from 'vite-plus/test';
import { PaginationFooter } from './pagination-footer';
import type { PaginationData } from './pagination-footer';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        preserveScroll: _scroll,
        preserveState: _state,
        ...props
    }: ComponentProps<'a'> & {
        preserveScroll?: boolean;
        preserveState?: boolean;
    }) => createElement('a', props),
    router: { visit: vi.fn() },
}));

const pagination: PaginationData = {
    currentPage: 2,
    lastPage: 24,
    from: 26,
    to: 50,
    total: 597,
    prevUrl:
        'https://estatell.test/reports/activity-log?sort=-created_at&page=1',
    nextUrl:
        'https://estatell.test/reports/activity-log?sort=-created_at&page=3',
};

test('renders four bordered navigation controls and the current page input', () => {
    const html = renderToStaticMarkup(
        <PaginationFooter pagination={pagination} />,
    );

    for (const label of [
        'First page',
        'Previous page',
        'Next page',
        'Last page',
    ]) {
        expect(html).toContain(`aria-label="${label}"`);
    }

    expect(html.match(/class="[^"]*border-border/g)).toHaveLength(4);
    expect(html).toContain('page=24');
    expect(html).toContain('value="2"');
    expect(html).toContain('inputMode="numeric"');
    expect(html).toContain('Showing 26–50 of 597');
});

test('disables first and previous controls on the first page', () => {
    const html = renderToStaticMarkup(
        <PaginationFooter
            pagination={{ ...pagination, currentPage: 1, prevUrl: null }}
        />,
    );

    expect(html.match(/aria-disabled="true"/g)).toHaveLength(2);
    expect(html).not.toMatch(/<a[^>]*aria-label="(?:First|Previous) page"/);
});

test('disables next and last controls on the last page', () => {
    const html = renderToStaticMarkup(
        <PaginationFooter
            pagination={{ ...pagination, currentPage: 24, nextUrl: null }}
        />,
    );

    expect(html.match(/aria-disabled="true"/g)).toHaveLength(2);
    expect(html).not.toMatch(/<a[^>]*aria-label="(?:Next|Last) page"/);
});

test('shows only the count for a single page and hides an empty footer', () => {
    const html = renderToStaticMarkup(
        <PaginationFooter
            pagination={{
                ...pagination,
                lastPage: 1,
                currentPage: 1,
                total: 10,
                from: 1,
                to: 10,
                prevUrl: null,
                nextUrl: null,
            }}
        />,
    );

    expect(html).toContain('Showing 1–10 of 10');
    expect(html).not.toContain('<input');
    expect(html).not.toContain('<a');
    expect(
        renderToStaticMarkup(
            <PaginationFooter pagination={{ ...pagination, total: 0 }} />,
        ),
    ).toBe('');
});
