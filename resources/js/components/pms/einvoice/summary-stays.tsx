import { Link } from '@inertiajs/react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { folio as folioTab } from '@/routes/reservations';
import { type Submission } from '@/components/pms/einvoice/submission';

/**
 * The stays in a monthly summary, to check against the folios. Amounts are split as LHDN received them; the totals
 * row matches the summary.
 */
export function SummaryStays({
    submission,
    currency,
}: {
    submission: Submission;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const money = (amount: string) => (
        <MoneyText amount={amount} currency={currency} />
    );
    const sum = (key: 'net' | 'tax' | 'total') =>
        submission.stays
            .reduce((total, stay) => total + Number(stay[key]), 0)
            .toFixed(2);

    return (
        <section className="flex flex-col gap-2">
            <h2 className="text-sm font-medium">
                Stays in this summary ({submission.stays.length})
            </h2>
            <p className="text-sm text-muted-foreground">
                Each stay as LHDN received it. Open a reservation to check its
                folio.
            </p>
            <div className="overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Reservation</TableHead>
                            <TableHead>Guest</TableHead>
                            <TableHead>Folio</TableHead>
                            <TableHead>Closed</TableHead>
                            <TableHead className="text-right">
                                Before tax
                            </TableHead>
                            <TableHead className="text-right">Tax</TableHead>
                            <TableHead className="text-right">Total</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {submission.stays.map((stay) => (
                            <TableRow key={stay.folio_id}>
                                <TableCell>
                                    <Link
                                        href={folioTab.url(
                                            {
                                                reservation:
                                                    stay.reservation_id,
                                            },
                                            {
                                                query: {
                                                    folio: String(
                                                        stay.folio_id,
                                                    ),
                                                },
                                            },
                                        )}
                                        className="font-medium underline-offset-2 hover:underline"
                                    >
                                        {stay.reference ?? 'Reservation'}
                                    </Link>
                                </TableCell>
                                <TableCell>{stay.guest ?? '—'}</TableCell>
                                <TableCell className="text-muted-foreground">
                                    {stay.folio_number ?? '—'}
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {stay.closed_on ? (
                                        <DateText date={stay.closed_on} />
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell className="text-right">
                                    {money(stay.net)}
                                </TableCell>
                                <TableCell className="text-right">
                                    {money(stay.tax)}
                                </TableCell>
                                <TableCell className="text-right">
                                    {money(stay.total)}
                                </TableCell>
                            </TableRow>
                        ))}
                        <TableRow className="font-medium">
                            <TableCell colSpan={4}>Total</TableCell>
                            <TableCell className="text-right">
                                {money(sum('net'))}
                            </TableCell>
                            <TableCell className="text-right">
                                {money(sum('tax'))}
                            </TableCell>
                            <TableCell className="text-right">
                                {money(sum('total'))}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </section>
    );
}
