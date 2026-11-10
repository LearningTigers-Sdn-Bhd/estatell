/**
 * A date-only value (`2026-09-12`) as "12 Sep 2026", for places that need text, such as a select label. Parsed as a
 * local date so it never shifts a day.
 */
export function formatDate(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

/**
 * A date-only value (`2026-09-12`) shown as "12 Sep 2026". Parsed as a local date so it never shifts a day.
 */
export function DateText({ date }: { date: string | null }) {
    if (!date) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <time dateTime={date} className="tabular-nums">
            {formatDate(date)}
        </time>
    );
}
