import {
    Building2,
    LogIn,
    LogOut,
    MoreHorizontal,
    Pencil,
    Plus,
    Printer,
    ReceiptText,
    Ship,
    XCircle,
} from 'lucide-react';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { AddUnitSheet } from '@/components/pms/reservations/add-unit-sheet';
import { CheckInAllDialog } from '@/components/pms/reservations/check-in-all-dialog';
import { CancelReservationDialog } from '@/components/pms/reservations/cancel-reservation-dialog';
import { ChangeCompanyDialog } from '@/components/pms/reservations/change-company-dialog';
import { SetBoatsDialog } from '@/components/pms/reservations/set-boats-dialog';
import { UnitActionDialogs } from '@/components/pms/reservations/unit-actions-menu';
import { EditGroupDialog } from '@/components/pms/reservations/edit-group-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { bookingSummary, checkOut } from '@/routes/reservations';
import { receipts } from '@/routes/reservations/check-out';

/**
 * The page header buttons: Add unit (hidden once the reservation is closed) and More, which holds Edit group, Change company, for a group Check in all and Check out all (the group check-out page), the booking summary prints (the second one without prices, for a reservation with a company) and Check in and Check out of the one unit of a single-unit reservation (the unit menu's own Dialogs), Cancel reservation (one Dialog for several units, with `reservations.manage` while a unit is Confirmed) and Print receipts (one PDF with every receipt of the reservation; the server sends the payment ids only with payment.record). The prints show on closed reservations too.
 */
export function ReservationActions({
    canManage,
    reservation,
    billingModes,
    addUnitOptions,
    canViewCompany,
    canCheckIn,
    canCheckOutAll,
    canCancelReservation,
    hasUnitToCheckIn,
    singleUnit,
    canWaiveFees,
    canOverrideRestrictions,
    paymentOptions,
    receiptPaymentIds,
    canOverrideCredit,
    companies,
    currency,
    hasBoats,
    boatSetup,
    boatDepartures,
}: {
    canManage: boolean;
    reservation: App.Data.Reservations.ReservationData;
    billingModes: EnumOption[];
    addUnitOptions?: App.Data.Reservations.AddUnitOptionsData;
    canViewCompany: boolean;
    canCheckIn: boolean;
    canCheckOutAll: boolean;
    canCancelReservation: boolean;
    hasUnitToCheckIn: boolean;
    singleUnit: App.Data.Reservations.ReservationUnitData | null;
    canWaiveFees: boolean;
    canOverrideRestrictions: boolean;
    paymentOptions?: App.Data.Payments.PaymentFormOptionsData;
    receiptPaymentIds: number[];
    canOverrideCredit: boolean;
    companies?: App.Data.CityLedger.CompanyOptionData[];
    currency: App.Data.Property.CurrencyData | null;
    hasBoats: boolean;
    boatSetup?: App.Data.Boats.BoatSetupUnitData[];
    boatDepartures?: App.Data.Boats.BoatDepartureOptionData[];
}) {
    const [addingUnit, setAddingUnit] = useState(false);
    const [editingGroup, setEditingGroup] = useState(false);
    const [changingCompany, setChangingCompany] = useState(false);
    const [checkingInAll, setCheckingInAll] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [settingBoats, setSettingBoats] = useState(false);
    const [unitAction, setUnitAction] = useState<App.Enums.UnitAction | null>(
        null,
    );
    const [lastUnitAction, setLastUnitAction] =
        useState<App.Enums.UnitAction>('check-in');
    const chooseUnitAction = (next: App.Enums.UnitAction) => {
        setUnitAction(next);
        setLastUnitAction(next);
    };
    // A single-unit reservation gets the unit's own Check in and Check out here, whenever its menu offers them.
    const singleUnitItems = (singleUnit?.actions ?? []).filter(
        (item) => item.key === 'check-in' || item.key === 'check-out',
    );
    const canCheckInAll =
        canManage &&
        canCheckIn &&
        !reservation.isClosed &&
        hasUnitToCheckIn &&
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
                <DropdownMenuContent align="end" className="min-w-56">
                    {singleUnitItems.map((item) => (
                        <DropdownMenuItem
                            key={item.key}
                            onClick={() => chooseUnitAction(item.key)}
                        >
                            {item.key === 'check-in' ? <LogIn /> : <LogOut />}
                            {item.label}
                        </DropdownMenuItem>
                    ))}
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
                    {canManage && hasBoats && !reservation.isClosed && (
                        <DropdownMenuItem onClick={() => setSettingBoats(true)}>
                            <Ship />
                            Set boats
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
                    {canCancelReservation && (
                        <DropdownMenuItem
                            variant="destructive"
                            onClick={() => setCancelling(true)}
                        >
                            <XCircle />
                            Cancel reservation
                        </DropdownMenuItem>
                    )}
                    {receiptPaymentIds.length > 0 && (
                        <DropdownMenuItem
                            render={
                                <a
                                    href={receipts.url(
                                        { reservation: reservation.id },
                                        {
                                            query: {
                                                payments: receiptPaymentIds,
                                            },
                                        },
                                    )}
                                    target="_blank"
                                    rel="noreferrer"
                                />
                            }
                        >
                            <ReceiptText />
                            Print receipts
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
                    stayType={reservation.stayType}
                />
            )}
            {singleUnit !== null && singleUnitItems.length > 0 && (
                <UnitActionDialogs
                    reservationId={reservation.id}
                    unit={singleUnit}
                    canWaiveFees={canWaiveFees}
                    canOverrideRestrictions={canOverrideRestrictions}
                    paymentOptions={paymentOptions}
                    currency={currency}
                    action={unitAction}
                    lastAction={lastUnitAction}
                    onChoose={chooseUnitAction}
                    onClose={() => setUnitAction(null)}
                />
            )}
            {canCheckInAll && (
                <CheckInAllDialog
                    open={checkingInAll}
                    onOpenChange={setCheckingInAll}
                    reservation={reservation}
                />
            )}
            {canCancelReservation && (
                <CancelReservationDialog
                    open={cancelling}
                    onOpenChange={setCancelling}
                    reservation={reservation}
                    canWaiveFees={canWaiveFees}
                    currency={currency}
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
            {canManage && hasBoats && (
                <SetBoatsDialog
                    open={settingBoats}
                    onOpenChange={setSettingBoats}
                    reservationId={reservation.id}
                    units={boatSetup}
                    departures={boatDepartures}
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
