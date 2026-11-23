import { formatDate } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';

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
            <dd className="text-right">{children}</dd>
        </div>
    );
}

/**
 * The summary rail of the New event page: what the event is, its facility lines and their totals from the server
 * quote. Facility charges post on the day of each booking. See
 * docs/33-facilities/03-events-and-event-folios.md#new-event-page.
 */
export function NewEventSummary({
    name,
    startsOn,
    endsOn,
    statusLabel,
    lineCount,
    quote,
    currency,
}: {
    name: string;
    startsOn: string;
    endsOn: string;
    statusLabel: string;
    lineCount: number;
    quote: App.Data.Facilities.EventLinesQuoteData | null;
    currency: App.Data.Property.CurrencyData | null;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Summary</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <dl className="flex flex-col gap-2">
                    <Row label="Event">{name.trim() || '–'}</Row>
                    <Row label="Dates">
                        <span className="tabular-nums">
                            {startsOn === endsOn
                                ? formatDate(startsOn)
                                : `${formatDate(startsOn)} – ${formatDate(endsOn)}`}
                        </span>
                    </Row>
                    <Row label="Status">{statusLabel}</Row>
                    <Row label="Facilities">
                        <span className="tabular-nums">{lineCount}</span>
                    </Row>
                </dl>
                {lineCount > 0 && (
                    <>
                        <Separator />
                        <dl className="flex flex-col gap-2">
                            <Row label="Facilities">
                                <MoneyText
                                    amount={quote?.amount ?? '0'}
                                    currency={currency}
                                    plain
                                />
                            </Row>
                            <Row label="Tax">
                                <MoneyText
                                    amount={quote?.tax ?? '0'}
                                    currency={currency}
                                    plain
                                />
                            </Row>
                            <Row label="Total">
                                <span className="font-medium">
                                    <MoneyText
                                        amount={quote?.total ?? '0'}
                                        currency={currency}
                                    />
                                </span>
                            </Row>
                        </dl>
                        <p className="text-sm text-muted-foreground">
                            Each booking is charged to the event folio on its
                            day. Facilities charged by hand are not counted.
                        </p>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
