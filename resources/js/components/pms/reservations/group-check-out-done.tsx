import { Link } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { MoneyText } from '@/components/pms/money-text';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { buttonVariants } from '@/components/ui/button';
import { receipts } from '@/routes/reservations/check-out';

type Done = App.Data.Reservations.GroupCheckOutDoneData;

const plural = (count: number, one: string, many: string) =>
    `${count} ${count === 1 ? one : many}`;

function Figure({
    label,
    amount,
    currency,
}: {
    label: string;
    amount: string;
    currency: App.Data.Property.CurrencyData | null;
}) {
    return (
        <div className="flex justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <MoneyText amount={amount} currency={currency} />
        </div>
    );
}

/**
 * The state after a group check-out is saved: the totals, then the next steps. Print receipts opens one PDF with every
 * receipt of this save (`done.paymentIds`) in a new tab; it is shown only with `payment.record` (`canPrintReceipts`).
 */
export function GroupCheckOutDone({
    done,
    currency,
    reservationId,
    reservationUrl,
    canPrintReceipts,
}: {
    done: Done;
    currency: App.Data.Property.CurrencyData | null;
    reservationId: number;
    reservationUrl: string;
    canPrintReceipts: boolean;
}) {
    return (
        <div className="flex max-w-xl flex-col gap-4">
            <Alert>
                <CheckCircle2 />
                <AlertTitle>
                    {plural(done.checkedOut, 'room', 'rooms')} checked out
                </AlertTitle>
                {done.settled > 0 && (
                    <AlertDescription>
                        {plural(done.settled, 'room', 'rooms')} that were
                        already out or cancelled were settled.
                    </AlertDescription>
                )}
            </Alert>

            <div className="flex flex-col gap-1 text-sm">
                <Figure
                    label="Collected"
                    amount={done.collected}
                    currency={currency}
                />
                <Figure
                    label="Refunded"
                    amount={done.refunded}
                    currency={currency}
                />
                <Figure
                    label="Moved to group bill"
                    amount={done.movedToMaster}
                    currency={currency}
                />
                {done.invoiceNumber && (
                    <div className="flex justify-between gap-4">
                        <span className="text-muted-foreground">
                            City ledger invoice
                        </span>
                        <span className="font-medium">
                            {done.invoiceNumber}
                        </span>
                    </div>
                )}
            </div>

            <div className="flex flex-wrap gap-2">
                {canPrintReceipts && done.paymentIds.length > 0 && (
                    <a
                        href={receipts.url(
                            { reservation: reservationId },
                            { query: { payments: done.paymentIds } },
                        )}
                        target="_blank"
                        rel="noopener"
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        Print receipts ({done.paymentIds.length})
                    </a>
                )}
                {done.masterUrl !== null && (
                    <Link
                        href={done.masterUrl}
                        className={buttonVariants({
                            variant: done.canSettleMaster
                                ? 'default'
                                : 'outline',
                        })}
                    >
                        {done.canSettleMaster
                            ? 'Settle to City ledger'
                            : 'Open folio'}
                    </Link>
                )}
                <Link
                    href={reservationUrl}
                    className={buttonVariants({ variant: 'outline' })}
                >
                    Back to reservation
                </Link>
            </div>
        </div>
    );
}
