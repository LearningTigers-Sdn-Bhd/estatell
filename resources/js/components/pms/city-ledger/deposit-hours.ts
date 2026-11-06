const MAX_HOURS = 8760;

/**
 * Hours as days and hours: 72 is "3 days", 36 is "1 day 12 hours", 12 is "12 hours".
 */
function hoursAsDays(hours: number): string {
    const days = Math.floor(hours / 24);
    const rest = hours % 24;
    const parts = [
        days > 0 ? `${days} ${days === 1 ? 'day' : 'days'}` : '',
        rest > 0 ? `${rest} ${rest === 1 ? 'hour' : 'hours'}` : '',
    ];

    return parts.filter(Boolean).join(' ');
}

/**
 * The note under Deposit due within (hours): what the typed hours come to or, while empty, the property's. Display only;
 * the server validates the value.
 */
export function depositHoursHint(
    entered: string,
    propertyDefault: number | null,
): string {
    const typed = entered.trim();

    if (typed === '') {
        return propertyDefault === null
            ? 'Leave empty to follow the property default.'
            : `Property default: ${hoursAsDays(propertyDefault)} after booking.`;
    }

    const hours = Number(typed);

    if (!Number.isInteger(hours) || hours < 1 || hours > MAX_HOURS) {
        return `Enter whole hours, from 1 to ${MAX_HOURS}.`;
    }

    return `${hoursAsDays(hours)} after booking.`;
}
