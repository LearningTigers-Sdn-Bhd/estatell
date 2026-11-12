import { Link } from '@inertiajs/react';
import { BedDouble, History } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { PanelHeading } from '@/components/pms/panel-heading';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { show as reservation } from '@/routes/reservations';

type Stay = App.Data.Guests.GuestStayData;

/**
 * The Stays tab: every stay of the guest, newest first, with a total row. Spend shows only on stays where the guest
 * is the primary guest, so a shared room counts once.
 */
export function StaysTab({
    stays,
    summary,
    currency,
}: {
    stays: Stay[];
    summary: App.Data.Guests.GuestStaySummaryData;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const heading = (
        <PanelHeading
            icon={History}
            title="Stays"
            description="Checked-in and checked-out stays, newest first. Spend is without tax."
        />
    );

    if (stays.length === 0) {
        return (
            <div className="flex flex-col gap-4">
                {heading}
                <EmptyState
                    icon={BedDouble}
                    title="No stays yet"
                    description="Checked-in and checked-out stays show here."
                />
            </div>
        );
    }

    const shared = stays.some((stay) => !stay.isPrimary);

    return (
        <div className="flex flex-col gap-4">
            {heading}
            <div className="overflow-auto rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Booking</TableHead>
                            <TableHead>Dates</TableHead>
                            <TableHead>Room</TableHead>
                            <TableHead>Source</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead className="text-right">Nights</TableHead>
                            <TableHead className="text-right">
                                Spend{currency ? ` (${currency.code})` : ''}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {stays.map((stay) => (
                            <TableRow key={stay.unitId}>
                                <TableCell>
                                    <Link
                                        href={reservation.url({
                                            reservation: stay.reservationId,
                                        })}
                                        className="font-medium hover:underline"
                                    >
                                        {stay.shortCode}
                                    </Link>
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    <DateText date={stay.arrival} />
                                    {stay.isDayUse ? (
                                        ' · Day use'
                                    ) : (
                                        <>
                                            {' → '}
                                            <DateText date={stay.departure} />
                                        </>
                                    )}
                                </TableCell>
                                <TableCell>{stay.room}</TableCell>
                                <TableCell>{stay.source ?? '—'}</TableCell>
                                <TableCell>
                                    <StatusBadge status={stay.status} />
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {stay.nights}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {stay.spend === null ? (
                                        '—'
                                    ) : (
                                        <MoneyText
                                            amount={stay.spend}
                                            currency={currency}
                                            plain
                                        />
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell colSpan={5}>Total</TableCell>
                            <TableCell className="text-right tabular-nums">
                                {summary.nights}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                <MoneyText
                                    amount={summary.spend}
                                    currency={currency}
                                    plain
                                />
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </div>
            {shared && (
                <p className="text-sm text-muted-foreground">
                    — in Spend: another guest is the primary guest of that room,
                    so the spend counts on them.
                </p>
            )}
        </div>
    );
}
