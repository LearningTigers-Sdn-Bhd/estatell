import { Head } from '@inertiajs/react';
import { useEffect } from 'react';
import { CardStep } from '@/components/concierge/card-step';
import { CheckedOutStep } from '@/components/concierge/checked-out-step';
import { IdentityStep } from '@/components/concierge/identity-step';
import { KeyStep } from '@/components/concierge/key-step';
import { PaymentStep } from '@/components/concierge/payment-step';
import { StayHome } from '@/components/concierge/stay-home';
import { CheckInStep, MessageStep } from '@/components/concierge/status-steps';
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
    useEffect(() => {
        try {
            localStorage.setItem(
                `concierge-stay:${stay.property.code}`,
                JSON.stringify({ token: stay.token, at: Date.now() }),
            );
        } catch {
            // Storage can be blocked; the stay still works without it.
        }
    }, [stay.property.code, stay.token]);

    return (
        <>
            <Head title="Check in" />
            <div className="min-h-0 flex-1 overflow-y-auto px-4 py-8">
                <div className="mx-auto flex w-full max-w-md flex-col gap-6">
                    <div className="flex flex-col gap-1">
                        <h1 className="font-heading text-2xl font-semibold">
                            {stay.guestFirstName
                                ? `Welcome, ${stay.guestFirstName}`
                                : 'Welcome'}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {stay.property.name}
                            {stay.step === 'home' || stay.step === 'key'
                                ? ''
                                : ` · ${stay.arrivalDate} to ${stay.departureDate}`}
                        </p>
                    </div>

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
                    {stay.step === 'checked_out' && (
                        <CheckedOutStep stay={stay} />
                    )}
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
                        <MessageStep
                            title="Please see the front desk"
                            body="We cannot finish this check-in here. Show your booking at the front desk and the team will help you."
                        />
                    )}
                </div>
            </div>
        </>
    );
}
