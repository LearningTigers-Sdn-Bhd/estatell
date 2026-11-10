import { Ship } from 'lucide-react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { PaxCount } from '@/components/pms/pax-count';
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
import { cn } from '@/lib/utils';

type Unit = App.Data.Portal.PortalBookingUnitData;
type BoatUnit = App.Data.Boats.BoatSetupUnitData;

function nightsText(count: number): string {
    return `${count} ${count === 1 ? 'night' : 'nights'}`;
}

function Stay({ unit }: { unit: Unit }) {
    return (
        <>
            <DateText date={unit.arrivalDate} /> →{' '}
            <DateText date={unit.departureDate} />{' '}
            <span className="text-muted-foreground">
                · {nightsText(unit.nights)}
            </span>
        </>
    );
}

/** The boat line under Stay: "In 06:00 boat · Out Own 11:30". Only for a unit in the Set boats list. */
function BoatLine({ boat }: { boat: BoatUnit }) {
    return (
        <span className="flex items-center gap-1.5 text-xs text-muted-foreground tabular-nums">
            <Ship aria-hidden className="size-3.5 shrink-0" />
            In {boat.boatIn?.short ?? 'Not set'} · Out{' '}
            {boat.boatOut?.short ?? 'Not set'}
        </span>
    );
}

/** The add-ons of a unit as one short line: "Cleaning fee, Breakfast × 2". */
function addOnsText(unit: Unit): string | null {
    if (unit.addOns.length === 0) {
        return null;
    }

    return unit.addOns
        .map((addOn) =>
            addOn.quantity > 1
                ? `${addOn.name} × ${addOn.quantity}`
                : addOn.name,
        )
        .join(', ');
}

/**
 * The Units tab of a portal booking: one row per room or bed, with the lead guest, party, stay, boats, rate plan, add-ons,
 * total and status, and the booking total in the footer. The layout follows the staff Units tab's All view, without the
 * room number, which the portal never shows. Below `md` each row is a card.
 */
export function BookingUnitsTable({
    units,
    boats,
    total,
    toolbar,
    currency,
}: {
    units: Unit[];
    /** The units in the Set boats list, by unit id. Empty when the property does not use boats. */
    boats: BoatUnit[];
    /** The booking total from the server, without cancelled rooms. */
    total: string;
    /** Buttons on the right of the toolbar (Set boats). */
    toolbar?: ReactNode;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const boatFor = (unit: Unit) => boats.find((boat) => boat.id === unit.id);
    const roomNights = units.reduce((sum, unit) => sum + unit.nights, 0);
    const summary = `${units.length} ${units.length === 1 ? 'unit' : 'units'} · ${roomNights} room ${roomNights === 1 ? 'night' : 'nights'}`;

    return (
        <div className="flex flex-col gap-4">
            {toolbar && (
                <div className="flex items-center justify-end gap-2">
                    {toolbar}
                </div>
            )}

            <div className="hidden rounded-lg border md:block">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>Room type</TableHead>
                            <TableHead>Guest</TableHead>
                            <TableHead>Pax</TableHead>
                            <TableHead>Stay</TableHead>
                            <TableHead>Rate plan</TableHead>
                            <TableHead className="text-right">Total</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {units.map((unit) => {
                            const boat = boatFor(unit);
                            const addOns = addOnsText(unit);

                            return (
                                <TableRow key={unit.id}>
                                    <TableCell>{unit.roomType}</TableCell>
                                    <TableCell
                                        className={cn(
                                            'max-w-48 truncate',
                                            unit.guestNames.length === 0 &&
                                                'text-muted-foreground',
                                        )}
                                    >
                                        {unit.guestNames[0] ?? 'No guest'}
                                    </TableCell>
                                    <TableCell>
                                        <PaxCount stay={unit} />
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        <span className="flex flex-col gap-0.5">
                                            <span>
                                                <Stay unit={unit} />
                                            </span>
                                            {boat && <BoatLine boat={boat} />}
                                        </span>
                                    </TableCell>
                                    <TableCell className="max-w-48">
                                        <span className="flex flex-col gap-0.5">
                                            <span className="truncate">
                                                {unit.ratePlan}
                                            </span>
                                            {addOns && (
                                                <span className="truncate text-xs text-muted-foreground">
                                                    {addOns}
                                                </span>
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        <MoneyText
                                            amount={unit.total}
                                            currency={currency}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge status={unit.status} />
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                    <TableFooter>
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={5}>{summary}</TableCell>
                            <TableCell className="text-right tabular-nums">
                                <MoneyText amount={total} currency={currency} />
                            </TableCell>
                            <TableCell />
                        </TableRow>
                    </TableFooter>
                </Table>
            </div>

            <ul className="flex flex-col divide-y rounded-lg border md:hidden">
                {units.map((unit) => {
                    const boat = boatFor(unit);
                    const addOns = addOnsText(unit);

                    return (
                        <li
                            key={unit.id}
                            className="flex min-w-0 flex-col gap-1 p-3 text-sm"
                        >
                            <span className="flex items-center justify-between gap-2">
                                <span className="truncate font-medium">
                                    {unit.roomType}
                                </span>
                                <StatusBadge status={unit.status} />
                            </span>
                            <span
                                className={cn(
                                    'truncate',
                                    unit.guestNames.length === 0 &&
                                        'text-muted-foreground',
                                )}
                            >
                                {unit.guestNames[0] ?? 'No guest'}
                            </span>
                            <PaxCount stay={unit} size="sm" />
                            <span className="text-muted-foreground tabular-nums">
                                <Stay unit={unit} />
                            </span>
                            {boat && <BoatLine boat={boat} />}
                            <span className="flex items-center justify-between gap-2">
                                <span className="truncate text-muted-foreground">
                                    {unit.ratePlan}
                                </span>
                                <MoneyText
                                    amount={unit.total}
                                    currency={currency}
                                />
                            </span>
                            {addOns && (
                                <span className="truncate text-xs text-muted-foreground">
                                    {addOns}
                                </span>
                            )}
                        </li>
                    );
                })}
                <li className="flex items-center justify-between gap-2 bg-muted/50 p-3 text-sm font-medium">
                    <span>{summary}</span>
                    <MoneyText amount={total} currency={currency} />
                </li>
            </ul>
        </div>
    );
}
