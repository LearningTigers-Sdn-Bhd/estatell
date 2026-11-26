import { useState } from 'react';
import { BedDouble } from 'lucide-react';
import {
    IdentityStep,
    ReturningCheck,
} from '@/components/concierge/identity-step';
import { GuestFrame } from '@/components/concierge/stay-menu';
import type { EnumOption } from '@/components/pms/enum-select';
import { walkIn } from '@/routes/concierge';
import { book, returning } from '@/routes/concierge/walk-in';

type Booking = {
    adults: number;
    stay_type: 'overnight' | 'day_use';
    nights: number | null;
    start_time: string | null;
    hours: number | null;
    room_type_id: number;
};

function money(amount: string, currency: string): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    }).format(Number(amount));
}

/**
 * Where a walk-in gives their details for the room they picked. Nothing is booked until they send them: the room and the details
 * go together, so a booking never exists without the guest's name. A returning guest can give an email or phone instead.
 */
export default function ConciergeWalkInDetails({
    property,
    offer,
    booking,
    nationalityRequired,
    idPhotoRequired,
    identityKinds,
    genders,
    countries,
}: {
    property: App.Data.Property.PropertySummaryData;
    offer: App.Data.Concierge.ConciergeOfferData;
    booking: Booking;
    nationalityRequired: boolean;
    idPhotoRequired: boolean;
    identityKinds: EnumOption[];
    genders: EnumOption[];
    countries: EnumOption[];
}) {
    // A guest is first asked to be recognised as a returning guest; what they type carries into the full form.
    const [showFull, setShowFull] = useState(false);
    const [contact, setContact] = useState('');

    const dayUse = booking.stay_type === 'day_use';
    const length = dayUse
        ? `${booking.hours} ${booking.hours === 1 ? 'hour' : 'hours'}`
        : `${booking.nights} ${booking.nights === 1 ? 'night' : 'nights'}`;
    const { room_type_id: _room, ...search } = booking;
    const searchQuery = Object.fromEntries(
        Object.entries(search).filter(([, value]) => value !== null),
    ) as Record<string, string | number>;

    return (
        <GuestFrame
            back={{
                href: walkIn.url(
                    { property: property.code },
                    { query: searchQuery },
                ),
                label: 'Choose another room',
            }}
            title="Your details"
            description="Tell us who is staying. Your room is booked when you continue, then you pay."
        >
            <section
                className="flex items-center gap-4 rounded-2xl border border-border/40 bg-card p-5"
                aria-label="Your room"
            >
                <span className="flex size-12 shrink-0 items-center justify-center rounded-full bg-muted/60 text-primary">
                    <BedDouble
                        className="size-7"
                        strokeWidth={1.5}
                        aria-hidden
                    />
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="text-lg font-semibold text-pretty">
                        {offer.name}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {booking.adults}{' '}
                        {booking.adults === 1 ? 'adult' : 'adults'} · {length}
                    </p>
                </div>
                <p className="text-xl font-semibold tabular-nums">
                    {money(offer.total, offer.currency)}
                </p>
            </section>
            {showFull ? (
                <IdentityStep
                    photoRequired={idPhotoRequired}
                    action={book.url({ property: property.code })}
                    fields={booking}
                    nationalityRequired={nationalityRequired}
                    contact={contact}
                    kinds={identityKinds}
                    genders={genders}
                    countries={countries}
                />
            ) : (
                <ReturningCheck
                    action={returning.url({ property: property.code })}
                    fields={booking}
                    contact={contact}
                    onContact={setContact}
                    onNew={() => setShowFull(true)}
                />
            )}
        </GuestFrame>
    );
}
