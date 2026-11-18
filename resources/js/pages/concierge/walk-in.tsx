import { Form, Link } from "@inertiajs/react";
import {
    ArrowRight,
    BedDouble,
    Clock,
    Hourglass,
    Moon,
    Search,
    UsersRound,
} from "lucide-react";
import { EmptyState } from "@/components/pms/empty-state";
import { GuestFrame } from "@/components/concierge/stay-menu";
import { Button, buttonVariants } from "@/components/ui/button";
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { enterCode, lobby, walkIn } from "@/routes/concierge";
import { book } from "@/routes/concierge/walk-in";

function money(amount: string, currency: string): string {
    return new Intl.NumberFormat(undefined, {
        style: "currency",
        currency,
    }).format(Number(amount));
}

/** A guest with no booking picks a room for tonight. The server prices every room; the guest pays on the next page. */
export default function ConciergeWalkIn({
    property,
    adults,
    nights,
    stayType,
    startTime,
    hours,
    canDayUse,
    offers,
}: {
    property: App.Data.Property.PropertySummaryData;
    adults: number;
    nights: number;
    stayType: "overnight" | "day_use";
    startTime: string;
    hours: number;
    canDayUse: boolean;
    offers: App.Data.Concierge.ConciergeOfferData[];
}) {
    const dayUse = stayType === "day_use";
    const length = dayUse
        ? `${hours} ${hours === 1 ? "hour" : "hours"}`
        : `${nights} ${nights === 1 ? "night" : "nights"}`;

    return (
        <GuestFrame
            back={{
                href: lobby.url({ property: property.code }),
                label: "Home",
            }}
            title={
                dayUse
                    ? "Book a room for a few hours"
                    : "Book a room for tonight"
            }
            description={
                dayUse
                    ? "Choose your guests, start time and hours, then select a room. Payment and check-in follow."
                    : "Choose your guests and number of nights, then select a room. Payment and check-in follow."
            }
        >
            <section
                className="flex flex-col gap-5 rounded-2xl border border-border/40 bg-card p-5 sm:p-6"
                aria-labelledby="walk-in-search-title"
            >
                <h2 id="walk-in-search-title" className="text-lg font-semibold">
                    Your stay details
                </h2>
                {canDayUse && (
                    <div
                        className="flex gap-2"
                        role="group"
                        aria-label="Stay type"
                    >
                        {(["overnight", "day_use"] as const).map((type) => (
                            <Link
                                key={type}
                                href={walkIn.url(
                                    { property: property.code },
                                    { query: { stay_type: type, adults } },
                                )}
                                aria-current={
                                    stayType === type ? "true" : undefined
                                }
                                className={buttonVariants({
                                    variant:
                                        stayType === type
                                            ? "default"
                                            : "outline",
                                    className: "min-h-11 flex-1",
                                })}
                            >
                                {type === "overnight" ? "Overnight" : "Day use"}
                            </Link>
                        ))}
                    </div>
                )}
                <Form
                    action={walkIn.url({ property: property.code })}
                    method="get"
                >
                    {({ processing, errors }) => (
                        <div className="flex flex-col items-stretch gap-4 sm:flex-row sm:items-end">
                            <input
                                type="hidden"
                                name="stay_type"
                                value={stayType}
                            />
                            <FieldGroup
                                className={`grid flex-1 gap-4 ${dayUse ? "grid-cols-3" : "grid-cols-2"}`}
                            >
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
                                {dayUse ? (
                                    <>
                                        <Field
                                            data-invalid={!!errors.start_time}
                                        >
                                            <FieldLabel htmlFor="start_time">
                                                <Clock
                                                    className="size-4 text-primary"
                                                    aria-hidden
                                                />
                                                Start time
                                            </FieldLabel>
                                            <Input
                                                id="start_time"
                                                name="start_time"
                                                type="time"
                                                step={1800}
                                                defaultValue={startTime}
                                                className="h-12 text-base"
                                                aria-invalid={
                                                    !!errors.start_time
                                                }
                                            />
                                            <FieldError>
                                                {errors.start_time}
                                            </FieldError>
                                        </Field>
                                        <Field data-invalid={!!errors.hours}>
                                            <FieldLabel htmlFor="hours">
                                                <Hourglass
                                                    className="size-4 text-primary"
                                                    aria-hidden
                                                />
                                                Hours
                                            </FieldLabel>
                                            <Input
                                                id="hours"
                                                name="hours"
                                                type="number"
                                                min={1}
                                                max={12}
                                                defaultValue={hours}
                                                className="h-12 text-base"
                                                aria-invalid={!!errors.hours}
                                            />
                                            <FieldError>
                                                {errors.hours}
                                            </FieldError>
                                        </Field>
                                    </>
                                ) : (
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
                                )}
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
                        description={`No room is available for ${adults} ${adults === 1 ? "adult" : "adults"} and ${length}. Try fewer guests${dayUse ? ", another time or fewer hours" : " or nights"}, or ask the front desk.`}
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
                            {offers.length}{" "}
                            {offers.length === 1 ? "option" : "options"}
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
                                                name="stay_type"
                                                value={stayType}
                                            />
                                            {dayUse ? (
                                                <>
                                                    <input
                                                        type="hidden"
                                                        name="start_time"
                                                        value={startTime}
                                                    />
                                                    <input
                                                        type="hidden"
                                                        name="hours"
                                                        value={hours}
                                                    />
                                                </>
                                            ) : (
                                                <input
                                                    type="hidden"
                                                    name="nights"
                                                    value={nights}
                                                />
                                            )}
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
                                                    Total for {length}
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
                        variant: "outline",
                        className: "min-h-11 w-full sm:w-fit",
                    })}
                >
                    Find my booking
                    <ArrowRight aria-hidden />
                </Link>
            </div>
        </GuestFrame>
    );
}
