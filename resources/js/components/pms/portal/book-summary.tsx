import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';

/**
 * The summary rail of the book page, in the style of the staff booking summary: the stay, one row for each line, the
 * room and auto-added totals from the server added up for display, who is billed, the free-cancellation date, the guest
 * names and Confirm. There is no billing choice: the company always pays.
 */
export function BookSummary({
    arrival,
    departure,
    nights,
    rows,
    rooms,
    addOns,
    total,
    autoAddOns,
    companyName,
    freeUntil,
    today,
    guests,
    unnamed,
    currency,
    canConfirm,
    processing,
}: {
    arrival: string;
    departure: string;
    nights: number;
    rows: { key: string; label: string; amount: string | null }[];
    rooms: string;
    addOns: string;
    total: string;
    autoAddOns: string[];
    companyName: string;
    /** The last day of free cancellation, or null when the company can only ask the property to cancel. */
    freeUntil: string | null;
    today: string;
    guests: { key: string; name: string; unit: string; isBooker: boolean }[];
    unnamed: number;
    currency: App.Data.Property.CurrencyData | null;
    canConfirm: boolean;
    processing: boolean;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Summary</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {nights > 0 && (
                    <p className="text-sm">
                        <DateText date={arrival} /> –{' '}
                        <DateText date={departure} /> · {nights}{' '}
                        {nights === 1 ? 'night' : 'nights'}
                    </p>
                )}
                <dl className="flex flex-col gap-2">
                    {rows.map((row) => (
                        <div
                            key={row.key}
                            className="flex items-baseline justify-between gap-3 text-sm"
                        >
                            <dt className="text-muted-foreground">
                                {row.label}
                            </dt>
                            <dd>
                                {row.amount === null ? (
                                    '—'
                                ) : (
                                    <MoneyText
                                        amount={row.amount}
                                        currency={currency}
                                    />
                                )}
                            </dd>
                        </div>
                    ))}
                    <div className="flex items-baseline justify-between gap-3 text-sm">
                        <dt className="text-muted-foreground">Rooms</dt>
                        <dd>
                            <MoneyText amount={rooms} currency={currency} />
                        </dd>
                    </div>
                    {autoAddOns.length > 0 && (
                        <div className="flex items-baseline justify-between gap-3 text-sm">
                            <dt className="text-muted-foreground">
                                {autoAddOns.join(', ')}
                            </dt>
                            <dd>
                                <MoneyText
                                    amount={addOns}
                                    currency={currency}
                                />
                            </dd>
                        </div>
                    )}
                    <Separator />
                    <div className="flex items-baseline justify-between gap-3 text-sm">
                        <dt className="font-medium">Total</dt>
                        <dd className="font-medium">
                            <MoneyText amount={total} currency={currency} />
                        </dd>
                    </div>
                </dl>
                <div className="flex flex-col gap-1 text-sm text-muted-foreground">
                    <p>Billed to {companyName}.</p>
                    {freeUntil !== null &&
                        (freeUntil >= today ? (
                            <p>
                                Free cancellation until{' '}
                                <DateText date={freeUntil} />.
                            </p>
                        ) : (
                            <p>
                                Free cancellation has ended for these dates. You
                                can ask the property to cancel.
                            </p>
                        ))}
                </div>
                <Separator />
                <div className="flex flex-col gap-1 text-sm">
                    <span className="font-medium">Guests</span>
                    {guests.map((guest) => (
                        <span
                            key={guest.key}
                            className="flex justify-between gap-3"
                        >
                            <span>
                                {guest.name}
                                {guest.isBooker && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · booker
                                    </span>
                                )}
                            </span>
                            <span className="text-right text-muted-foreground">
                                {guest.unit}
                            </span>
                        </span>
                    ))}
                    {unnamed > 0 && (
                        <span className="text-muted-foreground">
                            {unnamed} {unnamed === 1 ? 'room' : 'rooms'} without
                            a name
                        </span>
                    )}
                </div>
                <Button
                    type="submit"
                    disabled={!canConfirm || processing}
                    focusableWhenDisabled
                >
                    {processing && <Spinner data-icon="inline-start" />}
                    Confirm booking
                </Button>
            </CardContent>
        </Card>
    );
}
