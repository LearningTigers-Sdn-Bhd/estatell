import {
    Building2,
    LogIn,
    LogOut,
    MoreHorizontal,
    Pencil,
    Plus,
    Printer,
} from 'lucide-react';
import { Link } from '@inertiajs/react';
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
import { bookingSummary, checkOut } from '@/routes/reservations';

/**
 * The page header buttons: Add unit (hidden once the reservation is closed) and More, which holds Edit group, Change company, for a group Check in all and Check out all (the group check-out page), and the booking summary prints (the second one without prices, for a reservation with a company). The prints show on closed reservations too.
 */
export function ReservationActions({
    canManage,
    reservation,
    billingModes,
    addUnitOptions,
    canViewCompany,
    canCheckIn,
    canCheckOutAll,
    canOverrideCredit,
    companies,
    currency,
}: {
    canManage: boolean;
    reservation: App.Data.Reservations.ReservationData;
    billingModes: EnumOption[];
    addUnitOptions?: App.Data.Reservations.AddUnitOptionsData;
    canViewCompany: boolean;
    canCheckIn: boolean;
    canCheckOutAll: boolean;
    canOverrideCredit: boolean;
    companies?: App.Data.CityLedger.CompanyOptionData[];
    currency: App.Enums.Currency | null;
}) {
    const [addingUnit, setAddingUnit] = useState(false);
    const [editingGroup, setEditingGroup] = useState(false);
    const [changingCompany, setChangingCompany] = useState(false);
    const [checkingInAll, setCheckingInAll] = useState(false);
    const canCheckInAll =
        canManage &&
        canCheckIn &&
        !reservation.isClosed &&
        reservation.unitCount > 1;

    return (
        <>
            {canManage && !reservation.isClosed && (
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
                    {canManage && (
                        <DropdownMenuItem onClick={() => setEditingGroup(true)}>
                            <Pencil />
                            Edit group
                        </DropdownMenuItem>
                    )}
                    {canCheckInAll && (
                        <DropdownMenuItem
                            onClick={() => setCheckingInAll(true)}
                        >
                            <LogIn />
                            Check in all
                        </DropdownMenuItem>
                    )}
                    {canCheckOutAll && (
                        <DropdownMenuItem
                            render={
                                <Link
                                    href={checkOut.url({
                                        reservation: reservation.id,
                                    })}
                                />
                            }
                        >
                            <LogOut />
                            Check out all
                        </DropdownMenuItem>
                    )}
                    {canManage && canViewCompany && !reservation.isClosed && (
                        <DropdownMenuItem
                            onClick={() => setChangingCompany(true)}
                        >
                            <Building2 />
                            Change company
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem
                        render={
                            <a
                                href={bookingSummary.url({
                                    reservation: reservation.id,
                                })}
                                target="_blank"
                                rel="noreferrer"
                            />
                        }
                    >
                        <Printer />
                        Print booking summary
                    </DropdownMenuItem>
                    {reservation.companyId !== null && (
                        <DropdownMenuItem
                            render={
                                <a
                                    href={bookingSummary.url(
                                        { reservation: reservation.id },
                                        { query: { prices: 0 } },
                                    )}
                                    target="_blank"
                                    rel="noreferrer"
                                />
                            }
                        >
                            <Printer />
                            Print without prices
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            {canManage && (
                <AddUnitSheet
                    open={addingUnit}
                    onOpenChange={setAddingUnit}
                    reservationId={reservation.id}
                    companyId={reservation.companyId}
                    options={addUnitOptions}
                    currency={currency}
                />
            )}
            {canCheckInAll && (
                <CheckInAllDialog
                    open={checkingInAll}
                    onOpenChange={setCheckingInAll}
                    reservation={reservation}
                />
            )}
            {canManage && (
                <EditGroupDialog
                    open={editingGroup}
                    onOpenChange={setEditingGroup}
                    reservation={reservation}
                    billingModes={billingModes}
                />
            )}
            {canManage && canViewCompany && (
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
