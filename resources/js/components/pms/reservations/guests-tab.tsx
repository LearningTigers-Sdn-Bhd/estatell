import { Link } from '@inertiajs/react';
import { StatusBadge } from '@/components/pms/status-badge';
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

const OPEN = ['confirmed', 'checked_in'];

/**
 * The Guests tab: each guest on a unit with their roles, the booker when they do not stay, and units with no guest.
 */
export function GuestsTab({ guests }: { guests: Row[] }) {
    return (
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
                                <span className="text-muted-foreground">—</span>
                            ) : row.guestId !== null ? (
                                <Link
                                    href={showGuest.url({ guest: row.guestId })}
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
                            {row.roles.length > 0
                                ? row.roles.join(' · ')
                                : row.name === null
                                  ? 'No guest yet'
                                  : '—'}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
