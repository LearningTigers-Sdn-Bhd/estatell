const parseDay = (date: string) => {
    const [year, month, day = 1] = date.split('-').map(Number);

    return new Date(year, month - 1, day);
};

/** `2026-10-18` as "18 Oct 2026". */
export function longDate(date: string): string {
    return parseDay(date).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/** `2026-10-18` as "18 Oct". */
export function shortDate(date: string): string {
    return parseDay(date).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}

/** `2026-10` as "Oct 2026". */
export function monthLabel(month: string): string {
    return parseDay(month).toLocaleDateString('en-GB', {
        month: 'short',
        year: 'numeric',
    });
}

/** "1 Oct – 18 Oct 2026". The year shows once when both ends share it. */
export function rangeLabel(from: string, to: string): string {
    const sameYear = from.slice(0, 4) === to.slice(0, 4);

    return `${sameYear ? shortDate(from) : longDate(from)} – ${longDate(to)}`;
}

/** The period as words, for the line under the presets. */
export function periodSentence(
    period: App.Data.Dashboard.PerformancePeriodData,
): string {
    return `${rangeLabel(period.from, period.to)} · compared with ${rangeLabel(period.previousFrom, period.previousTo)}`;
}

/** A decimal string as a whole number, rounded half away from zero. */
export function whole(value: string): number {
    return Math.round(Number(value));
}

export type Change = { direction: 'up' | 'down' | 'same'; text: string };

/** The arrow text of a change, for example "▲ 4 pt". Display only: the server works the figure out. */
export function describeChange(
    change: string | null,
    unit: 'pt' | '%' | 'days',
): Change | null {
    if (change === null) {
        return null;
    }

    const amount = Math.abs(whole(change));

    if (amount === 0) {
        return { direction: 'same', text: 'No change' };
    }

    const suffix =
        unit === 'days' ? (amount === 1 ? ' day' : ' days') : ` ${unit}`;
    const text = unit === '%' ? `${amount}%` : `${amount}${suffix}`;

    return Number(change) > 0
        ? { direction: 'up', text: `▲ ${text}` }
        : { direction: 'down', text: `▼ ${text}` };
}
