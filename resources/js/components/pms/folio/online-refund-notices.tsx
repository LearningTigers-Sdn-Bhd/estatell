import { router } from '@inertiajs/react';
import { useState } from 'react';
import { MoneyText } from '@/components/pms/money-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertAction, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { retry } from '@/routes/reservations/folios/online-refunds';

/**
 * The online refunds of a folio that are not finished: a Pending one waits on Curlec, and a Failed one can be retried. The
 * refund line posts on the folio only when Curlec confirms it.
 */
export function OnlineRefundNotices({
    reservationId,
    folio,
    currency,
    canRetry,
}: {
    reservationId: number;
    folio: App.Data.Folio.FolioData;
    currency: App.Data.Property.CurrencyData | null;
    canRetry: boolean;
}) {
    const [retrying, setRetrying] = useState<number | null>(null);

    const submitRetry = (refundId: number) => {
        setRetrying(refundId);
        router.post(
            retry.url({
                reservation: reservationId,
                folio: Number(folio.key),
                gatewayRefund: refundId,
            }),
            {},
            {
                preserveScroll: true,
                onFinish: () => setRetrying(null),
            },
        );
    };

    return folio.onlineRefunds.map((refund) => (
        <Alert
            key={refund.id}
            className="flex flex-wrap items-center gap-x-2 gap-y-2 has-data-[slot=alert-action]:pr-2.5"
        >
            <AlertDescription className="flex min-w-0 flex-1 basis-60 flex-wrap items-center gap-2">
                <StatusBadge status={refund.status} />
                <MoneyText amount={refund.amount} currency={currency} />
                {refund.failureReason && <span>{refund.failureReason}</span>}
            </AlertDescription>
            {refund.canRetry && canRetry && (
                <AlertAction className="static">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={retrying !== null}
                        focusableWhenDisabled
                        onClick={() => submitRetry(refund.id)}
                    >
                        {retrying === refund.id && (
                            <Spinner data-icon="inline-start" />
                        )}
                        Retry
                    </Button>
                </AlertAction>
            )}
        </Alert>
    ));
}
