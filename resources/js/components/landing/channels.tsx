import { BookingJourney } from './booking-journey';
import { ArrowRight, ArrowLeftRight } from 'lucide-react';
import { Container } from './ui';
import { useScrollReveal } from './use-reveal';

const otas = [
    { name: 'Airbnb', src: '/images/landing/ota/airbnb.png' },
    { name: 'Agoda', src: '/images/landing/ota/agoda.png' },
    { name: 'Booking.com', src: '/images/landing/ota/bookingcom.png' },
    { name: 'Expedia', src: '/images/landing/ota/expedia.png' },
    { name: 'Klook', src: '/images/landing/ota/klook.png' },
    { name: 'Tiket.com', src: '/images/landing/ota/tiketcom.png' },
    { name: 'Traveloka', src: '/images/landing/ota/traveloka.png' },
    { name: 'Trip.com', src: '/images/landing/ota/tripcom.png' },
];

const benefits = [
    {
        title: 'No overbooking',
        body: 'A room sold on one channel closes on the others.',
    },
    {
        title: 'No manual errors',
        body: 'Stop copying rates between extranets and spreadsheets.',
    },
    {
        title: 'One login',
        body: 'Manage every channel from a single dashboard.',
    },
];

export function Channels() {
    const cmCopyRef = useScrollReveal();
    const cmDiagramRef = useScrollReveal(120);

    return (
        <>
            {/* Channel Manager */}
            <section
                id="channels"
                className="scroll-mt-24 border-y border-border bg-muted pt-10 pb-8 sm:pt-14 sm:pb-10 lg:pt-16"
            >
                <Container>
                    <div
                        ref={cmCopyRef}
                        className="grid gap-8 lg:grid-cols-[1.2fr_1fr] lg:items-end lg:gap-20"
                    >
                        <div>
                            <p className="text-xs font-medium tracking-widest text-foreground/70 uppercase">
                                Channel Manager
                            </p>
                            <h2 className="mt-5 text-4xl leading-none font-medium tracking-tighter sm:text-5xl lg:text-6xl">
                                One update.
                                <br />
                                Everywhere you sell.
                            </h2>
                        </div>
                        <div>
                            <p className="max-w-md text-base leading-relaxed text-foreground/70">
                                Your rates, rooms and availability — working
                                together across every connected OTA and your
                                direct channel. Update in Estatell. Two-way sync
                                takes care of the rest.
                            </p>
                        </div>
                    </div>

                    <div ref={cmDiagramRef} className="mt-12 bg-muted sm:mt-16">
                        <figure aria-label="Update rates and inventory in Estatell, which synchronizes availability with your connected booking channels.">
                            <div className="grid items-center gap-4 border-y border-border py-8 text-center sm:grid-cols-[1fr_40px_1fr_40px_1fr] sm:gap-5 sm:py-12 lg:grid-cols-[1fr_72px_1fr_72px_1fr]">
                                <div>
                                    <p className="text-xl font-medium tracking-tight sm:text-2xl">
                                        Your property
                                    </p>
                                    <p className="mt-2 text-sm text-foreground/70">
                                        Rates. Rooms. Availability.
                                    </p>
                                </div>
                                <ArrowRight
                                    className="text-foreground/70-foreground mx-auto size-6 rotate-90 sm:h-5 sm:w-full sm:rotate-0"
                                    strokeWidth={1}
                                    aria-hidden="true"
                                />
                                <div>
                                    <img
                                        src="/images/landing/estatell-logo.webp"
                                        alt="Estatell"
                                        width={809}
                                        height={159}
                                        className="mx-auto h-auto w-36"
                                    />
                                    <p className="mt-2 text-xs tracking-wide text-foreground/70">
                                        One place to manage it all
                                    </p>
                                </div>
                                <ArrowLeftRight
                                    className="mx-auto size-6 rotate-90 text-primary sm:h-5 sm:w-full sm:rotate-0"
                                    strokeWidth={1}
                                    aria-hidden="true"
                                />
                                <div>
                                    <p className="text-xl font-medium tracking-tight sm:text-2xl">
                                        Every channel
                                    </p>
                                    <p className="mt-2 text-sm text-foreground/70">
                                        Connected through two-way sync.
                                    </p>
                                </div>
                            </div>

                            <div className="grid grid-cols-2 items-center gap-x-6 gap-y-7 py-9 sm:grid-cols-4 lg:grid-cols-8 lg:gap-x-6 lg:py-10">
                                {otas.map((ota) => (
                                    <div
                                        key={ota.name}
                                        className="flex h-9 items-center justify-center"
                                    >
                                        {/* eslint-disable-next-line @next/next/no-img-element */}
                                        <img
                                            src={ota.src}
                                            alt={ota.name}
                                            loading="lazy"
                                            className="max-h-7 w-auto max-w-[88px] object-contain mix-blend-multiply"
                                        />
                                    </div>
                                ))}
                            </div>
                            <figcaption className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-sm text-foreground/70">
                                <span>Plus your own channels</span>
                                <span
                                    aria-hidden="true"
                                    className="text-foreground/70-foreground"
                                >
                                    /
                                </span>
                                <span className="text-foreground">
                                    Direct booking & walk-ins
                                </span>
                            </figcaption>
                        </figure>
                    </div>

                    <div className="mt-12 grid gap-8 border-t border-border pt-10 lg:grid-cols-[1fr_1.35fr] lg:gap-20">
                        <div>
                            <h3 className="max-w-xs text-3xl leading-tight font-medium tracking-tight">
                                Less admin.
                                <br />
                                More control.
                            </h3>
                            <p className="mt-4 max-w-xs text-sm leading-relaxed text-foreground/70">
                                Start with Channel Manager on its own. Connect
                                Estatell PMS when you want your front desk in
                                sync, too.
                            </p>
                        </div>
                        <dl className="divide-y divide-border">
                            {benefits.map((benefit) => (
                                <div
                                    key={benefit.title}
                                    className="grid gap-2 py-5 first:pt-0 last:pb-0 sm:grid-cols-[160px_1fr] sm:gap-6"
                                >
                                    <dt className="text-base font-medium tracking-tight">
                                        {benefit.title}
                                    </dt>
                                    <dd className="text-sm leading-relaxed text-foreground/70">
                                        {benefit.body}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                </Container>
            </section>

            <BookingJourney />
        </>
    );
}
