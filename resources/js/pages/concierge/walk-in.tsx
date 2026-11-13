import { Form, Link } from '@inertiajs/react';
import { ArrowRight, BedDouble, Moon, Search, UsersRound } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { GuestFrame } from '@/components/concierge/stay-menu';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { enterCode, lobby, walkIn } from '@/routes/concierge';
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
            description="Choose your guests and number of nights, then select a room. Payment and check-in follow."
        >
            <section
                className="flex flex-col gap-5 rounded-2xl border border-border/40 bg-card p-5 sm:p-6"
                aria-labelledby="walk-in-search-title"
            >
                <h2 id="walk-in-search-title" className="text-lg font-semibold">
                    Your stay details
                </h2>
                <Form
                    action={walkIn.url({ property: property.code })}
                    method="get"
                >
                    {({ processing, errors }) => (
                        <div className="flex flex-col items-stretch gap-4 sm:flex-row sm:items-end">
                            <FieldGroup className="grid flex-1 grid-cols-2 gap-4">
                                <Field data-invalid={!!errors.adults}>
                                    <FieldLabel htmlFor="adults">
                                        <UsersRound
                                            className="size-4 text-primary"
                                            aria-hidden
                                        />
                                        Adults
                                    </FieldLabel>
                                    <Input
                                        id="adults"
                                        name="adults"
                                        type="number"
                                        min={1}
                                        max={8}
                                        defaultValue={adults}
                                        className="h-12 text-base"
                                        aria-invalid={!!errors.adults}
                                    />
                                    <FieldError>{errors.adults}</FieldError>
                                </Field>
                                <Field data-invalid={!!errors.nights}>
                                    <FieldLabel htmlFor="nights">
                                        <Moon
                                            className="size-4 text-primary"
                                            aria-hidden
                                        />
                                        Nights
                                    </FieldLabel>
                                    <Input
                                        id="nights"
                                        name="nights"
                                        type="number"
                                        min={1}
                                        max={14}
                                        defaultValue={nights}
                                        className="h-12 text-base"
                                        aria-invalid={!!errors.nights}
                                    />
                                    <FieldError>{errors.nights}</FieldError>
                                </Field>
                            </FieldGroup>
                            <Button
                                type="submit"
                                size="lg"
                                variant="outline"
                                className="min-h-12 px-5"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing ? (
                                    <Spinner data-icon="inline-start" />
                                ) : (
                                    <Search
                                        data-icon="inline-start"
                                        aria-hidden
                                    />
                                )}
                                Update search
                            </Button>
                        </div>
                    )}
                </Form>
            </section>
            {offers.length === 0 ? (
                <div className="rounded-2xl border border-border/40 bg-card px-5 py-8">
                    <EmptyState
                        icon={BedDouble}
                        title="No rooms available for these details"
                        description={`No room is available for ${adults} ${adults === 1 ? 'adult' : 'adults'} and ${nights} ${nights === 1 ? 'night' : 'nights'}. Try fewer guests or nights, or ask the front desk.`}
                    />
                </div>
            ) : (
                <section
                    className="flex flex-col gap-5"
                    aria-labelledby="walk-in-offers-title"
                >
                    <div className="flex items-center justify-between gap-3">
                        <h2
                            id="walk-in-offers-title"
                            className="text-xl font-semibold"
                        >
                            Choose a room
                        </h2>
                        <p className="text-sm text-muted-foreground tabular-nums">
                            {offers.length}{' '}
                            {offers.length === 1 ? 'option' : 'options'}
                        </p>
                    </div>
                    <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {offers.map((offer) => (
                            <li key={offer.roomTypeId}>
                                <Form
                                    action={book.url({
                                        property: property.code,
                                    })}
                                    method="post"
                                    className="flex h-full flex-col rounded-2xl border border-border/40 bg-card p-6"
                                >
                                    {({ processing, errors }) => (
                                        <div className="flex h-full flex-col gap-6">
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
                                            <span className="flex size-12 items-center justify-center rounded-full bg-muted/60 text-primary">
                                                <BedDouble
                                                    className="size-6"
                                                    strokeWidth={1.5}
                                                    aria-hidden
                                                />
                                            </span>
                                            <h3 className="flex-1 text-xl font-semibold text-pretty">
                                                {offer.name}
                                            </h3>
                                            <div className="flex flex-col gap-2 border-t border-border/40 pt-5">
                                                <p className="text-xs text-muted-foreground">
                                                    Total for {nights}{' '}
                                                    {nights === 1
                                                        ? 'night'
                                                        : 'nights'}
                                                </p>
                                                <p className="text-3xl font-semibold tracking-tight break-words tabular-nums">
                                                    {money(
                                                        offer.total,
                                                        offer.currency,
                                                    )}
                                                </p>
                                            </div>
                                            <FieldError>
                                                {errors.room_type_id}
                                            </FieldError>
                                            <Button
                                                type="submit"
                                                size="lg"
                                                className="min-h-12 w-full"
                                                disabled={processing}
                                                focusableWhenDisabled
                                            >
                                                {processing && (
                                                    <Spinner data-icon="inline-start" />
                                                )}
                                                Book this room
                                                <ArrowRight
                                                    data-icon="inline-end"
                                                    aria-hidden
                                                />
                                            </Button>
                                        </div>
                                    )}
                                </Form>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
            <div className="flex flex-col items-center gap-3 border-t border-border/40 pt-6 sm:flex-row sm:justify-between">
                <p className="text-sm text-muted-foreground">
                    Already have a booking?
                </p>
                <Link
                    href={enterCode.url({ property: property.code })}
                    className={buttonVariants({
                        variant: 'outline',
                        className: 'min-h-11 w-full sm:w-fit',
                    })}
                >
                    Find my booking
                    <ArrowRight aria-hidden />
                </Link>
            </div>
        </GuestFrame>
    );
}
