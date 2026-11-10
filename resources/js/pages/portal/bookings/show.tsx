import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { BedDouble, Users, Wallet } from 'lucide-react';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { BookingBoatsDialog } from '@/components/pms/portal/booking-boats-dialog';
import { BookingCancelActions } from '@/components/pms/portal/booking-cancel-actions';
import { BookingGuestsSheet } from '@/components/pms/portal/booking-guests-sheet';
import { BookingGuestsTab } from '@/components/pms/portal/booking-guests-tab';
import { BookingPaymentSection } from '@/components/pms/portal/booking-payment-section';
import { BookingSummaryCard } from '@/components/pms/portal/booking-summary-card';
import { BookingUnitsTable } from '@/components/pms/portal/booking-units-table';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { TabLinks } from '@/components/pms/tab-links';
import { Workspace } from '@/components/pms/workspace';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    guests as guestsTab,
    index,
    payments as paymentsTab,
    show,
} from '@/routes/portal/bookings';

type Booking = App.Data.Portal.PortalBookingData;

type Tab = 'units' | 'guests' | 'payments';

/**
 * One booking of the company, laid out like the staff reservation page: a header, link tabs (Units, Guests, Payments)
 * and a summary card on the side. The server sends only the open tab's data.
 */
export default function PortalBookingShow({
    tab,
    booking,
    propertyCode,
    companySuspended,
    currency,
    paymentSchedule,
    agentBill,
    boatDepartures = [],
    boatSetup = [],
    idTypes = [],
    countries = [],
    paymentProofs = [],
}: {
    tab: Tab;
    booking: Booking;
    propertyCode: string;
    companySuspended: boolean;
    currency: App.Data.Property.CurrencyData | null;
    paymentSchedule: App.Data.Reservations.PaymentScheduleData | null;
    agentBill: App.Data.Reservations.AgentBillData;
    /** Units tab only. */
    boatDepartures?: App.Data.Boats.BoatDepartureOptionData[];
    /** Units tab only. */
    boatSetup?: App.Data.Boats.BoatSetupUnitData[];
    /** Guests tab only. */
    idTypes?: EnumOption[];
    /** Guests tab only. */
    countries?: EnumOption[];
    /** Payments tab only. */
    paymentProofs?: App.Data.Payments.PaymentProofData[];
}) {
    const [editing, setEditing] = useState(false);
    const [editingBoats, setEditingBoats] = useState(false);
    const boatFormKey = useFreshOnOpen(editingBoats);
    const canChangeBoats = boatSetup.some(
        (unit) => unit.canSetBoatIn || unit.canSetBoatOut,
    );
    const [saves, setSaves] = useState(0);
    const key = { property: propertyCode, reservation: booking.id };

    const tabs = [
        {
            value: 'units',
            label: 'Units',
            icon: BedDouble,
            href: show.url(key),
        },
        {
            value: 'guests',
            label: 'Guests',
            icon: Users,
            href: guestsTab.url(key),
        },
        {
            value: 'payments',
            label: 'Payments',
            icon: Wallet,
            href: paymentsTab.url(key),
        },
    ];

    return (
        <>
            <Head title={booking.reference} />

            <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 lg:overflow-hidden">
                <PageHeader
                    title={booking.title}
                    description={
                        <span className="flex flex-col gap-1">
                            <span className="flex flex-wrap items-center gap-x-2">
                                <span>
                                    Ref:{' '}
                                    <strong className="font-semibold text-foreground">
                                        {booking.reference}
                                    </strong>
                                </span>
                                {booking.externalReference && (
                                    <span>
                                        · Your ref:{' '}
                                        <strong className="font-semibold text-foreground">
                                            {booking.externalReference}
                                        </strong>
                                    </span>
                                )}
                            </span>
                            <span className="flex flex-wrap items-center gap-x-2">
                                <DateText date={booking.firstArrival} /> →{' '}
                                <DateText date={booking.lastDeparture} />
                                <span>
                                    · {booking.units.length}{' '}
                                    {booking.units.length === 1
                                        ? 'unit'
                                        : 'units'}
                                </span>
                            </span>
                        </span>
                    }
                    badge={<StatusBadge status={booking.status} />}
                    back={{
                        href: index({ property: propertyCode }),
                        label: 'Bookings',
                    }}
                    actions={
                        <BookingCancelActions
                            propertyCode={propertyCode}
                            booking={booking}
                        />
                    }
                />

                {companySuspended && (
                    <Alert>
                        <AlertDescription>
                            Your company is suspended at this property. You can
                            read your bookings but not change or cancel them.
                            Contact the property.
                        </AlertDescription>
                    </Alert>
                )}

                <TabLinks
                    tabs={tabs}
                    activeTab={tab}
                    panelWidth="full"
                    className="flex flex-col gap-4 lg:min-h-0 lg:flex-1"
                    panelClassName="flex flex-col lg:min-h-0 lg:flex-1"
                >
                    <Workspace
                        asideLabel="Booking summary"
                        aside={
                            <BookingSummaryCard
                                booking={booking}
                                bill={agentBill}
                                schedule={paymentSchedule}
                                currency={currency}
                            />
                        }
                    >
                        {tab === 'guests' ? (
                            <BookingGuestsTab
                                booking={booking}
                                countries={countries}
                                toolbar={
                                    booking.canEditGuests && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setEditing(true)}
                                        >
                                            Edit guests
                                        </Button>
                                    )
                                }
                            />
                        ) : tab === 'payments' ? (
                            <BookingPaymentSection
                                propertyCode={propertyCode}
                                reservationId={booking.id}
                                schedule={paymentSchedule}
                                bill={agentBill}
                                proofs={paymentProofs}
                                canUpload={
                                    !companySuspended &&
                                    paymentSchedule !== null &&
                                    paymentSchedule.stages.some(
                                        (stage) => stage.status !== 'paid',
                                    )
                                }
                                currency={currency}
                            />
                        ) : (
                            <BookingUnitsTable
                                units={booking.units}
                                boats={boatSetup}
                                total={booking.total}
                                currency={currency}
                                toolbar={
                                    canChangeBoats && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                setEditingBoats(true)
                                            }
                                        >
                                            Set boats
                                        </Button>
                                    )
                                }
                            />
                        )}
                    </Workspace>
                </TabLinks>

                {tab === 'units' && boatSetup.length > 0 && (
                    <BookingBoatsDialog
                        key={boatFormKey}
                        open={editingBoats}
                        onOpenChange={setEditingBoats}
                        propertyCode={propertyCode}
                        reservationId={booking.id}
                        units={boatSetup}
                        departures={boatDepartures}
                    />
                )}

                {tab === 'guests' && booking.canEditGuests && (
                    <BookingGuestsSheet
                        key={saves}
                        open={editing}
                        onOpenChange={setEditing}
                        propertyCode={propertyCode}
                        reservationId={booking.id}
                        guests={booking.guests}
                        idTypes={idTypes}
                        countries={countries}
                        onSaved={() => setSaves((count) => count + 1)}
                    />
                )}
            </div>
        </>
    );
}

PortalBookingShow.layout = (props: {
    propertyCode: string;
    booking: Booking;
}) => ({
    variant: 'fixed',
    breadcrumbs: [
        { title: 'Bookings', href: index({ property: props.propertyCode }) },
        {
            title: props.booking.reference,
            href: show({
                property: props.propertyCode,
                reservation: props.booking.id,
            }),
        },
    ],
});
