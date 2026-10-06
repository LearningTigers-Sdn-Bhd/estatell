import { Building2, LogIn, MoreHorizontal, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { AddUnitSheet } from '@/components/pms/reservations/add-unit-sheet';
import { CheckInAllDialog } from '@/components/pms/reservations/check-in-all-dialog';
import { ChangeCompanyDialog } from '@/components/pms/reservations/change-company-dialog';
import { EditGroupDialog } from '@/components/pms/reservations/edit-group-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * The page header buttons: Add unit (hidden once the reservation is closed) and More, which holds Edit group, Change company and, for a group, Check in all.
 */
export function ReservationActions({
    reservation,
    billingModes,
    addUnitOptions,
    canViewCompany,
    canCheckIn,
    canOverrideCredit,
    companies,
    currency,
}: {
    reservation: App.Data.Reservations.ReservationData;
    billingModes: EnumOption[];
    addUnitOptions?: App.Data.Reservations.AddUnitOptionsData;
    canViewCompany: boolean;
    canCheckIn: boolean;
    canOverrideCredit: boolean;
    companies?: App.Data.CityLedger.CompanyOptionData[];
    currency: App.Enums.Currency | null;
}) {
    const [addingUnit, setAddingUnit] = useState(false);
    const [editingGroup, setEditingGroup] = useState(false);
    const [changingCompany, setChangingCompany] = useState(false);
    const [checkingInAll, setCheckingInAll] = useState(false);
    const canCheckInAll =
        canCheckIn && !reservation.isClosed && reservation.unitCount > 1;

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
                    {canCheckInAll && (
                        <DropdownMenuItem
                            onClick={() => setCheckingInAll(true)}
                        >
                            <LogIn />
                            Check in all
                        </DropdownMenuItem>
                    )}
                    {canViewCompany && !reservation.isClosed && (
                        <DropdownMenuItem
                            onClick={() => setChangingCompany(true)}
                        >
                            <Building2 />
                            Change company
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            <AddUnitSheet
                open={addingUnit}
                onOpenChange={setAddingUnit}
                reservationId={reservation.id}
                companyId={reservation.companyId}
                options={addUnitOptions}
                currency={currency}
            />
            {canCheckInAll && (
                <CheckInAllDialog
                    open={checkingInAll}
                    onOpenChange={setCheckingInAll}
                    reservation={reservation}
                />
            )}
            <EditGroupDialog
                open={editingGroup}
                onOpenChange={setEditingGroup}
                reservation={reservation}
                billingModes={billingModes}
            />
            {canViewCompany && (
                <ChangeCompanyDialog
                    open={changingCompany}
                    onOpenChange={setChangingCompany}
                    reservation={reservation}
                    companies={companies}
                    canOverrideCredit={canOverrideCredit}
                />
            )}
        </>
    );
}
