import { Check, Pause, Play } from 'lucide-react';
import { useScrollReveal } from './use-reveal';
import { usePreviewPlayback } from './use-preview-playback';

const steps = [
    [
        'Search & compare',
        'Find your stay',
        'Select dates. Make yourself at home.',
        [
            ['Check-in', '18 Oct'],
            ['Check-out', '20 Oct'],
            ['Guests', '2 adults'],
        ],
        'City, dates and guest count show live availability and the cheapest room.',
    ],
    [
        'Instant payment',
        'Secure checkout',
        'Your stay, one step closer.',
        [
            ['Stay total', 'RM 560'],
            ['Payment', 'FPX / card'],
            ['Booking', '2 nights'],
        ],
        'Secure FPX or credit card checkout, with protection against duplicate bookings when a payment is retried.',
    ],
    [
        'Confirmation & reminders',
        'You’re booked',
        'We’ll take it from here.',
        [
            ['Confirmation', 'Email sent'],
            ['Before arrival', 'WhatsApp reminder'],
            ['Check-in', 'From 3 pm'],
        ],
        'Email confirmation, then a WhatsApp reminder before check-in. Bookings land in Estatell automatically.',
    ],
    [
        'Hotel dashboard',
        'Ready for arrival',
        'Every booking, already in place.',
        [
            ['Guest', 'Sarah L.'],
            ['Room', '204 · Deluxe'],
            ['Status', 'Pre-checked in'],
        ],
        'Manage room types, pricing, inventory and e-invoices. View bookings, check-ins, checkouts and housekeeping on one screen.',
    ],
] as const;

const stepImages = [
    {
        src: '/images/landing/boutique-guest-room.webp',
        alt: 'Boutique guest room with a king bed, warm timber furnishings, and a balcony overlooking tropical greenery.',
        title: 'Find your escape.',
        caption: 'The right room. The right stay.',
    },
    {
        src: '/images/landing/booking-payment.webp',
        alt: 'A traveler holding a bank card while booking a hotel stay on a laptop.',
        title: 'Make it yours.',
        caption: 'A simple checkout, straight from your website.',
    },
    {
        src: '/images/landing/booking-confirmation.webp',
        alt: 'A traveler checking their phone beside a packed suitcase before departure.',
        title: 'Arrive reassured.',
        caption: 'Stay details and reminders, close at hand.',
    },
    {
        src: '/images/landing/booking-dashboard.webp',
        alt: 'Two hotel staff reviewing an arrivals calendar together at a computer.',
        title: 'Ready to welcome.',
        caption: 'Every arrival connected to your team.',
    },
] as const;

export function BookingJourney() {
    const headRef = useScrollReveal();
    const {
        sectionRef,
        selected: stage,
        paused,
        reducedMotion,
        manual,
        setFocused,
        select,
        togglePaused,
    } = usePreviewPlayback(steps.length);
    const active = steps[stage];
    const activeImage = stepImages[stage];
    return (
        <section
            ref={sectionRef}
            id="direct"
            aria-labelledby="booking-heading"
            className="scroll-mt-24 py-10 sm:py-14"
            onPointerDownCapture={() => setFocused(false)}
            onKeyDownCapture={() => setFocused(true)}
            onFocusCapture={(event) =>
                setFocused(event.target.matches(':focus-visible'))
            }
            onBlurCapture={(event) => {
                if (!event.currentTarget.contains(event.relatedTarget))
                    setFocused(false);
            }}
        >
            <div ref={headRef} className="mx-auto max-w-6xl px-5 sm:px-8">
                <div className="grid gap-6 lg:grid-cols-[1.1fr_0.9fr] lg:items-end lg:gap-20">
                    <div>
                        <p className="text-xs font-medium tracking-widest text-primary uppercase">
                            Booking Engine
                        </p>
                        <h2
                            id="booking-heading"
                            className="mt-5 text-4xl leading-none font-medium tracking-tighter sm:text-5xl xl:text-6xl"
                        >
                            Your hotel.
                            <br />
                            <span className="text-primary">
                                Their next great stay.
                            </span>
                        </h2>
                    </div>
                    <div>
                        <p className="max-w-sm text-base leading-relaxed text-foreground/70">
                            From the first click to a warm welcome. A booking
                            experience on your website, connected straight to
                            your front desk.
                        </p>
                    </div>
                </div>

                <div
                    className="mt-10 grid grid-cols-2 gap-x-3 gap-y-2 sm:mt-12 sm:gap-x-5 lg:grid-cols-4 lg:gap-6"
                    role="group"
                    aria-label="Explore the booking journey"
                >
                    {steps.map((step, index) => (
                        <button
                            key={step[0]}
                            type="button"
                            aria-pressed={stage === index}
                            aria-controls="booking-preview"
                            onClick={() => select(index)}
                            className={`flex min-h-16 cursor-pointer items-start gap-2 border-t-2 pt-4 pb-3 text-left transition-colors motion-reduce:transition-none sm:gap-3 ${stage === index ? 'border-primary text-primary' : 'border-border text-foreground/70 hover:border-border hover:text-foreground'}`}
                        >
                            <span
                                aria-hidden="true"
                                className="shrink-0 pt-0.5 text-xs tabular-nums"
                            >
                                0{index + 1}
                            </span>
                            <span className="text-sm leading-relaxed font-medium">
                                {step[0]}
                            </span>
                        </button>
                    ))}
                </div>

                <div className="mt-6 grid gap-8 lg:grid-cols-[1.1fr_0.9fr] lg:items-center lg:gap-20">
                    <figure className="dark relative isolate min-h-72 overflow-hidden rounded-sm bg-card sm:min-h-[420px] lg:h-full lg:min-h-[480px]">
                        <img
                            src={activeImage.src}
                            alt={activeImage.alt}
                            className="absolute inset-0 size-full object-cover object-center"
                        />
                        <div className="absolute inset-0 bg-gradient-to-t from-background/85 via-background/10 to-transparent" />
                        <figcaption className="absolute inset-x-0 bottom-0 p-5 text-foreground sm:p-8">
                            <p className="text-xs tracking-wide">{active[0]}</p>
                            <p className="mt-3 font-serif text-4xl sm:text-5xl">
                                {activeImage.title}
                            </p>
                            <p className="mt-3 text-sm text-foreground">
                                {activeImage.caption}
                            </p>
                        </figcaption>
                    </figure>

                    <div
                        id="booking-preview"
                        role="region"
                        aria-label="Booking journey preview"
                        aria-live={manual ? 'polite' : 'off'}
                        aria-atomic="true"
                        className="min-w-0 lg:py-6"
                    >
                        <div data-preview-content>
                            <div className="flex items-center justify-between gap-4">
                                <p className="text-xs font-medium tracking-widest text-primary uppercase">
                                    {active[1]}
                                </p>
                                <span className="shrink-0 text-xs text-foreground/70 tabular-nums">
                                    0{stage + 1} / 04
                                </span>
                            </div>
                            <h3 className="mt-4 min-h-[2.4em] max-w-sm text-3xl leading-tight font-medium tracking-tighter sm:text-4xl">
                                {active[2]}
                            </h3>
                            <dl className="mt-7 divide-y divide-border border-y border-border">
                                {active[3].map(([label, value]) => (
                                    <div
                                        key={label}
                                        className="flex items-baseline justify-between gap-4 py-4 text-sm"
                                    >
                                        <dt className="text-foreground/70">
                                            {label}
                                        </dt>
                                        <dd className="text-right font-medium">
                                            {value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                            <p className="mt-5 min-h-24 text-sm leading-relaxed text-foreground/70">
                                {active[4]}
                            </p>
                        </div>
                        <p className="mt-5 flex items-center gap-2 text-xs font-medium text-primary">
                            <Check
                                className="size-4 shrink-0"
                                aria-hidden="true"
                            />
                            Connected to your hotel, every step.
                        </p>
                    </div>
                </div>
                <div className="mt-5 flex flex-wrap items-center justify-between gap-3">
                    <p className="text-xs text-foreground/70">
                        Illustrative guest experience{' '}
                        <span aria-hidden="true">·</span> Powered by Estatell
                    </p>
                    {!reducedMotion && (
                        <button
                            type="button"
                            onClick={togglePaused}
                            className="inline-flex min-h-11 items-center gap-2 text-xs font-medium text-foreground/70 hover:text-foreground"
                            aria-label={
                                paused
                                    ? 'Play booking journey'
                                    : 'Pause booking journey'
                            }
                        >
                            {paused ? (
                                <Play className="size-4" aria-hidden="true" />
                            ) : (
                                <Pause className="size-4" aria-hidden="true" />
                            )}
                            {paused ? 'Play journey' : 'Pause autoplay'}
                        </button>
                    )}
                </div>
            </div>
        </section>
    );
}
