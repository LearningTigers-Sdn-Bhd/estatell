import { MoneyText } from '@/components/pms/money-text';
import { PendingLines } from '@/components/pms/reservations/audit-pending';
import type { CheckOutFigures } from './check-out-figures';
import { SummaryRow } from './summary-row';

type Preview = App.Data.Reservations.UnitCheckOutPreviewData;

/**
 * The unit folio the Dialog shows beside the form: the lines the check-out adds now, then the charges, what was paid,
 * the damage, the deposit held and the balance, split by room when the folio holds another room's charges.
 */
export function BillPanel({
    preview,
    figures,
    currency,
}: {
    preview: Preview;
    figures: CheckOutFigures;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const { charges, paid, damageOnFolio, held, owing, credit } = figures;

    return (
        <>
            <PendingLines lines={preview.pendingLines} currency={currency} />
            <div className="flex flex-col gap-1 text-sm">
                <span className="font-medium">Unit folio</span>
                <SummaryRow
                    label="Charges"
                    amount={charges}
                    currency={currency}
                />
                {Math.abs(paid) > 0.005 && (
                    <SummaryRow
                        label="Paid"
                        amount={Math.abs(paid)}
                        currency={currency}
                        negative={paid < 0}
                    />
                )}
                {damageOnFolio > 0 && (
                    <SummaryRow
                        label="Damage"
                        amount={damageOnFolio}
                        currency={currency}
                    />
                )}
                {held > 0 && (
                    <SummaryRow
                        label="Deposit held"
                        amount={held}
                        currency={currency}
                        negative
                    />
                )}
                <div className="flex justify-between gap-4 font-medium">
                    <span>
                        {owing > 0
                            ? 'To pay'
                            : credit > 0
                              ? 'Credit'
                              : 'Balance'}
                    </span>
                    {owing > 0 ? (
                        <span className="text-destructive">
                            <MoneyText
                                amount={owing.toFixed(2)}
                                currency={currency}
                            />
                        </span>
                    ) : credit > 0 ? (
                        <MoneyText
                            amount={credit.toFixed(2)}
                            currency={currency}
                        />
                    ) : (
                        <span>Settled</span>
                    )}
                </div>
                {preview.rooms.map((room) => (
                    <div
                        key={room.name}
                        className="flex justify-between gap-4 pl-4"
                    >
                        <span className="text-muted-foreground">
                            {room.name}
                        </span>
                        <MoneyText amount={room.amount} currency={currency} />
                    </div>
                ))}
            </div>
        </>
    );
}
