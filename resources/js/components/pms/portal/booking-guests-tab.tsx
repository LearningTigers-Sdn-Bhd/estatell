import type { ReactNode } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { FieldDescription } from '@/components/ui/field';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { guestRows } from '@/components/pms/portal/booking-guest-rows';

type Booking = App.Data.Portal.PortalBookingData;

/**
 * The Guests tab of a portal booking: every guest under the room type they stay in, with phone, nationality and the ID
 * on file. The layout follows the staff Guests tab, read only; Edit guests in the toolbar opens the edit Sheet. Below
 * `md` each guest is a card.
 */
export function BookingGuestsTab({
    booking,
    countries,
    toolbar,
}: {
    booking: Booking;
    countries: EnumOption[];
    /** Buttons on the right of the toolbar (Edit guests). */
    toolbar?: ReactNode;
}) {
    const rows = guestRows(booking);
    const country = (code: string | null) =>
        code === null
            ? null
            : (countries.find((option) => option.value === code)?.label ??
              code);

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
                            <TableHead>Stays in</TableHead>
                            <TableHead>Name</TableHead>
                            <TableHead>Phone</TableHead>
                            <TableHead>Nationality</TableHead>
                            <TableHead>ID</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={row.key}>
                                <TableCell
                                    className={cn(
                                        row.unit === null &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    {row.unit ?? 'Booker, not staying'}
                                </TableCell>
                                <TableCell className="max-w-48 truncate font-medium">
                                    {row.guest.fullName}
                                </TableCell>
                                <Muted value={row.guest.phone} />
                                <Muted
                                    value={country(row.guest.nationalityCode)}
                                />
                                <Muted
                                    value={
                                        row.guest.idTypeLabel &&
                                        `${row.guest.idTypeLabel} on file`
                                    }
                                />
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            <ul className="flex flex-col divide-y rounded-lg border md:hidden">
                {rows.map((row) => (
                    <li
                        key={row.key}
                        className="flex min-w-0 flex-col gap-1 p-3 text-sm"
                    >
                        <span className="truncate font-medium">
                            {row.guest.fullName}
                        </span>
                        <span className="text-muted-foreground">
                            {row.unit ?? 'Booker, not staying'}
                        </span>
                        <span className="text-muted-foreground">
                            {[
                                row.guest.phone,
                                country(row.guest.nationalityCode),
                                row.guest.idTypeLabel &&
                                    `${row.guest.idTypeLabel} on file`,
                            ]
                                .filter(Boolean)
                                .join(' · ') || 'No details yet'}
                        </span>
                    </li>
                ))}
            </ul>

            <FieldDescription>
                Changes to dates, rooms or only some rooms go through the
                property.
            </FieldDescription>
        </div>
    );
}

function Muted({ value }: { value: string | null | undefined }) {
    return (
        <TableCell className={cn(!value && 'text-muted-foreground')}>
            {value || '—'}
        </TableCell>
    );
}
