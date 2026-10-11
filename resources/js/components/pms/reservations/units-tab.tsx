import { Link, router } from '@inertiajs/react';
import { BedDouble, CalendarDays, Info, Tag, User, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EnumSelect } from '@/components/pms/enum-select';
import { Badge } from '@/components/ui/badge';
import { MoneyText } from '@/components/pms/money-text';
import { ChangeNightPriceDialog } from '@/components/pms/reservations/change-night-price-dialog';
import { UnitActionsMenu } from '@/components/pms/reservations/unit-actions-menu';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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

function Detail({
    icon: Icon,
    label,
    children,
}: {
    icon: LucideIcon;
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center gap-x-2">
            <span className="flex items-center gap-1.5 text-muted-foreground">
                <Icon aria-hidden className="size-4" />
                {label}
            </span>
            <span className="flex items-center gap-1">{children}</span>
        </div>
    );
}

/**
 * The Units tab list: Choose unit as a select below `lg` and a stack of links from `lg`.
 */
export function UnitsList({
    reservationId,
    units,
    activeUnitId,
    autoAssignRooms,
}: {
    reservationId: number;
    units: UnitOption[];
    activeUnitId: number;
    autoAssignRooms: boolean;
}) {
    return (
        <>
            <div className="lg:hidden">
                <EnumSelect
                    aria-label="Choose unit"
                    options={units.map((option) => ({
                        value: String(option.id),
                        label: `${option.label} · ${option.status.label}`,
                    }))}
                    value={String(activeUnitId)}
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
                className="hidden flex-col gap-1 lg:flex"
            >
                {units.map((option) => (
                    <Link
                        key={option.id}
                        href={unitUrl(reservationId, option.id)}
                        preserveScroll
                        preserveState
                        aria-current={
                            option.id === activeUnitId ? 'true' : undefined
                        }
                        className={cn(
                            'flex flex-col rounded-lg border px-3 py-2 text-sm hover:bg-muted',
                            option.id === activeUnitId &&
                                'border-primary bg-muted',
                        )}
                    >
                        <span className="font-medium">{option.roomType}</span>
                        <span className="text-muted-foreground">
                            {option.assigned ??
                                (autoAssignRooms
                                    ? 'Auto'
                                    : 'Not assigned')}{' '}
                            · {option.status.label}
                        </span>
                    </Link>
                ))}
            </nav>
        </>
    );
}

/**
 * The Units tab: the panel of the open unit. The unit list is a separate slot of the workspace.
 */
export function UnitsTab({
    reservationId,
    unit,
    autoAssignRooms,
    canWaiveFees,
    canOverrideRestrictions,
    paymentOptions,
    currency,
}: {
    reservationId: number;
    unit: Unit | null;
    autoAssignRooms: boolean;
    canWaiveFees: boolean;
    canOverrideRestrictions: boolean;
    paymentOptions?: App.Data.Payments.PaymentFormOptionsData;
    currency: App.Enums.Currency | null;
}) {
    const [ticked, setTicked] = useState<number[]>([]);
    const [changingPrice, setChangingPrice] = useState(false);

    if (unit === null) {
        return <p className="text-sm text-muted-foreground">No units.</p>;
    }

    const nightIds = unit.nights.map((night) => night.id);
    const tickedNow = ticked.filter((id) => nightIds.includes(id));
    const allTicked =
        nightIds.length > 0 && tickedNow.length === nightIds.length;

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between gap-3 ps-3 pt-2">
                <h2 className="font-heading text-base font-medium">
                    {unit.roomType} · {unit.ratePlan}
                </h2>
                <div className="flex items-center gap-2">
                    <StatusBadge status={unit.status} />
                    <UnitActionsMenu
                        reservationId={reservationId}
                        unit={unit}
                        canWaiveFees={canWaiveFees}
                        canOverrideRestrictions={canOverrideRestrictions}
                        paymentOptions={paymentOptions}
                        currency={currency}
                    />
                </div>
            </div>
            <div className="flex flex-col gap-2 ps-3 text-sm">
                <Detail icon={CalendarDays} label="Stay">
                    <DateText date={unit.arrival} /> {unit.arrivalTime} →{' '}
                    <DateText date={unit.departure} /> {unit.departureTime} ·{' '}
                    {unit.nightCount}{' '}
                    {unit.nightCount === 1 ? 'night' : 'nights'}
                </Detail>
                <div className="flex flex-wrap gap-x-6 gap-y-2">
                    <Detail icon={BedDouble} label="Room">
                        {roomText(unit, autoAssignRooms)}
                    </Detail>
                    <Detail icon={Users} label="Guests">
                        {guestsText(unit)}
                    </Detail>
                </div>
                <Detail icon={User} label="Primary">
                    {unit.primaryGuest === null ? (
                        <span className="text-muted-foreground">
                            No primary guest
                        </span>
                    ) : unit.primaryGuestId !== null ? (
                        <Link
                            href={showGuest.url({
                                guest: unit.primaryGuestId,
                            })}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="underline underline-offset-4"
                        >
                            {unit.primaryGuest}
                        </Link>
                    ) : (
                        unit.primaryGuest
                    )}
                </Detail>
            </div>
            {unit.canChangePrice && (
                <div className="flex items-center justify-end gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setChangingPrice(true)}
                    >
                        <Tag data-icon="inline-start" />
                        Change price
                    </Button>
                </div>
            )}
            <div className="overflow-hidden rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            {unit.canChangePrice && (
                                <TableHead className="w-10">
                                    <Checkbox
                                        aria-label="Tick every night"
                                        checked={allTicked}
                                        onCheckedChange={(checked) =>
                                            setTicked(checked ? nightIds : [])
                                        }
                                    />
                                </TableHead>
                            )}
                            <TableHead>Night</TableHead>
                            <TableHead>Room</TableHead>
                            <TableHead>Rate</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {unit.nights.map((night) => (
                            <TableRow key={night.id}>
                                {unit.canChangePrice && (
                                    <TableCell>
                                        <Checkbox
                                            aria-label={`Tick ${night.date}`}
                                            checked={tickedNow.includes(
                                                night.id,
                                            )}
                                            onCheckedChange={(checked) =>
                                                setTicked(
                                                    checked
                                                        ? [
                                                              ...tickedNow,
                                                              night.id,
                                                          ]
                                                        : tickedNow.filter(
                                                              (id) =>
                                                                  id !==
                                                                  night.id,
                                                          ),
                                                )
                                            }
                                        />
                                    </TableCell>
                                )}
                                <TableCell>
                                    <DateText date={night.date} />
                                    {night.isCharged && (
                                        <span className="ms-2 text-xs text-muted-foreground">
                                            Charged
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell>
                                    {night.assigned ?? (
                                        <Badge variant="outline">
                                            Unassigned
                                        </Badge>
                                    )}
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
                            <TableCell colSpan={unit.canChangePrice ? 4 : 3}>
                                Total
                            </TableCell>
                            <TableCell className="text-right">
                                <MoneyText
                                    amount={unit.total}
                                    currency={currency}
                                />
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </div>
            {unit.priceOverrideReason !== null && (
                <p className="flex items-start gap-2 text-sm text-muted-foreground">
                    <Info aria-hidden className="mt-0.5 size-4 shrink-0" />
                    <span>
                        Price override
                        {unit.priceOverrideBy
                            ? ` by ${unit.priceOverrideBy}`
                            : ''}
                        : “{unit.priceOverrideReason}”
                    </span>
                </p>
            )}
            {unit.canChangePrice && (
                <ChangeNightPriceDialog
                    open={changingPrice}
                    onOpenChange={(open) => {
                        setChangingPrice(open);

                        if (!open) {
                            setTicked([]);
                        }
                    }}
                    reservationId={reservationId}
                    unitId={unit.id}
                    title={unit.roomType}
                    description={unit.primaryGuest}
                    initialNightIds={tickedNow}
                    currency={currency}
                />
            )}
        </div>
    );
}
