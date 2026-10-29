export type BusinessDateState =
    | 'in-sync'
    | 'audit-pending'
    | 'auditing'
    | 'overdue'
    | 'ahead'
    | 'not-live';

export type BusinessDateView = {
    state: BusinessDateState;
    /** The state in words. The indicator never relies on color alone. */
    message: string;
    /** The business date as `yyyy-MM-dd`, or null when the property is not live. */
    businessDay: string | null;
    /** The system date in the property time zone as `yyyy-MM-dd`, or null when the property is not live. */
    systemDay: string | null;
    /** The system time in the property time zone as `HH:mm`. */
    systemTime: string | null;
    daysBehind: number;
};

const DAY_MS = 86_400_000;

function toUtcMs(day: string): number {
    const [year, month, date] = day.split('-').map(Number);

    return Date.UTC(year, month - 1, date);
}

/**
 * The calendar date and time at `nowMs` in the property time zone.
 */
export function systemClock(
    nowMs: number,
    timeZone: string,
): { day: string; time: string } {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-CA', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        })
            .formatToParts(new Date(nowMs))
            .map((part) => [part.type, part.value]),
    );

    return {
        day: `${parts.year}-${parts.month}-${parts.day}`,
        time: `${parts.hour}:${parts.minute}`,
    };
}

/**
 * A date-only value as "Fri 12 Jun 2026" (full), "Fri 12 Jun" (short) or "12 Jun" (compact).
 */
export function formatBusinessDay(
    day: string,
    style: 'full' | 'short' | 'compact' = 'full',
): string {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-GB', {
            timeZone: 'UTC',
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        })
            .formatToParts(new Date(toUtcMs(day)))
            .map((part) => [part.type, part.value]),
    );

    if (style === 'compact') {
        return `${parts.day} ${parts.month}`;
    }

    return `${parts.weekday} ${parts.day} ${parts.month}${style === 'full' ? ` ${parts.year}` : ''}`;
}

/**
 * The sidebar state of the business date. `nowMs` is the server-corrected clock.
 * See docs/design-system/09-app-shell.md.
 */
export function deriveBusinessDateView(
    businessDate: App.Data.NightAudit.BusinessDateData | null,
    nowMs: number,
): BusinessDateView {
    if (businessDate === null) {
        return {
            state: 'not-live',
            message: 'Not live yet',
            businessDay: null,
            systemDay: null,
            systemTime: null,
            daysBehind: 0,
        };
    }

    const clock = systemClock(nowMs, businessDate.timeZone);
    const difference = Math.round(
        (toUtcMs(clock.day) - toUtcMs(businessDate.date)) / DAY_MS,
    );
    const daysBehind = Math.max(0, difference);
    const base = {
        businessDay: businessDate.date,
        systemDay: clock.day,
        systemTime: clock.time,
        daysBehind,
    };

    if (businessDate.status === 'auditing') {
        return { ...base, state: 'auditing', message: 'Night audit running' };
    }

    if (difference < 0) {
        return {
            ...base,
            state: 'ahead',
            message: 'Business date is ahead of the calendar',
        };
    }

    if (daysBehind >= 2) {
        return {
            ...base,
            state: 'overdue',
            message: `Business date is ${daysBehind} days behind`,
        };
    }

    if (daysBehind === 1) {
        return {
            ...base,
            state: 'audit-pending',
            message: 'Night audit pending',
        };
    }

    return { ...base, state: 'in-sync', message: 'In sync' };
}
