import { Link } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { move as moveCharges } from '@/routes/reservations/folio';

type Row = App.Data.Reservations.GroupCheckOutRowData;

/**
 * A section of rooms that cannot be checked out here: Departing later (with **Check out early…**) and Can't check out
 * yet (with **Move charges** for a user who may move them). Muted rows, no checkbox, outside the tables.
 */
export function GroupCheckOutMutedList({
    heading,
    rows,
    reservationId,
    onCheckOutEarly,
}: {
    heading: string;
    rows: Row[];
    reservationId: number;
    onCheckOutEarly: (row: Row) => void;
}) {
    return (
        <section className="flex flex-col gap-2">
            <h2 className="text-sm font-medium">
                {heading} ({rows.length})
            </h2>
            <div className="overflow-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-24">Room</TableHead>
                            <TableHead className="w-48">Guest</TableHead>
                            <TableHead>Why</TableHead>
                            <TableHead className="text-right">
                                <span className="sr-only">Action</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow
                                key={row.unitId}
                                className="text-muted-foreground"
                            >
                                <TableCell className="font-medium">
                                    {row.label}
                                </TableCell>
                                <TableCell>
                                    {row.guest ?? 'No primary guest'}
                                </TableCell>
                                <TableCell className="whitespace-normal">
                                    {row.note}
                                </TableCell>
                                <TableCell className="text-right">
                                    {row.unit !== null && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => onCheckOutEarly(row)}
                                        >
                                            <LogOut data-icon="inline-start" />
                                            Check out early…
                                        </Button>
                                    )}
                                    {row.canMoveCharges &&
                                        row.incoming.length > 0 && (
                                            <Link
                                                href={moveCharges.url({
                                                    reservation: reservationId,
                                                })}
                                                className={buttonVariants({
                                                    variant: 'outline',
                                                    size: 'sm',
                                                })}
                                            >
                                                Move charges
                                            </Link>
                                        )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </section>
    );
}
