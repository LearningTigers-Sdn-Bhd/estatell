import { ArrowRight } from 'lucide-react';

type Field = {
    field: string;
    label: string;
    group: string;
    value: string | null;
};

/** "04 Oct 2026, 15:00" into the date, the weekday and the time. The weekday is left out when the date does not parse. */
function splitDateTime(value: string, locale: string) {
    const [date, time] = value.split(', ');
    const parsed = new Date(`${date} 12:00`);
    let weekday = '';

    if (!Number.isNaN(parsed.getTime())) {
        try {
            weekday = new Intl.DateTimeFormat(locale, {
                weekday: 'short',
            }).format(parsed);
        } catch {
            weekday = '';
        }
    }

    return { date: weekday ? `${weekday}, ${date}` : date, time: time ?? '' };
}

function DateBlock({
    label,
    value,
    locale,
    align = 'left',
}: {
    label: string;
    value: string;
    locale: string;
    align?: 'left' | 'right';
}) {
    const { date, time } = splitDateTime(value, locale);

    return (
        <div
            className={
                align === 'right'
                    ? 'flex flex-col gap-0.5 text-right'
                    : 'flex flex-col gap-0.5'
            }
        >
            <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
            <span className="font-heading text-base font-semibold tabular-nums">
                {date}
            </span>
            <span className="text-sm text-muted-foreground tabular-nums">
                {time}
            </span>
        </div>
    );
}

/**
 * The stay at the top of the guest screen: the two dates large with the nights between,
 * the other visible stay fields in an even grid and the prices in their own row.
 */
export function StaySummary({
    fields,
    title,
    locale,
}: {
    fields: Field[];
    title: string;
    locale: string;
}) {
    const byKey = Object.fromEntries(
        fields.map((field) => [field.field, field]),
    );
    const checkIn = byKey.check_in;
    const checkOut = byKey.check_out;
    const nights = byKey.nights;
    const booking = byKey.booking_number;
    const details = fields.filter(
        (field) =>
            field.group === 'stay' &&
            !['booking_number', 'check_in', 'check_out', 'nights'].includes(
                field.field,
            ),
    );
    const prices = fields.filter((field) => field.group === 'prices');

    return (
        <section className="flex flex-col gap-4 rounded-xl bg-muted/30 p-4 ring-1 ring-foreground/10">
            <div className="flex items-baseline justify-between gap-2">
                <h2 className="font-heading text-base font-semibold">
                    {title}
                </h2>
                {booking && (
                    <span className="text-xs text-muted-foreground tabular-nums">
                        {booking.value}
                    </span>
                )}
            </div>
            {(checkIn || checkOut) && (
                <div className="grid grid-cols-[auto_1fr_auto] items-center gap-4">
                    {checkIn ? (
                        <DateBlock
                            label={checkIn.label}
                            value={checkIn.value ?? ''}
                            locale={locale}
                        />
                    ) : (
                        <span />
                    )}
                    <div className="flex flex-col items-center gap-1 text-xs text-muted-foreground">
                        {nights && (
                            <span className="tabular-nums">
                                {nights.value} {nights.label.toLowerCase()}
                            </span>
                        )}
                        <div className="flex w-full items-center" aria-hidden>
                            <span className="h-px flex-1 bg-border" />
                            <ArrowRight className="size-4 shrink-0" />
                        </div>
                    </div>
                    {checkOut ? (
                        <DateBlock
                            label={checkOut.label}
                            value={checkOut.value ?? ''}
                            locale={locale}
                            align="right"
                        />
                    ) : (
                        <span />
                    )}
                </div>
            )}
            {details.length > 0 && (
                <dl className="grid grid-cols-2 gap-4 border-t pt-4 text-sm sm:grid-cols-4">
                    {details.map((field) => (
                        <div
                            key={field.field}
                            className="flex flex-col gap-0.5"
                        >
                            <dt className="text-xs text-muted-foreground">
                                {field.label}
                            </dt>
                            <dd className="font-medium">{field.value}</dd>
                        </div>
                    ))}
                </dl>
            )}
            {prices.length > 0 && (
                <dl className="flex flex-wrap justify-end gap-x-6 gap-y-1 border-t pt-4 text-sm">
                    {prices.map((field) => (
                        <div key={field.field} className="flex gap-1.5">
                            <dt className="text-muted-foreground">
                                {field.label}
                            </dt>
                            <dd className="font-medium tabular-nums">
                                {field.value}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
        </section>
    );
}
