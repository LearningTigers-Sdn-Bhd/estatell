/** A date-only value (`2026-10-12`) as "12 Oct". Parsed as a local date so it never shifts a day. */
export function shortDate(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}

/** The label of the Move button, from the counts of ticked charges and chosen rooms. */
export function moveButtonLabel(charges: number, schedules: number): string {
    const chargeText = charges === 1 ? '1 charge' : `${charges} charges`;
    const scheduleText =
        schedules === 1 ? '1 schedule' : `${schedules} schedules`;

    if (charges > 0 && schedules > 0) {
        return `Move ${chargeText} and ${scheduleText}`;
    }

    if (charges > 0) {
        return `Move ${chargeText}`;
    }

    if (schedules > 0) {
        return `Move ${scheduleText}`;
    }

    return 'Move charges';
}
