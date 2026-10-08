export type DateRangePreset = {
    label: string;
    /** First date, `Y-m-d`. */
    from: string;
    /** Last date, `Y-m-d`. Both dates are included. */
    to: string;
};

export type DatePreset = {
    label: string;
    /** The date, `Y-m-d`. */
    date: string;
};

const DAY_MS = 86_400_000;

function parse(day: string): Date {
    const [year, month, date] = day.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, date));
}

function format(date: Date): string {
    return date.toISOString().slice(0, 10);
}

function addDays(date: Date, days: number): Date {
    return new Date(date.getTime() + days * DAY_MS);
}

/** Monday of the week that holds the date. */
function weekStart(date: Date): Date {
    return addDays(date, -((date.getUTCDay() + 6) % 7));
}

/** The quick dates a range report offers when its page sends none, in the order shown. */
export const defaultReportPresets: App.Enums.ReportDatePreset[] = [
    'today',
    'yesterday',
    'this-week',
    'last-week',
    'this-month',
    'last-month',
    'this-year',
    'last-30-days',
];

/**
 * The quick dates of a report, counted from the business date and not from the clock.
 * Weeks run Monday to Sunday and months and years run in full. `keys` picks the presets and their order; a report
 * sends its own list (`ReportKey::datePresets()`), and without one the standard list shows.
 */
export function reportDatePresets(
    businessDate: string,
    keys: App.Enums.ReportDatePreset[] = defaultReportPresets,
): DateRangePreset[] {
    const today = parse(businessDate);
    const year = today.getUTCFullYear();
    const month = today.getUTCMonth();
    const thisWeek = weekStart(today);
    const day = (offset: number) => {
        const date = format(addDays(today, offset));

        return { from: date, to: date };
    };
    const week = (start: Date) => ({
        from: format(start),
        to: format(addDays(start, 6)),
    });

    const ranges: Record<
        App.Enums.ReportDatePreset,
        { label: string; from: string; to: string }
    > = {
        today: { label: 'Today', ...day(0) },
        yesterday: { label: 'Yesterday', ...day(-1) },
        tomorrow: { label: 'Tomorrow', ...day(1) },
        'next-7-days': {
            label: 'Next 7 days',
            from: format(today),
            to: format(addDays(today, 6)),
        },
        'this-week': { label: 'This week', ...week(thisWeek) },
        'last-week': { label: 'Last week', ...week(addDays(thisWeek, -7)) },
        'next-week': { label: 'Next week', ...week(addDays(thisWeek, 7)) },
        'this-month': {
            label: 'This month',
            from: format(new Date(Date.UTC(year, month, 1))),
            to: format(new Date(Date.UTC(year, month + 1, 0))),
        },
        'last-month': {
            label: 'Last month',
            from: format(new Date(Date.UTC(year, month - 1, 1))),
            to: format(new Date(Date.UTC(year, month, 0))),
        },
        'this-year': {
            label: 'This year',
            from: format(new Date(Date.UTC(year, 0, 1))),
            to: format(new Date(Date.UTC(year, 11, 31))),
        },
        'last-30-days': {
            label: 'Last 30 days',
            from: format(addDays(today, -29)),
            to: format(today),
        },
    };

    return keys.map((key) => ranges[key]);
}

/**
 * The quick dates of a report of one date, counted from the business date and not from the clock.
 */
export function reportDatePresetsForOneDate(
    businessDate: string,
): DatePreset[] {
    const today = parse(businessDate);

    return [
        { label: 'Today', date: format(today) },
        { label: 'Yesterday', date: format(addDays(today, -1)) },
    ];
}

/** The number of days in a preset, counting both ends. */
export function presetDays(preset: DateRangePreset): number {
    return (
        Math.round(
            (parse(preset.to).getTime() - parse(preset.from).getTime()) /
                DAY_MS,
        ) + 1
    );
}
