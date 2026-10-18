import { renderToStaticMarkup } from 'react-dom/server';
import { expect, test } from 'vite-plus/test';
import { BookingSourceBadge, swatchClasses } from './booking-source-badge';

const source = (overrides: Record<string, unknown> = {}) =>
    ({
        name: 'Phone',
        type: 'direct',
        icon: 'phone',
        letters: null,
        color: 'gray',
        ...overrides,
    }) as App.Data.Reservations.BookingSourceBadgeData;

test('an icon source shows the icon and the name as accessible name', () => {
    const html = renderToStaticMarkup(<BookingSourceBadge source={source()} />);

    expect(html).toContain('role="img"');
    expect(html).toContain('aria-label="Phone"');
    expect(html).toContain('<svg');
});

test('a letters source shows the letters and no icon', () => {
    const html = renderToStaticMarkup(
        <BookingSourceBadge
            source={source({
                name: 'Booking.com',
                icon: null,
                letters: 'B',
                color: 'blue',
            })}
        />,
    );

    expect(html).toContain('aria-label="Booking.com"');
    expect(html).toContain('>B<');
    expect(html).not.toContain('<svg');
    expect(html).toContain('bg-swatch-blue text-swatch-blue-foreground');
});

test('every swatch color has its own pair of classes', () => {
    for (const [color, classes] of Object.entries(swatchClasses)) {
        expect(classes).toBe(
            `bg-swatch-${color} text-swatch-${color}-foreground`,
        );
    }
});

test('the sm size is smaller than the default size', () => {
    expect(
        renderToStaticMarkup(
            <BookingSourceBadge source={source()} size="sm" />,
        ),
    ).toContain('size-5');
    expect(
        renderToStaticMarkup(<BookingSourceBadge source={source()} />),
    ).toContain('size-6');
});
