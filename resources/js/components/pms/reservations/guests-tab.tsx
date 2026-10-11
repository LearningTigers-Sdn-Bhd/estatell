import { Link } from '@inertiajs/react';
import { BookUser, IdCard, MoreHorizontal, UserPlus } from 'lucide-react';
import { Fragment, useState } from 'react';
import { AttachGuestDialog } from '@/components/pms/reservations/attach-guest-dialog';
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { show as showGuest } from '@/routes/guests';
import { show as showCard } from '@/routes/registration';

type Row = App.Data.Reservations.ReservationGuestData;
type Unit = App.Data.Reservations.ReservationUnitOptionData;

const OPEN = ['confirmed', 'checked_in'];
const ATTACHABLE = [...OPEN, 'checked_out'];

function RoleBadge({ role }: { role: string }) {
    if (role === 'Booker') {
        return (
            <Badge variant="secondary" className="bg-info/10 text-info">
                <BookUser aria-hidden />
                Booker
            </Badge>
        );
    }

    return (
        <Badge variant={role === 'Primary' ? 'default' : 'secondary'}>
            {role}
        </Badge>
    );
}

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
    const openUnits = units.filter((unit) =>
        ATTACHABLE.includes(unit.status.value),
    );
    const canAttach = canManage && openUnits.length > 0;

    const attach = (forUnit: number | null) => {
        setUnitId(forUnit);
        setAttaching(true);
    };

    const groups = [...Map.groupBy(guests, (row) => row.unitId).values()];

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between gap-3 ps-3 pt-2">
                <h2 className="font-heading text-base font-medium">Guests</h2>
                {canAttach && (
                    <Button variant="outline" onClick={() => attach(null)}>
                        <UserPlus data-icon="inline-start" />
                        Attach guest
                    </Button>
                )}
            </div>
            <div className="overflow-hidden rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Guest</TableHead>
                            <TableHead>Phone</TableHead>
                            <TableHead>Role</TableHead>
                            <TableHead>
                                <span className="sr-only">Card</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {groups.map((rows) => {
                            const first = rows[0];

                            return (
                                <Fragment key={first.unitId ?? 'not-staying'}>
                                    <TableRow className="bg-muted/50 hover:bg-muted/50">
                                        <TableCell
                                            colSpan={4}
                                            className="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                        >
                                            <span className="flex items-center gap-2">
                                                {first.unitLabel ??
                                                    'Not staying'}
                                                {first.unitStatus &&
                                                    !OPEN.includes(
                                                        first.unitStatus.value,
                                                    ) && (
                                                        <StatusBadge
                                                            status={
                                                                first.unitStatus
                                                            }
                                                        />
                                                    )}
                                            </span>
                                        </TableCell>
                                    </TableRow>
                                    {rows.map((row, index) => (
                                        <TableRow key={index}>
                                            <TableCell>
                                                {row.name === null ? (
                                                    <span className="text-muted-foreground">
                                                        No guest yet
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
                                                {row.phone ?? ''}
                                            </TableCell>
                                            <TableCell>
                                                {row.name === null ? (
                                                    canAttach &&
                                                    row.unitId !== null &&
                                                    row.unitStatus !== null &&
                                                    ATTACHABLE.includes(
                                                        row.unitStatus.value,
                                                    ) && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() =>
                                                                attach(
                                                                    row.unitId,
                                                                )
                                                            }
                                                        >
                                                            Attach guest
                                                        </Button>
                                                    )
                                                ) : (
                                                    <span className="flex flex-wrap gap-1">
                                                        {row.roles.length ===
                                                        0 ? (
                                                            <Badge variant="outline">
                                                                Guest
                                                            </Badge>
                                                        ) : (
                                                            row.roles.map(
                                                                (role) => (
                                                                    <RoleBadge
                                                                        key={
                                                                            role
                                                                        }
                                                                        role={
                                                                            role
                                                                        }
                                                                    />
                                                                ),
                                                            )
                                                        )}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {row.cardUnitGuestId !== null &&
                                                    row.unitStatus !== null &&
                                                    OPEN.includes(
                                                        row.unitStatus.value,
                                                    ) && (
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger
                                                                render={
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        aria-label={`Actions for ${row.name}`}
                                                                    />
                                                                }
                                                            >
                                                                <MoreHorizontal />
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end">
                                                                <DropdownMenuItem
                                                                    render={
                                                                        <Link
                                                                            href={showCard.url(
                                                                                {
                                                                                    unitGuest:
                                                                                        row.cardUnitGuestId,
                                                                                },
                                                                            )}
                                                                        />
                                                                    }
                                                                >
                                                                    <IdCard />
                                                                    Registration
                                                                    card
                                                                </DropdownMenuItem>
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </Fragment>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
            <AttachGuestDialog
                open={attaching}
                onOpenChange={setAttaching}
                reservationId={reservationId}
                units={openUnits}
                initialUnitId={unitId}
            />
        </div>
    );
}
