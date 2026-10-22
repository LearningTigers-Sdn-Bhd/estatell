import { Info, TriangleAlert } from 'lucide-react';
import { MoneyText } from '@/components/pms/money-text';
import { cn } from '@/lib/utils';

type Pending = App.Data.Reservations.UnitAuditPendingData;
type Line = App.Data.Reservations.UnitPendingLineData;

/**
 * The warning strip at the top of a front desk form while the night audit is pending: the hotel day, today and what the
 * night audit is doing. The server sends the texts, so the browser has no date rules. Renders nothing without the block.
 */
export function AuditPendingStrip({
    pending,
    className,
}: {
    pending: Pending | null | undefined;
    className?: string;
}) {
    if (!pending) {
        return null;
    }

    return (
        <div
            role="status"
            className={cn(
                'flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-sm',
                className,
            )}
        >
            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
            <p>
                <span className="font-medium">
                    Hotel day {pending.businessDate} · Today {pending.today}
                </span>
                {'. '}
                {pending.running
                    ? 'Night audit running.'
                    : 'Night audit not run yet.'}
            </p>
        </div>
    );
}

/**
 * The ⓘ line under the stay: what the pending night audit means for this guest. Renders nothing without a notice.
 */
export function AuditPendingNote({
    pending,
}: {
    pending: Pending | null | undefined;
}) {
    if (!pending?.notice) {
        return null;
    }

    return (
        <p className="flex items-start gap-2 text-sm text-muted-foreground">
            <Info className="mt-0.5 size-4 shrink-0" />
            {pending.notice}
        </p>
    );
}

/**
 * The lines the check-out posts first for the night of the business date, marked as added now. A discount or tax line
 * (`level` 1) is indented under its charge.
 */
export function PendingLines({
    lines,
    currency,
}: {
    lines: Line[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    if (lines.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-1 text-sm">
            {lines.map((line, index) => (
                <div
                    key={index}
                    className={cn(
                        'flex justify-between gap-4',
                        line.level > 0 && 'pl-4',
                    )}
                >
                    <span className="text-muted-foreground">
                        {line.description}
                        {line.level === 0 && ' (added now)'}
                    </span>
                    <MoneyText amount={line.amount} currency={currency} />
                </div>
            ))}
        </div>
    );
}
