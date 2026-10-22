import { ExternalLink, HandCoins, Landmark } from 'lucide-react';
import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { RecordPaymentDialog } from '@/components/pms/folio/record-payment-dialog';
import { SettleCityLedgerDialog } from '@/components/pms/folio/settle-city-ledger-dialog';
import { MoneyText } from '@/components/pms/money-text';
import { Button, buttonVariants } from '@/components/ui/button';

type Folio = App.Data.Reservations.GroupCheckOutFolioData;
type Options = App.Data.Payments.PaymentFormOptionsData;

/**
 * The Open Master and Third party folios that still hold money once every room is out, one row each with what the user
 * may do: Record payment and Settle to City ledger (the same Dialogs as the folio tab) and Open folio, which opens
 * the folio tab in a new tab. The page asks the server again when its tab regains focus, so a change made in the other
 * tab shows here. Refund is not offered: it needs a payment line, so it stays on the folio tab.
 */
export function GroupCheckOutFolios({
    folios,
    reservationId,
    paymentOptions,
    currency,
}: {
    folios: Folio[];
    reservationId: number;
    paymentOptions?: Options;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [paying, setPaying] = useState<Folio | null>(null);
    const [settling, setSettling] = useState<Folio | null>(null);

    useEffect(() => {
        const reload = () => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['checkOut'] });
            }
        };

        document.addEventListener('visibilitychange', reload);

        return () => document.removeEventListener('visibilitychange', reload);
    }, []);

    return (
        <div className="flex max-w-3xl flex-col gap-2">
            <h2 className="text-sm font-medium">Folios still open</h2>
            <div className="flex flex-col divide-y rounded-lg border">
                {folios.map((folio) => (
                    <div
                        key={folio.key}
                        className="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div className="flex min-w-0 flex-col gap-1 text-sm">
                            <span className="font-medium">{folio.name}</span>
                            <span className="text-muted-foreground">
                                {Number(folio.balance) > 0
                                    ? 'To pay '
                                    : 'In credit '}
                                <MoneyText
                                    amount={Math.abs(
                                        Number(folio.balance),
                                    ).toFixed(2)}
                                    currency={currency}
                                />
                            </span>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {folio.canRecordPayment && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setPaying(folio)}
                                >
                                    <HandCoins data-icon="inline-start" />
                                    Record payment
                                </Button>
                            )}
                            {folio.canSettleToCityLedger && (
                                <Button
                                    type="button"
                                    onClick={() => setSettling(folio)}
                                >
                                    <Landmark data-icon="inline-start" />
                                    Settle to City ledger
                                </Button>
                            )}
                            <a
                                href={folio.url}
                                target="_blank"
                                rel="noopener"
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                <ExternalLink data-icon="inline-start" />
                                Open folio
                            </a>
                        </div>
                    </div>
                ))}
            </div>
            {paying !== null && (
                <RecordPaymentDialog
                    open
                    onOpenChange={(open) => !open && setPaying(null)}
                    reservationId={reservationId}
                    folio={paying}
                    options={paymentOptions}
                    currency={currency}
                />
            )}
            {settling !== null && (
                <SettleCityLedgerDialog
                    open
                    onOpenChange={(open) => !open && setSettling(null)}
                    reservationId={reservationId}
                    folio={settling}
                    currency={currency}
                />
            )}
        </div>
    );
}
