import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { sumMoney } from '@/components/pms/reservations/booking-lines';
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
 * The booking summary: units, the server's room and add-on totals added up for display, the billing mode and the guest
 * list. Tax shows "from folio" until the folio exists.
 */
export function BookingSummaryRail({
    units,
    quotes,
    currency,
    billingModes,
    billingMode,
    onBillingModeChange,
    bookerName,
}: {
    units: number;
    quotes: Quote[];
    currency: App.Enums.Currency | null;
    billingModes: EnumOption[];
    billingMode: string;
    onBillingModeChange: (value: string) => void;
    bookerName: string | null;
}) {
    const rooms = sumMoney(quotes.map((quote) => quote.lineTotal));
    const addOns = sumMoney(quotes.map((quote) => quote.addOnsTotal));
    const total = sumMoney([rooms, addOns]);

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
                    <Separator />
                    <Row label="Total">
                        <span className="font-medium">
                            <MoneyText amount={total} currency={currency} />
                        </span>
                    </Row>
                </dl>
                {units > 1 && (
                    <>
                        <Separator />
                        <div className="flex flex-col gap-2">
                            <span className="text-sm font-medium">Billing</span>
                            <EnumSelect
                                aria-label="Billing"
                                options={billingModes}
                                value={billingMode}
                                onValueChange={onBillingModeChange}
                            />
                        </div>
                    </>
                )}
                <Separator />
                <div className="flex flex-col gap-1 text-sm">
                    <span className="font-medium">Guests</span>
                    <span>
                        {bookerName ?? (
                            <span className="text-muted-foreground">
                                No booker yet
                            </span>
                        )}
                        {bookerName && (
                            <span className="text-muted-foreground">
                                {' '}
                                · booker
                            </span>
                        )}
                    </span>
                    <span className="text-muted-foreground">
                        {units} {units === 1 ? 'unit' : 'units'} without a guest
                    </span>
                </div>
            </CardContent>
        </Card>
    );
}
