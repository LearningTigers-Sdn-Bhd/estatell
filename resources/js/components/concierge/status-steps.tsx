import { Link, router } from '@inertiajs/react';
import { ArrowRight, BedDouble, Check, KeyRound } from 'lucide-react';
import { useState } from 'react';
import { Button, buttonVariants } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { checkIn, lobby } from '@/routes/concierge';

export function FrontDeskStep({ propertyCode }: { propertyCode: string }) {
    return (
        <section
            aria-labelledby="front-desk-title"
            className="mx-auto flex w-full max-w-4xl flex-col items-center gap-6 py-4 text-center sm:py-10"
        >
            <img
                src="/images/concierge-host.png"
                alt=""
                width={1024}
                height={1024}
                className="size-56 shrink-0 object-contain sm:size-72 md:size-80"
            />
            <div className="flex max-w-md flex-col items-center gap-6">
                <div className="flex flex-col gap-3">
                    <h2
                        id="front-desk-title"
                        className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl"
                    >
                        Please visit the front desk
                    </h2>
                    <p className="text-sm leading-relaxed text-pretty text-muted-foreground sm:text-base">
                        We can’t complete your check-in here. Show your booking
                        confirmation to the front desk team and they’ll help
                        you.
                    </p>
                </div>
                <Link
                    href={lobby.url({ property: propertyCode })}
                    className={buttonVariants({
                        size: 'lg',
                        className: 'min-h-12 px-5',
                    })}
                >
                    Back to home <ArrowRight aria-hidden />
                </Link>
            </div>
        </section>
    );
}

/** A heading and a sentence, for the steps that only tell the guest something. */
export function MessageStep({ title, body }: { title: string; body: string }) {
    return (
        <div className="flex max-w-2xl flex-col gap-2 rounded-lg border p-4">
            <h2 className="text-lg font-semibold">{title}</h2>
            <p className="text-sm text-pretty text-muted-foreground">{body}</p>
        </div>
    );
}

/** The last step before the key: one button that checks the guest in. */
export function CheckInStep({
    stay,
}: {
    stay: App.Data.Concierge.ConciergeStayData;
}) {
    const [busy, setBusy] = useState(false);

    const send = (position?: GeolocationPosition) =>
        router.post(
            checkIn.url({ token: stay.token }),
            position
                ? {
                      latitude: position.coords.latitude,
                      longitude: position.coords.longitude,
                  }
                : {},
            {
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );

    // A property that checks the guest's position gets it from the browser. A refused prompt sends none, and the server
    // tells the guest to allow location access.
    const checkInNow = () =>
        stay.locationRequired && navigator.geolocation
            ? navigator.geolocation.getCurrentPosition(send, () => send(), {
                  enableHighAccuracy: true,
                  timeout: 15000,
              })
            : send();

    return (
        <section
            aria-labelledby="check-in-title"
            className="mx-auto flex w-full max-w-md flex-col items-center gap-8 py-4 text-center sm:py-8"
        >
            <div
                className="relative flex size-28 items-center justify-center rounded-full border border-border/30 bg-muted/60"
                aria-hidden="true"
            >
                <KeyRound className="size-12 text-primary" strokeWidth={1.5} />
                <span className="absolute right-0 bottom-0 flex size-9 items-center justify-center rounded-full border-4 border-background bg-primary text-primary-foreground">
                    <Check className="size-4" strokeWidth={2.5} />
                </span>
            </div>
            <div className="flex flex-col gap-3">
                <h2
                    id="check-in-title"
                    className="text-2xl font-semibold tracking-tight sm:text-3xl"
                >
                    Ready to check in
                </h2>
                <p className="text-sm leading-relaxed text-pretty text-muted-foreground sm:text-base">
                    Your details are complete. Check in to see your room details
                    and door code.
                </p>
            </div>
            <div className="grid w-full grid-cols-2 divide-x divide-border/40 border-y border-border/40 py-6 text-sm">
                <div className="flex flex-col items-center gap-2 px-3">
                    <BedDouble
                        className="size-5 text-primary"
                        strokeWidth={1.5}
                        aria-hidden
                    />
                    <span>Room details</span>
                </div>
                <div className="flex flex-col items-center gap-2 px-3">
                    <KeyRound
                        className="size-5 text-primary"
                        strokeWidth={1.5}
                        aria-hidden
                    />
                    <span>Door code</span>
                </div>
            </div>
            <Button
                size="lg"
                className="h-12 w-full rounded-lg"
                disabled={busy}
                focusableWhenDisabled
                onClick={checkInNow}
            >
                {busy && <Spinner data-icon="inline-start" />}
                Check in now
                <ArrowRight data-icon="inline-end" aria-hidden />
            </Button>
        </section>
    );
}
