import { Headset, GraduationCap, ArrowRight, Check } from 'lucide-react';
import { Container } from './ui';
import { useScrollReveal, useStaggerReveal } from './use-reveal';

const plans = [
    {
        tag: 'By room count',
        name: 'PMS',
        desc: 'The operational core for your property.',
        points: [
            'Reservations, front desk, housekeeping',
            'Dashboard and e-invoicing',
        ],
    },
    {
        tag: 'By room count',
        name: 'Channel Manager',
        desc: 'One update reaches every channel.',
        points: [
            '9+ OTAs synced in real time',
            'Two-way availability sync',
            'Standalone or paired with PMS',
        ],
    },
    {
        tag: 'No commission on your own website',
        name: 'Booking Engine',
        desc: 'Bookings made on your own website carry no commission, only the Booking Engine fee.',
        points: ['Your own website', 'FPX and card payments'],
    },
];

const steps = [
    {
        label: 'Step 1',
        title: 'Book a walkthrough',
        body: 'A 20-minute call to map your rooms, rates and channels onto Estatell.',
    },
    {
        label: 'Step 2',
        title: 'Onboard in hours',
        body: 'We configure your PMS, Channel Manager and Booking Engine, then layer on AI Concierge, smart lock and e-invoicing automations.',
    },
    {
        label: 'Step 3',
        title: 'Your system goes live',
        body: 'Ready to take bookings and manage your property and guests.',
    },
    {
        label: 'Step 4 · Optional',
        title: 'Go live on WhatsApp',
        body: 'Guests can book and chat on the app they already use.',
    },
];

export function PlansSteps() {
    const plansHeadRef = useScrollReveal();
    const plansGridRef = useStaggerReveal(0.1);
    const plansCtaRef = useScrollReveal();
    const stepsHeadRef = useScrollReveal();
    const stepsGridRef = useStaggerReveal(0.08);
    const supportRef = useStaggerReveal(0.08);

    return (
        <>
            {/* Plans */}
            <section
                id="plans"
                aria-labelledby="plans-heading"
                className="scroll-mt-24 border-t border-border bg-muted py-10 sm:py-14"
            >
                <Container>
                    <div
                        ref={plansHeadRef}
                        className="mb-12 grid gap-6 lg:mb-16 lg:grid-cols-[1.1fr_0.9fr] lg:items-end lg:gap-20"
                    >
                        <div>
                            <p className="text-xs font-medium tracking-widest text-primary uppercase">
                                Pricing
                            </p>
                            <h2
                                id="plans-heading"
                                className="mt-5 text-4xl leading-none font-medium tracking-tighter sm:text-5xl xl:text-6xl"
                            >
                                Your property.
                                <br />
                                <span className="text-primary">
                                    Your scale.
                                </span>
                            </h2>
                        </div>
                        <p className="max-w-md text-base leading-relaxed text-foreground/70 lg:pb-1">
                            PMS and Channel Manager are priced by room-count
                            tier. You pay for the size of your property, not a
                            one-size-fits-all package.
                        </p>
                    </div>

                    <div ref={plansGridRef} className="border-t border-border">
                        {plans.map((plan, index) => (
                            <article
                                key={plan.name}
                                className="border-b border-border py-7 sm:py-9"
                            >
                                <div
                                    data-stagger
                                    className="grid gap-5 lg:grid-cols-[1.1fr_0.9fr] lg:gap-20"
                                >
                                    <div className="flex items-start gap-4 sm:gap-6">
                                        <span
                                            className="shrink-0 pt-1.5 text-xs font-medium text-foreground/70 tabular-nums"
                                            aria-hidden="true"
                                        >
                                            0{index + 1}
                                        </span>
                                        <div className="min-w-0">
                                            <h3 className="text-2xl font-medium tracking-tight sm:text-3xl">
                                                {plan.name}
                                            </h3>
                                            <p className="mt-2 text-xs font-medium text-primary">
                                                {plan.tag}
                                            </p>
                                            <p className="mt-4 max-w-sm text-sm leading-relaxed text-foreground/70">
                                                {plan.desc}
                                            </p>
                                        </div>
                                    </div>
                                    <ul className="grid content-center gap-3 pl-8 sm:pl-10 lg:pl-0">
                                        {plan.points.map((point) => (
                                            <li
                                                key={point}
                                                className="flex items-start gap-3 text-sm leading-relaxed text-foreground/70"
                                            >
                                                <Check
                                                    className="mt-1 size-4 shrink-0 text-primary"
                                                    strokeWidth={1.5}
                                                    aria-hidden="true"
                                                />
                                                {point}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            </article>
                        ))}
                    </div>

                    <div
                        ref={plansCtaRef}
                        className="mt-8 flex flex-col items-start gap-5 sm:mt-10 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <p className="text-lg font-medium tracking-tight">
                                Let’s find your room-count tier.
                            </p>
                            <p className="mt-1 text-sm leading-relaxed text-foreground/70">
                                Tell us about your property. We’ll walk you
                                through the pricing.
                            </p>
                        </div>
                        <a
                            href="https://wa.me/601162023998?text=Hi%2C%20I'd%20like%20room-count%20tier%20pricing%20for%20Estatell."
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex min-h-12 w-full shrink-0 items-center justify-center gap-4 rounded-full bg-primary px-5 py-3 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90 motion-reduce:transition-none sm:w-auto sm:gap-5 sm:px-6"
                        >
                            Get pricing on WhatsApp
                            <span className="sr-only">
                                {' '}
                                (opens in a new tab)
                            </span>
                            <ArrowRight
                                className="size-4 shrink-0"
                                aria-hidden="true"
                            />
                        </a>
                    </div>
                </Container>
            </section>

            {/* Get started */}
            <section
                id="start"
                aria-labelledby="start-heading"
                className="scroll-mt-24 py-10 sm:py-14"
            >
                <Container>
                    <div className="grid gap-12 lg:grid-cols-[0.9fr_1.1fr] lg:gap-24">
                        <div ref={stepsHeadRef}>
                            <p className="text-xs font-medium tracking-widest text-primary uppercase">
                                Get started
                            </p>
                            <h2
                                id="start-heading"
                                className="mt-5 text-4xl leading-none font-medium tracking-tighter sm:text-5xl xl:text-6xl"
                            >
                                Your next chapter.
                                <br />
                                <span className="text-primary">
                                    Up and running.
                                </span>
                            </h2>
                            <p className="mt-6 max-w-sm text-base leading-relaxed text-foreground/70">
                                From the first walkthrough to your first
                                booking. We help you get set up, then stay by
                                your side.
                            </p>
                        </div>

                        <div ref={stepsGridRef}>
                            <ol>
                                {steps.map((step, index) => (
                                    <li
                                        key={step.label}
                                        className="relative pb-8 pl-16 before:absolute before:top-12 before:bottom-2 before:left-[23px] before:w-px before:bg-border last:pb-0 last:before:hidden sm:pl-20"
                                    >
                                        <div data-stagger className="relative">
                                            <span
                                                className={`absolute top-0 -left-16 flex size-12 items-center justify-center rounded-full text-sm font-medium tabular-nums sm:-left-20 ${index === 0 ? 'bg-primary text-primary-foreground' : 'border border-border bg-background text-foreground/70'}`}
                                                aria-hidden="true"
                                            >
                                                0{index + 1}
                                            </span>
                                            <p className="text-xs font-medium text-primary">
                                                {step.label}
                                            </p>
                                            <h3 className="mt-2 text-xl font-medium tracking-tight sm:text-2xl">
                                                {step.title}
                                            </h3>
                                            <p className="mt-2 max-w-md text-sm leading-relaxed text-foreground/70">
                                                {step.body}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </div>

                    <div
                        ref={supportRef}
                        className="mt-14 grid gap-6 border-t border-border pt-8 sm:grid-cols-2 lg:mt-20 lg:grid-cols-[0.9fr_1.1fr] lg:gap-24"
                    >
                        {[
                            {
                                icon: Headset,
                                title: 'Dedicated customer support',
                                body: 'Real people in your timezone.',
                            },
                            {
                                icon: GraduationCap,
                                title: 'Full onboarding & training',
                                body: 'A setup guide for every property.',
                            },
                        ].map((support) => (
                            <div
                                key={support.title}
                                data-stagger
                                className="flex items-start gap-4"
                            >
                                <support.icon
                                    className="mt-1 size-6 shrink-0 text-primary"
                                    strokeWidth={1.5}
                                    aria-hidden="true"
                                />
                                <div>
                                    <h3 className="text-base font-medium tracking-tight">
                                        {support.title}
                                    </h3>
                                    <p className="mt-1 text-sm text-foreground/70">
                                        {support.body}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                </Container>
            </section>
        </>
    );
}
