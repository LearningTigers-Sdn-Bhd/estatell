import { Link, router } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { show as showGuest } from '@/routes/guests';
import { show } from '@/routes/reservations';

type UnitOption = App.Data.Reservations.ReservationUnitOptionData;
type Unit = App.Data.Reservations.ReservationUnitData;

function unitUrl(reservationId: number, unitId: number): string {
    return show.url(
        { reservation: reservationId },
        { query: { unit: unitId } },
    );
}

function roomText(unit: Unit, autoAssignRooms: boolean): string {
    if (unit.assigned.length === 0) {
        return autoAssignRooms ? 'Assigned automatically' : 'Not assigned';
    }

    if (unit.assigned.length === 1 && unit.allNightsAssigned) {
        return `${unit.assigned[0]} (all nights)`;
    }

    return `${unit.assigned.join(', ')} (changes during the stay)`;
}

function guestsText(unit: Unit): string {
    const adults = `${unit.adults} ${unit.adults === 1 ? 'adult' : 'adults'}`;

    if (unit.children === 0) {
        return adults;
    }

    const children = `${unit.children} ${unit.children === 1 ? 'child' : 'children'}`;

    return `${adults}, ${children} (${unit.childAges.join(', ')})`;
}

/**
 * The Units tab: Choose unit on the left (a select below `lg`, hidden for one unit) and the panel of the open unit.
 */
export function UnitsTab({
    reservationId,
    units,
    unit,
    autoAssignRooms,
    currency,
}: {
    reservationId: number;
    units: UnitOption[];
    unit: Unit | null;
    autoAssignRooms: boolean;
    currency: App.Enums.Currency | null;
}) {
    if (unit === null) {
        return <p className="text-sm text-muted-foreground">No units.</p>;
    }

    const choose = units.length > 1;

    return (
        <div className={cn('grid gap-4', choose && 'lg:grid-cols-[14rem_1fr]')}>
            {choose && (
                <>
                    <div className="lg:hidden">
                        <EnumSelect
                            aria-label="Choose unit"
                            options={units.map((option) => ({
                                value: String(option.id),
                                label: `${option.label} · ${option.status.label}`,
                            }))}
                            value={String(unit.id)}
                            onValueChange={(value) =>
                                router.get(
                                    unitUrl(reservationId, Number(value)),
                                    {},
                                    {
                                        preserveScroll: true,
                                        preserveState: true,
                                        replace: true,
                                    },
                                )
                            }
                        />
                    </div>
                    <nav
                        aria-label="Choose unit"
                        className="hidden h-fit flex-col gap-1 lg:flex"
                    >
                        {units.map((option) => (
                            <Link
                                key={option.id}
                                href={unitUrl(reservationId, option.id)}
                                preserveScroll
                                preserveState
                                aria-current={
                                    option.id === unit.id ? 'true' : undefined
                                }
                                className={cn(
                                    'flex flex-col rounded-lg border px-3 py-2 text-sm hover:bg-muted',
                                    option.id === unit.id &&
                                        'border-primary bg-muted',
                                )}
                            >
                                <span className="font-medium">
                                    {option.roomType}
                                </span>
                                <span className="text-muted-foreground">
                                    {option.assigned ??
                                        (autoAssignRooms ? 'auto' : '—')}{' '}
                                    · {option.status.label}
                                </span>
                            </Link>
                        ))}
                    </nav>
                </>
            )}
            <Card>
                <CardHeader className="flex flex-row items-center justify-between gap-3">
                    <h2 className="font-heading text-base font-medium">
                        {unit.roomType} · {unit.ratePlan}
                    </h2>
                    <StatusBadge status={unit.status} />
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    <dl className="grid gap-2 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-6">
                        <dt className="text-muted-foreground">Stay</dt>
                        <dd className="flex items-center gap-1">
                            <DateText date={unit.arrival} /> →{' '}
                            <DateText date={unit.departure} /> ·{' '}
                            {unit.nightCount}{' '}
                            {unit.nightCount === 1 ? 'night' : 'nights'}
                        </dd>
                        <dt className="text-muted-foreground">Guests</dt>
                        <dd>{guestsText(unit)}</dd>
                        <dt className="text-muted-foreground">Room</dt>
                        <dd>{roomText(unit, autoAssignRooms)}</dd>
                        <dt className="text-muted-foreground">Primary</dt>
                        <dd>
                            {unit.primaryGuest === null ? (
                                '—'
                            ) : unit.primaryGuestId !== null ? (
                                <Link
                                    href={showGuest.url({
                                        guest: unit.primaryGuestId,
                                    })}
                                    className="underline underline-offset-4"
                                >
                                    {unit.primaryGuest}
                                </Link>
                            ) : (
                                unit.primaryGuest
                            )}
                        </dd>
                    </dl>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Night</TableHead>
                                <TableHead>Room</TableHead>
                                <TableHead>Rate</TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {unit.nights.map((night) => (
                                <TableRow key={night.date}>
                                    <TableCell>
                                        <DateText date={night.date} />
                                    </TableCell>
                                    <TableCell>
                                        {night.assigned ?? '—'}
                                    </TableCell>
                                    <TableCell>{night.ratePlan}</TableCell>
                                    <TableCell className="text-right">
                                        <MoneyText
                                            amount={night.amount}
                                            currency={currency}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={3}>Total</TableCell>
                                <TableCell className="text-right">
                                    <MoneyText
                                        amount={unit.total}
                                        currency={currency}
                                    />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                    {unit.priceOverrideReason !== null && (
                        <p className="flex items-start gap-2 text-sm text-muted-foreground">
                            <Info
                                aria-hidden
                                className="mt-0.5 size-4 shrink-0"
                            />
                            <span>
                                Price override
                                {unit.priceOverrideBy
                                    ? ` by ${unit.priceOverrideBy}`
                                    : ''}
                                : “{unit.priceOverrideReason}”
                            </span>
                        </p>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
