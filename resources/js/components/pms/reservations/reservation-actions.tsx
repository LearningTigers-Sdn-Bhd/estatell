import { MoreHorizontal, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { AddUnitSheet } from '@/components/pms/reservations/add-unit-sheet';
import { EditGroupDialog } from '@/components/pms/reservations/edit-group-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * The page header buttons: Add unit (hidden once the reservation is closed) and More, which holds Edit group.
 */
export function ReservationActions({
    reservation,
    billingModes,
    addUnitOptions,
    currency,
}: {
    reservation: App.Data.Reservations.ReservationData;
    billingModes: EnumOption[];
    addUnitOptions?: App.Data.Reservations.AddUnitOptionsData;
    currency: App.Enums.Currency | null;
}) {
    const [addingUnit, setAddingUnit] = useState(false);
    const [editingGroup, setEditingGroup] = useState(false);

    return (
        <>
            {!reservation.isClosed && (
                <Button onClick={() => setAddingUnit(true)}>
                    <Plus data-icon="inline-start" />
                    Add unit
                </Button>
            )}
            <DropdownMenu>
                <DropdownMenuTrigger
                    render={<Button variant="outline" />}
                    aria-label="More actions"
                >
                    <MoreHorizontal data-icon="inline-start" />
                    More
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem onClick={() => setEditingGroup(true)}>
                        <Pencil />
                        Edit group
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <AddUnitSheet
                open={addingUnit}
                onOpenChange={setAddingUnit}
                reservationId={reservation.id}
                options={addUnitOptions}
                currency={currency}
            />
            <EditGroupDialog
                open={editingGroup}
                onOpenChange={setEditingGroup}
                reservation={reservation}
                billingModes={billingModes}
            />
        </>
    );
}
