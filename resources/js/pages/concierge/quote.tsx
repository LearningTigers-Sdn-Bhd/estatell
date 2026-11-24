import { useState } from 'react';
import { BedDouble, CircleAlert, TimerOff } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    IdentityStep,
    ReturningCheck,
} from '@/components/concierge/identity-step';
import { GuestFrame } from '@/components/concierge/stay-menu';
import { MessageStep } from '@/components/concierge/status-steps';
import type { EnumOption } from '@/components/pms/enum-select';
import { lobby } from '@/routes/concierge';
import { book, returning } from '@/routes/concierge/quote';

function money(amount: string, currency: string): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    }).format(Number(amount));
}

const friendlyDate = (date: string) =>
    new Date(`${date}T00:00:00`).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

/**
 * A quote link the AI assistant sent: the room, plan and dates priced again by the server. Nothing is booked until the guest
 * gives their details, as on the walk-in details page. An expired quote, or a room no longer free on that plan, sends the
 * guest back to the chat. See docs/34-ai-assistant/03-places-and-routes.md#quote-links.
 */
export default function ConciergeQuote({
    property,
    quote,
    nationalityRequired,
    identityKinds,
    genders,
    countries,
}: {
    property: App.Data.Property.PropertySummaryData;
    quote: App.Data.Concierge.ConciergeQuoteData;
    nationalityRequired: boolean;
    identityKinds: EnumOption[];
    genders: EnumOption[];
    countries: EnumOption[];
}) {
    // A guest is first asked to be recognised as a returning guest; what they type carries into the full form.
    const [showFull, setShowFull] = useState(false);
    const [contact, setContact] = useState('');

    const home = {
        href: lobby.url({ property: property.code }),
        label: 'Home',
    };
    const route = { property: property.code, quote: quote.token };

    if (quote.expired || quote.total === null) {
        return (
            <GuestFrame back={home} title="Your quote">
                <MessageStep
                    icon={TimerOff}
                    action={{ ...home, label: 'Back to home' }}
                    title={
                        quote.expired
                            ? 'This quote has expired'
                            : 'This room is no longer available'
                    }
                    body="Prices and rooms change. Ask us again in the chat for a new quote, or ask the front desk."
                />
            </GuestFrame>
        );
    }

    const changed = quote.total !== quote.quotedTotal;

    return (
        <GuestFrame
            back={home}
            title="Your quote"
            description="Tell us who is staying. Your room is booked when you continue, then you pay the whole stay."
        >
            {changed && (
                <Alert>
                    <CircleAlert aria-hidden />
                    <AlertTitle>The price has changed</AlertTitle>
                    <AlertDescription>
                        It was {money(quote.quotedTotal, quote.currency)} and is
                        now {money(quote.total, quote.currency)}.
                    </AlertDescription>
                </Alert>
            )}
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
                        {quote.roomTypeName}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {quote.ratePlanName}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {friendlyDate(quote.arrivalDate)} to{' '}
                        {friendlyDate(quote.departureDate)} · {quote.nights}{' '}
                        {quote.nights === 1 ? 'night' : 'nights'} ·{' '}
                        {quote.adults} {quote.adults === 1 ? 'adult' : 'adults'}
                    </p>
                </div>
                <p className="text-xl font-semibold tabular-nums">
                    {money(quote.total, quote.currency)}
                </p>
            </section>
            <p className="text-sm text-muted-foreground">
                You pay the whole stay when you book. Any taxes and security
                deposit are added on the payment step; the deposit is refunded
                at check-out.
            </p>
            {showFull ? (
                <IdentityStep
                    action={book.url(route)}
                    nationalityRequired={nationalityRequired}
                    contact={contact}
                    kinds={identityKinds}
                    genders={genders}
                    countries={countries}
                />
            ) : (
                <ReturningCheck
                    action={returning.url(route)}
                    fields={{}}
                    contact={contact}
                    onContact={setContact}
                    onNew={() => setShowFull(true)}
                />
            )}
        </GuestFrame>
    );
}
