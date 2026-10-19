import { MoneyText } from '@/components/pms/money-text';
import { cn } from '@/lib/utils';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';

function Figure({
    label,
    now,
    after,
    currency,
}: {
    label: string;
    now: string;
    after: string;
    currency: App.Data.Property.CurrencyData | null;
}) {
    return (
        <>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right">
                <MoneyText amount={now} currency={currency} plain />
            </dd>
            <dd className="text-right font-medium">
                <MoneyText amount={after} currency={currency} plain />
            </dd>
        </>
    );
}

/**
 * The summary panel: charges, payments and balance of the selected folio, now and after posting, the balance of every
 * folio the rows touch, and the Close after posting choice. Every figure comes from the server preview. It sits in a
 * `ReservationWorkspace` panel, which draws the card.
 */
export function ManualFolioRail({
    preview,
    currency,
    current,
    close,
    onCloseChange,
}: {
    preview: App.Data.Folio.ManualPreviewData;
    currency: App.Data.Property.CurrencyData | null;
    /** False while the shown figures are not for the latest rows. */
    current: boolean;
    close: boolean;
    onCloseChange: (close: boolean) => void;
}) {
    const blocked = preview.closeBlocked;

    return (
        <div
            className={cn(
                'flex flex-col gap-4 p-2.5',
                !current && 'opacity-70',
            )}
        >
            <h2 className="font-medium">Summary</h2>
            <dl className="grid grid-cols-[1fr_auto_auto] gap-x-4 gap-y-1 text-sm tabular-nums">
                <span />
                <dd className="text-right text-xs text-muted-foreground">
                    Now
                </dd>
                <dd className="text-right text-xs text-muted-foreground">
                    After
                </dd>
                <Figure
                    label="Charges"
                    now={preview.chargesNow}
                    after={preview.chargesAfter}
                    currency={currency}
                />
                <Figure
                    label="Payments"
                    now={preview.paymentsNow}
                    after={preview.paymentsAfter}
                    currency={currency}
                />
                <Figure
                    label="Balance"
                    now={preview.balanceNow}
                    after={preview.balanceAfter}
                    currency={currency}
                />
            </dl>
            {preview.folios.length > 1 && (
                <div className="flex flex-col gap-1 border-t pt-3 text-sm">
                    <span className="text-xs text-muted-foreground">
                        By folio
                    </span>
                    <dl className="grid grid-cols-[1fr_auto_auto] gap-x-4 gap-y-1 tabular-nums">
                        {preview.folios.map((folio) => (
                            <Figure
                                key={folio.folioId}
                                label={folio.label}
                                now={folio.now}
                                after={folio.after}
                                currency={currency}
                            />
                        ))}
                    </dl>
                </div>
            )}
            <Field orientation="horizontal" className="border-t pt-3">
                <Checkbox
                    id="manual-close"
                    checked={close && blocked === null}
                    onCheckedChange={(checked) =>
                        onCloseChange(checked === true)
                    }
                    disabled={blocked !== null}
                />
                <div className="flex flex-col gap-1">
                    <FieldLabel htmlFor="manual-close">
                        Close the folio after posting
                    </FieldLabel>
                    {blocked !== null ? (
                        <FieldDescription>{blocked}</FieldDescription>
                    ) : (
                        <FieldDescription>
                            Only a folio with a balance of 0.00 can close.
                        </FieldDescription>
                    )}
                </div>
            </Field>
        </div>
    );
}
