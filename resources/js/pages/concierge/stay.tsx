import { useEffect } from 'react';
import { CardStep } from '@/components/concierge/card-step';
import { CheckedOutStep } from '@/components/concierge/checked-out-step';
import { IdentityStep } from '@/components/concierge/identity-step';
import { KeyStep } from '@/components/concierge/key-step';
import { PaymentStep } from '@/components/concierge/payment-step';
import { StayHome } from '@/components/concierge/stay-home';
import { GuestFrame } from '@/components/concierge/stay-menu';
import { lobby } from '@/routes/concierge';
import {
    CheckInStep,
    FrontDeskStep,
    MessageStep,
} from '@/components/concierge/status-steps';
import type { EnumOption } from '@/components/pms/enum-select';

type Stay = App.Data.Concierge.ConciergeStayData;

/** One page for the whole check-in. The server picks the step and the page shows only that step. */
export default function ConciergeStay({
    stay,
    identityKinds,
    genders,
    countries,
}: {
    stay: Stay;
    identityKinds: EnumOption[];
    genders: EnumOption[];
    countries: EnumOption[];
}) {
    // Remember the stay on this phone, so the lobby can offer "Continue your stay". It stays on the phone and is never sent.
    // A stay that ends at the front desk has nothing to continue, so its entry is dropped instead.
    const dead = stay.step === 'desk' || stay.step === 'hold_expired';

    useEffect(() => {
        try {
            const key = `concierge-stay:${stay.property.code}`;

            if (dead) {
                localStorage.removeItem(key);
            } else {
                localStorage.setItem(
                    key,
                    JSON.stringify({ token: stay.token, at: Date.now() }),
                );
            }
        } catch {
            // Storage can be blocked; the stay still works without it.
        }
    }, [stay.property.code, stay.token, dead]);

    return (
        <GuestFrame
            centerContent={
                stay.step === 'desk' ||
                stay.step === 'payment' ||
                stay.step === 'check_in'
            }
            back={{
                href: lobby.url({ property: stay.property.code }),
                label: 'Home',
            }}
            title={
                stay.guestFirstName
                    ? `Welcome, ${stay.guestFirstName}`
                    : 'Welcome'
            }
            description={
                stay.step === 'home' || stay.step === 'key'
                    ? undefined
                    : `${stay.arrivalDate} to ${stay.departureDate}`
            }
        >
            {stay.step === 'identity' && (
                <IdentityStep
                    stay={stay}
                    kinds={identityKinds}
                    genders={genders}
                    countries={countries}
                />
            )}
            {stay.step === 'card' && <CardStep stay={stay} />}
            {stay.step === 'payment' && (
                <PaymentStep
                    token={stay.token}
                    amount={stay.shortfall ?? '0.00'}
                />
            )}
            {stay.step === 'check_in' && <CheckInStep stay={stay} />}
            {stay.step === 'key' && (
                <>
                    <KeyStep stay={stay} />
                    <StayHome stay={stay} />
                </>
            )}
            {stay.step === 'home' && <StayHome stay={stay} />}
            {stay.step === 'checked_out' && <CheckedOutStep stay={stay} />}
            {stay.step === 'not_open' && (
                <MessageStep
                    title="Check-in is not open yet"
                    body={`Check-in opens on your arrival day, ${stay.arrivalDate}. Come back to this page then.`}
                />
            )}
            {stay.step === 'hold_expired' && (
                <MessageStep
                    title="Your room hold has ended"
                    body="The room was held for a short time and was not paid for, so it was released. Scan the lobby QR code to start again, or ask the front desk."
                />
            )}
            {stay.step === 'desk' && (
                <FrontDeskStep propertyCode={stay.property.code} />
            )}
        </GuestFrame>
    );
}
