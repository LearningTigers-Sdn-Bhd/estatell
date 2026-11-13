import { Form, Link } from '@inertiajs/react';
import { GuestFrame } from '@/components/concierge/stay-menu';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { lobby, walkIn } from '@/routes/concierge';
import { book } from '@/routes/concierge/walk-in';

function money(amount: string, currency: string): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    }).format(Number(amount));
}

/** A guest with no booking picks a room for tonight. The server prices every room; the guest pays on the next page. */
export default function ConciergeWalkIn({
    property,
    adults,
    nights,
    offers,
}: {
    property: App.Data.Property.PropertySummaryData;
    adults: number;
    nights: number;
    offers: App.Data.Concierge.ConciergeOfferData[];
}) {
    return (
        <GuestFrame
            back={{
                href: lobby.url({ property: property.code }),
                label: 'Home',
            }}
            title="Book a room for tonight"
            description="Choose how many people and nights. You pay next, and then check in on this phone."
        >
            <Form
                action={walkIn.url({ property: property.code })}
                method="get"
                className="flex items-end gap-3"
            >
                <FieldGroup className="grid grid-cols-2 gap-3">
                    <Field>
                        <FieldLabel htmlFor="adults">Adults</FieldLabel>
                        <Input
                            id="adults"
                            name="adults"
                            type="number"
                            min={1}
                            max={8}
                            defaultValue={adults}
                        />
                    </Field>
                    <Field>
                        <FieldLabel htmlFor="nights">Nights</FieldLabel>
                        <Input
                            id="nights"
                            name="nights"
                            type="number"
                            min={1}
                            max={14}
                            defaultValue={nights}
                        />
                    </Field>
                </FieldGroup>
                <Button type="submit" variant="outline">
                    Update
                </Button>
            </Form>

            {offers.length === 0 && (
                <p className="rounded-lg border p-4 text-sm text-pretty text-muted-foreground">
                    No room is free for {adults}{' '}
                    {adults === 1 ? 'adult' : 'adults'} and {nights}{' '}
                    {nights === 1 ? 'night' : 'nights'}. Try fewer people or
                    nights, or ask the front desk.
                </p>
            )}

            <ul className="flex flex-col gap-3">
                {offers.map((offer) => (
                    <li key={offer.roomTypeId}>
                        <Form
                            action={book.url({
                                property: property.code,
                            })}
                            method="post"
                            className="flex flex-col gap-3 rounded-lg border p-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="room_type_id"
                                        value={offer.roomTypeId}
                                    />
                                    <input
                                        type="hidden"
                                        name="adults"
                                        value={adults}
                                    />
                                    <input
                                        type="hidden"
                                        name="nights"
                                        value={nights}
                                    />
                                    <div className="flex items-baseline justify-between gap-3">
                                        <p className="font-medium">
                                            {offer.name}
                                        </p>
                                        <p className="font-semibold tabular-nums">
                                            {money(offer.total, offer.currency)}
                                        </p>
                                    </div>
                                    <FieldError>
                                        {errors.room_type_id}
                                    </FieldError>
                                    <Button
                                        type="submit"
                                        className="h-11 w-full rounded-lg"
                                        disabled={processing}
                                        focusableWhenDisabled
                                    >
                                        {processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        Book this room
                                    </Button>
                                </>
                            )}
                        </Form>
                    </li>
                ))}
            </ul>

            <Link
                href={lobby.url({ property: property.code })}
                className="self-center text-sm text-muted-foreground underline-offset-4 hover:underline"
            >
                I already have a booking
            </Link>
        </GuestFrame>
    );
}
