import { Link } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { useState } from 'react';
import { AttachGuestSheet } from '@/components/pms/reservations/attach-guest-sheet';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { show as showGuest } from '@/routes/guests';

type Row = App.Data.Reservations.ReservationGuestData;
type Unit = App.Data.Reservations.ReservationUnitOptionData;

const OPEN = ['confirmed', 'checked_in'];

/**
 * The Guests tab: each guest on a unit with their roles, the booker when they do not stay, and units with no guest.
 */
export function GuestsTab({
    reservationId,
    guests,
    units,
    canManage,
}: {
    reservationId: number;
    guests: Row[];
    units: Unit[];
    canManage: boolean;
}) {
    const [attaching, setAttaching] = useState(false);
    const [unitId, setUnitId] = useState<number | null>(null);
    const openUnits = units.filter((unit) => OPEN.includes(unit.status.value));
    const canAttach = canManage && openUnits.length > 0;

    const attach = (forUnit: number | null) => {
        setUnitId(forUnit);
        setAttaching(true);
    };

    return (
        <div className="flex flex-col gap-4">
            {canAttach && (
                <div className="flex justify-end">
                    <Button variant="outline" onClick={() => attach(null)}>
                        <UserPlus data-icon="inline-start" />
                        Attach guest
                    </Button>
                </div>
            )}
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Guest</TableHead>
                        <TableHead>Phone</TableHead>
                        <TableHead>Unit</TableHead>
                        <TableHead>Role</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {guests.map((row, index) => (
                        <TableRow key={index}>
                            <TableCell>
                                {row.name === null ? (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                ) : row.guestId !== null ? (
                                    <Link
                                        href={showGuest.url({
                                            guest: row.guestId,
                                        })}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {row.name}
                                    </Link>
                                ) : (
                                    row.name
                                )}
                            </TableCell>
                            <TableCell className="tabular-nums">
                                {row.phone ?? '—'}
                            </TableCell>
                            <TableCell>
                                {row.unitLabel === null ? (
                                    <span className="text-muted-foreground">
                                        Not staying
                                    </span>
                                ) : (
                                    <span className="flex items-center gap-2">
                                        {row.unitLabel}
                                        {row.unitStatus &&
                                            !OPEN.includes(
                                                row.unitStatus.value,
                                            ) && (
                                                <StatusBadge
                                                    status={row.unitStatus}
                                                />
                                            )}
                                    </span>
                                )}
                            </TableCell>
                            <TableCell>
                                {row.roles.length > 0 ? (
                                    row.roles.join(' · ')
                                ) : row.name === null ? (
                                    canAttach &&
                                    row.unitId !== null &&
                                    row.unitStatus !== null &&
                                    OPEN.includes(row.unitStatus.value) ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => attach(row.unitId)}
                                        >
                                            Attach guest
                                        </Button>
                                    ) : (
                                        'No guest yet'
                                    )
                                ) : (
                                    '—'
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
            <AttachGuestSheet
                open={attaching}
                onOpenChange={setAttaching}
                reservationId={reservationId}
                units={openUnits}
                initialUnitId={unitId}
            />
        </div>
    );
}
