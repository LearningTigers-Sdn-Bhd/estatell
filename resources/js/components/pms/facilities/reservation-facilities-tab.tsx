import { Plus } from 'lucide-react';
import { useState } from 'react';
import { BookFacilitySheet } from '@/components/pms/facilities/book-facility-sheet';
import { CancelFacilityBookingDialog } from '@/components/pms/facilities/cancel-facility-booking-dialog';
import { FacilityBookingsTable } from '@/components/pms/facilities/facility-bookings-table';
import { Button } from '@/components/ui/button';

type Booking = App.Data.Facilities.FacilityBookingRowData;

/**
 * Reservation page, Facilities tab: the reservation's facility bookings with Charge to, Book facility and each row's
 * Edit and Cancel. See docs/33-facilities/04-facility-bookings.md#reservation-facilities-tab.
 */
export function ReservationFacilitiesTab({
    reservationId,
    bookings,
    canBook,
    canCreateFolio,
    facilities,
    chargeToOptions,
    defaultChargeTo,
    defaultDate,
    canViewCompany,
    companies,
    currency,
}: {
    reservationId: number;
    bookings: Booking[];
    canBook: boolean;
    canCreateFolio: boolean;
    facilities: App.Data.Facilities.FacilityOptionData[];
    chargeToOptions: App.Data.Facilities.ChargeToOptionData[];
    defaultChargeTo: Record<string, string | null>;
    defaultDate: string;
    canViewCompany: boolean;
    companies?: App.Data.CityLedger.CompanyOptionData[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [booking, setBooking] = useState(false);
    const [editing, setEditing] = useState<Booking | null>(null);
    const [cancelling, setCancelling] = useState<Booking | null>(null);
    const canAdd = canBook && facilities.length > 0;

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between gap-3 ps-3 pt-2">
                <h2 className="font-heading text-base font-medium">
                    Facilities
                </h2>
                {canAdd && (
                    <Button variant="outline" onClick={() => setBooking(true)}>
                        <Plus data-icon="inline-start" />
                        Book facility
                    </Button>
                )}
            </div>

            <FacilityBookingsTable
                bookings={bookings}
                currency={currency}
                emptyDescription="Book the hall, a BBQ pit or an activity for this reservation."
                showChargeTo
                onEdit={setEditing}
                onCancel={setCancelling}
            />

            {canBook && (
                <BookFacilitySheet
                    open={booking || editing !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setBooking(false);
                            setEditing(null);
                        }
                    }}
                    owner={{
                        type: 'reservation',
                        id: reservationId,
                        defaultDate,
                        chargeToOptions,
                        defaultChargeTo,
                        canCreateFolio,
                        canViewCompany,
                        companies,
                    }}
                    facilities={facilities}
                    booking={editing ?? undefined}
                    currency={currency}
                />
            )}

            <CancelFacilityBookingDialog
                booking={cancelling}
                onClose={() => setCancelling(null)}
            />
        </div>
    );
}
