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

/**
 * The quick dates of a report, counted from the business date and not from the clock.
 * Weeks run Monday to Sunday and months and years run in full.
 */
export function reportDatePresets(businessDate: string): DateRangePreset[] {
    const today = parse(businessDate);
    const year = today.getUTCFullYear();
    const month = today.getUTCMonth();
    const thisWeek = weekStart(today);
    const lastWeek = addDays(thisWeek, -7);

    return [
        { label: 'Today', from: format(today), to: format(today) },
        {
            label: 'Yesterday',
            from: format(addDays(today, -1)),
            to: format(addDays(today, -1)),
        },
        {
            label: 'This week',
            from: format(thisWeek),
            to: format(addDays(thisWeek, 6)),
        },
        {
            label: 'Last week',
            from: format(lastWeek),
            to: format(addDays(lastWeek, 6)),
        },
        {
            label: 'This month',
            from: format(new Date(Date.UTC(year, month, 1))),
            to: format(new Date(Date.UTC(year, month + 1, 0))),
        },
        {
            label: 'Last month',
            from: format(new Date(Date.UTC(year, month - 1, 1))),
            to: format(new Date(Date.UTC(year, month, 0))),
        },
        {
            label: 'This year',
            from: format(new Date(Date.UTC(year, 0, 1))),
            to: format(new Date(Date.UTC(year, 11, 31))),
        },
        {
            label: 'Last 30 days',
            from: format(addDays(today, -29)),
            to: format(today),
        },
    ];
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
