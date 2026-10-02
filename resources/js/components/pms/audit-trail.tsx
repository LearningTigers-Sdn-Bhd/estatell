export type AuditTrailEntry = {
    id: number;
    text: string;
    reason: string | null;
    causerName: string | null;
    createdAt: string;
};

/**
 * Activity log entries, newest first: what happened, an optional reason, who did it and when.
 */
export function AuditTrail({ entries }: { entries: AuditTrailEntry[] }) {
    return (
        <ol className="flex flex-col divide-y rounded-lg border">
            {entries.map((entry) => (
                <li key={entry.id} className="flex flex-col gap-1 px-4 py-3">
                    <p className="text-sm">{entry.text}</p>
                    {entry.reason && (
                        <p className="text-sm text-muted-foreground">
                            {entry.reason}
                        </p>
                    )}
                    <p className="text-xs text-muted-foreground">
                        {entry.causerName ?? 'System'} ·{' '}
                        <time dateTime={entry.createdAt}>
                            {new Date(entry.createdAt).toLocaleString('en-GB', {
                                day: '2-digit',
                                month: 'short',
                                year: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit',
                            })}
                        </time>
                    </p>
                </li>
            ))}
        </ol>
    );
}
