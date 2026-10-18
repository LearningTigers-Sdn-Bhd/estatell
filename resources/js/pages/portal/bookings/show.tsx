import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { BookingCancelActions } from '@/components/pms/portal/booking-cancel-actions';
import { BookingGuestsSheet } from '@/components/pms/portal/booking-guests-sheet';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { index, show } from '@/routes/portal/bookings';

type Booking = App.Data.Portal.PortalBookingData;

export default function PortalBookingShow({
    booking,
    propertyCode,
    companySuspended,
    idTypes,
    currency,
}: {
    booking: Booking;
    propertyCode: string;
    companySuspended: boolean;
    idTypes: EnumOption[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [editing, setEditing] = useState(false);
    const [saves, setSaves] = useState(0);

    return (
        <>
            <Head title={booking.reference} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={booking.reference}
                    description={booking.title}
                    back={{
                        href: index({ property: propertyCode }),
                        label: 'Bookings',
                    }}
                    badge={<StatusBadge status={booking.status} />}
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

                {booking.cancelMode === 'free' && booking.freeUntil && (
                    <p className="text-sm text-muted-foreground">
                        Free cancellation until{' '}
                        <DateText date={booking.freeUntil} />.
                    </p>
                )}

                <dl className="grid max-w-3xl gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Fact label="Stay">
                        <DateText date={booking.firstArrival} /> →{' '}
                        <DateText date={booking.lastDeparture} />
                    </Fact>
                    <Fact label="Booker">{booking.bookerName}</Fact>
                    <Fact label="Your reference">
                        {booking.externalReference ?? '—'}
                    </Fact>
                    <Fact label="Total">
                        <MoneyText amount={booking.total} currency={currency} />
                    </Fact>
                </dl>

                <section className="flex flex-col gap-3">
                    <h2 className="text-base font-medium">Rooms</h2>
                    <ul className="flex flex-col gap-3">
                        {booking.units.map((unit) => (
                            <li
                                key={unit.id}
                                className="flex flex-col gap-2 rounded-lg border p-3"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {unit.roomType}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {unit.ratePlan}
                                        </span>
                                        <StatusBadge status={unit.status} />
                                    </span>
                                    <MoneyText
                                        amount={unit.total}
                                        currency={currency}
                                    />
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    <DateText date={unit.arrivalDate} /> →{' '}
                                    <DateText date={unit.departureDate} />,{' '}
                                    {unit.nights}{' '}
                                    {unit.nights === 1 ? 'night' : 'nights'},{' '}
                                    {unit.adults}{' '}
                                    {unit.adults === 1 ? 'adult' : 'adults'}
                                    {unit.children > 0 &&
                                        `, ${unit.children} ${unit.children === 1 ? 'child' : 'children'}`}
                                </p>
                                {unit.guestNames.length > 0 && (
                                    <p className="text-sm">
                                        Guest: {unit.guestNames.join(', ')}
                                    </p>
                                )}
                                {unit.addOns.length > 0 && (
                                    <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
                                        {unit.addOns.map((addOn, position) => (
                                            <li
                                                key={position}
                                                className="flex justify-between gap-2"
                                            >
                                                <span>
                                                    {addOn.name}
                                                    {addOn.quantity > 1 &&
                                                        ` × ${addOn.quantity}`}
                                                </span>
                                                <MoneyText
                                                    amount={addOn.total}
                                                    currency={currency}
                                                />
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-base font-medium">Guests</h2>
                        {booking.canEditGuests && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditing(true)}
                            >
                                Edit guests
                            </Button>
                        )}
                    </div>
                    <ul className="flex flex-col gap-1 text-sm">
                        {booking.guests.map((guest) => (
                            <li key={guest.guestId}>
                                <span className="font-medium">
                                    {guest.fullName}
                                </span>
                                <span className="text-muted-foreground">
                                    {guest.phone && `, ${guest.phone}`}
                                    {guest.idTypeLabel &&
                                        `, ${guest.idTypeLabel} on file`}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <p className="text-sm text-muted-foreground">
                        Changes to dates, rooms or only some rooms go through
                        the property.
                    </p>
                </section>

                {booking.canEditGuests && (
                    <BookingGuestsSheet
                        key={saves}
                        open={editing}
                        onOpenChange={setEditing}
                        propertyCode={propertyCode}
                        reservationId={booking.id}
                        guests={booking.guests}
                        idTypes={idTypes}
                        onSaved={() => setSaves((count) => count + 1)}
                    />
                )}
            </div>
        </>
    );
}

function Fact({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1 rounded-lg border p-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{children}</dd>
        </div>
    );
}

PortalBookingShow.layout = (props: {
    propertyCode: string;
    booking: Booking;
}) => ({
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
