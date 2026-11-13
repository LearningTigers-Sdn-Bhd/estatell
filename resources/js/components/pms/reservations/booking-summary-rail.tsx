import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { sumMoney } from '@/components/pms/reservations/booking-lines';
import {
    companyDefaultLabel,
    companyPaysCollectedTaxes,
    showsCollectedTaxes,
} from '@/components/pms/reservations/collected-taxes-hint';
import { eachPaysHint } from '@/components/pms/reservations/each-pays-hint';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';

type Quote = App.Data.Reservations.BookingQuoteData;

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-baseline justify-between gap-3 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

/**
 * Who pays the taxes collected at check-in, picked under Billing when a company is set. `choice` is a
 * `CollectedTaxesPayer` value, or "" for the company's default.
 */
type CollectedTaxesPick = {
    taxes: string[];
    payers: EnumOption[];
    choice: string;
    companyDefaultPays: boolean;
    onChange: (value: string) => void;
};

/**
 * The booking summary: units, the server's room and add-on totals added up for display, the billing mode, who pays the
 * taxes collected at check-in and the guest list. Tax shows "from folio" until the folio exists. Catalogue discounts are not in the total, because the folio applies
 * them; a note says so while any line has one.
 */
export function BookingSummaryRail({
    units,
    quotes,
    currency,
    billingModes,
    billingMode,
    onBillingModeChange,
    companyName,
    collectedTaxes,
    guests,
    unitsWithoutGuest,
    discountCount,
    registrationCard,
    payment,
    submitAction,
}: {
    units: number;
    quotes: Quote[];
    currency: App.Data.Property.CurrencyData | null;
    billingModes: EnumOption[];
    billingMode: string;
    onBillingModeChange: (value: string) => void;
    companyName?: string | null;
    collectedTaxes?: CollectedTaxesPick | null;
    guests: { name: string; unit: string | null }[];
    unitsWithoutGuest: number;
    /** How many lines have a catalogue discount picked. */
    discountCount: number;
    /** The walk-in Registration card block, under the total and above Payment. */
    registrationCard?: React.ReactNode;
    /** The walk-in Payment section, under the total. */
    payment?: React.ReactNode;
    /** The submit button, under the total, so it stays in view when the footer is out of sight. */
    submitAction?: React.ReactNode;
}) {
    const rooms = sumMoney(quotes.map((quote) => quote.lineTotal));
    const addOns = sumMoney(quotes.map((quote) => quote.addOnsTotal));
    const total = sumMoney([rooms, addOns]);
    const taxPick =
        collectedTaxes &&
        companyName &&
        showsCollectedTaxes(companyName, collectedTaxes.taxes, billingMode)
            ? collectedTaxes
            : null;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Summary</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <dl className="flex flex-col gap-2">
                    <Row label="Units">
                        <span className="tabular-nums">{units}</span>
                    </Row>
                    <Row label="Rooms">
                        <MoneyText amount={rooms} currency={currency} />
                    </Row>
                    <Row label="Add-ons">
                        <MoneyText amount={addOns} currency={currency} />
                    </Row>
                    <Row label="Tax">
                        <span className="text-muted-foreground">
                            from folio
                        </span>
                    </Row>
                    {taxPick && (
                        <Row
                            label={
                                taxPick.taxes.length === 1
                                    ? taxPick.taxes[0]
                                    : 'Taxes at check-in'
                            }
                        >
                            <span className="text-muted-foreground">
                                {companyPaysCollectedTaxes(
                                    taxPick.choice,
                                    taxPick.companyDefaultPays,
                                )
                                    ? companyName
                                    : 'guest, at check-in'}
                            </span>
                        </Row>
                    )}
                    <Separator />
                    <Row label="Total">
                        <span className="font-medium">
                            <MoneyText amount={total} currency={currency} />
                        </span>
                    </Row>
                </dl>
                {discountCount > 0 && (
                    <p className="text-sm text-muted-foreground">
                        {discountCount === 1
                            ? 'A discount is applied on the folio.'
                            : `${discountCount} discounts are applied on the folio.`}{' '}
                        It is not in this total.
                    </p>
                )}
                {submitAction}
                {registrationCard && (
                    <>
                        <Separator />
                        {registrationCard}
                    </>
                )}
                {payment && (
                    <>
                        <Separator />
                        {payment}
                    </>
                )}
                {(units > 1 || taxPick) && (
                    <>
                        <Separator />
                        <div className="flex flex-col gap-2">
                            <span className="text-sm font-medium">Billing</span>
                            {units > 1 && (
                                <>
                                    <EnumSelect
                                        aria-label="Billing"
                                        options={billingModes}
                                        value={billingMode}
                                        onValueChange={onBillingModeChange}
                                    />
                                    {eachPaysHint(companyName, billingMode) && (
                                        <p className="text-sm text-muted-foreground">
                                            {eachPaysHint(
                                                companyName,
                                                billingMode,
                                            )}
                                        </p>
                                    )}
                                </>
                            )}
                            {taxPick && (
                                <>
                                    <span className="text-sm text-muted-foreground">
                                        Taxes at check-in
                                    </span>
                                    <EnumSelect
                                        aria-label="Who pays the taxes at check-in"
                                        options={taxPick.payers}
                                        allLabel={companyDefaultLabel(
                                            taxPick.payers,
                                            taxPick.companyDefaultPays,
                                        )}
                                        value={taxPick.choice}
                                        onValueChange={taxPick.onChange}
                                    />
                                </>
                            )}
                        </div>
                    </>
                )}
                <Separator />
                <div className="flex flex-col gap-1 text-sm">
                    <span className="font-medium">Guests</span>
                    {guests.map((guest, index) => (
                        <span
                            key={index}
                            className="flex justify-between gap-3"
                        >
                            <span>
                                {guest.name}
                                {index === 0 && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · booker
                                    </span>
                                )}
                            </span>
                            <span className="text-right text-muted-foreground">
                                {guest.unit ??
                                    (index === 0 ? 'not staying' : '')}
                            </span>
                        </span>
                    ))}
                    {unitsWithoutGuest > 0 && (
                        <span className="text-muted-foreground">
                            {unitsWithoutGuest}{' '}
                            {unitsWithoutGuest === 1 ? 'unit' : 'units'} without
                            a guest
                        </span>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
