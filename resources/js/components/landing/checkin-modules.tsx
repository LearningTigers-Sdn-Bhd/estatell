import {
    Link2,
    FileSignature,
    LockKeyhole,
    ClipboardList,
    UtensilsCrossed,
    Briefcase,
    FileText,
    Star,
    TrendingUp,
    MessageCircle,
} from 'lucide-react';
import { Container } from './ui';
import { Kiosk } from './kiosk';
import { PinArt } from './pin-art';
import { useScrollReveal, useStaggerReveal } from './use-reveal';

const checkinSteps = [
    {
        icon: Link2,
        phase: 'Before arrival',
        title: 'Pre-check-in link',
        body: 'The guest receives the pre-check-in link before arrival via email and WhatsApp.',
    },
    {
        icon: FileSignature,
        phase: 'Before arrival',
        title: 'Documents & deposit',
        body: "Required documents and deposit payment are captured remotely and uploaded to the platform, including the guest's signature.",
    },
    {
        icon: LockKeyhole,
        phase: 'At check-in',
        title: 'Smart-lock PIN',
        body: "Keyless entry hardware, integrated with your PMS and the self-check-in flow. On remote check-in, the PIN goes straight to the guest's phone.",
    },
    {
        icon: ClipboardList,
        phase: 'For your team',
        title: 'Staff see everything',
        body: 'Hotels view pre-check-in status for every guest before they arrive.',
    },
];

const modules = [
    {
        icon: UtensilsCrossed,
        title: 'Point of sale',
        body: 'F&B orders and room charges, together in your PMS. Guests can order through the concierge, too.',
    },
    {
        icon: Briefcase,
        title: 'Agent portal',
        body: 'Group bookings, live availability, hotel approvals and a city ledger in one agent portal.',
    },
    {
        icon: FileText,
        title: 'E-Invoicing',
        body: 'Invoices and receipts for every booking, without a separate billing system.',
    },
    {
        icon: Star,
        title: 'Guest reputation',
        body: 'Catch concerns during the stay, then invite guests to share reviews on OTAs and Google.',
    },
    {
        icon: TrendingUp,
        title: 'Thoughtful upsells',
        body: 'Offer upgrades, late checkout, breakfast and experiences at the right moment in each stay.',
    },
    {
        icon: MessageCircle,
        title: 'WhatsApp automation',
        body: 'Confirmations, reminders and review requests, sent automatically on WhatsApp.',
    },
];

function ModulePreview({ index }: { index: number }) {
    const previews = [
        {
            label: 'ROOM 204 · DINING',
            rows: [
                ['Breakfast × 2', 'RM 48'],
                ['Fresh coffee', 'RM 12'],
            ],
            footer: 'Added to room bill',
            value: 'RM 60',
        },
        {
            label: 'AGENT RESERVATIONS',
            rows: [
                ['Deluxe king', '4 rooms'],
                ['Twin suite', '2 rooms'],
            ],
            footer: 'Group booking',
            value: 'Confirmed',
        },
        {
            label: 'INVOICE · SAMPLE',
            rows: [
                ['Room & stay', 'RM 480'],
                ['Dining', 'RM 60'],
            ],
            footer: 'Invoice ready',
            value: 'RM 540',
        },
    ];
    const preview = previews[index];
    return (
        <div
            aria-hidden="true"
            className="mb-7 rounded-xl border border-primary/10 bg-card p-4 text-xs"
        >
            <div className="mb-4 flex items-center justify-between text-xs font-medium tracking-widest text-primary">
                <span>{preview.label}</span>
                <span className="size-1.5 rounded-full bg-primary" />
            </div>
            {preview.rows.map(([label, value]) => (
                <div
                    key={label}
                    className="mt-2 flex justify-between gap-3 text-foreground/70"
                >
                    <span>{label}</span>
                    <span className="text-foreground">{value}</span>
                </div>
            ))}
            <div className="mt-4 flex justify-between gap-2 border-t border-border pt-3 font-medium text-primary">
                <span>{preview.footer}</span>
                <span>{preview.value}</span>
            </div>
        </div>
    );
}

export function CheckinModules() {
    const checkinHeadRef = useScrollReveal();
    const checkinGridRef = useStaggerReveal(0.08);
    const modulesHeadRef = useScrollReveal();
    const modulesGridRef = useStaggerReveal(0.06);

    return (
        <>
            {/* Self check-in */}
            <section
                id="checkin"
                aria-labelledby="checkin-heading"
                className="dark scroll-mt-24 bg-card pb-10 text-foreground sm:pb-14"
            >
                <div className="relative isolate overflow-hidden bg-muted pt-10 sm:pt-14">
                    <img
                        src="/images/landing/guest-arrival.webp"
                        alt="A traveler with a suitcase checking their phone at a boutique hotel entrance."
                        className="absolute inset-0 size-full object-cover object-[55%_center] lg:object-[center_45%]"
                    />
                    <div className="absolute inset-0 bg-gradient-to-r from-background/95 via-background/75 to-background/20" />
                    <Container>
                        <div
                            ref={checkinHeadRef}
                            className="relative flex min-h-[360px] flex-col justify-between gap-8 pb-6 sm:min-h-[480px] sm:gap-12 sm:p-10 lg:p-14"
                        >
                            <p className="text-xs font-medium tracking-widest text-chart-1 uppercase">
                                Self check-in &amp; smart locks
                            </p>
                            <div className="flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
                                <div className="max-w-lg">
                                    <h2
                                        id="checkin-heading"
                                        className="text-4xl leading-none font-medium tracking-tighter text-foreground sm:text-5xl xl:text-6xl"
                                    >
                                        Guests arrive ready.
                                        <br />
                                        <span className="text-chart-1">
                                            Staff stay ahead.
                                        </span>
                                    </h2>
                                    <p className="mt-5 max-w-sm text-base leading-relaxed text-foreground">
                                        No front desk queue. On remote check-in,
                                        the room’s smart-lock PIN is sent
                                        straight to the guest’s phone.
                                    </p>
                                </div>
                                <PinArt className="mx-auto w-full max-w-xs shrink-0 sm:max-w-sm lg:mx-0" />
                            </div>
                        </div>
                    </Container>
                </div>
                <Container>
                    <div ref={checkinGridRef} className="mt-10 sm:mt-12">
                        <ol className="grid gap-x-8 gap-y-9 sm:grid-cols-2 lg:grid-cols-4 lg:gap-x-6">
                            {checkinSteps.map((step, index) => (
                                <li
                                    key={step.title}
                                    className="border-t border-border pt-5"
                                >
                                    <div data-stagger>
                                        <div className="flex items-center justify-between gap-3 text-chart-1">
                                            <span
                                                className="text-xs font-medium tabular-nums"
                                                aria-hidden="true"
                                            >
                                                0{index + 1}
                                            </span>
                                            <step.icon
                                                className="size-5"
                                                strokeWidth={1.5}
                                                aria-hidden="true"
                                            />
                                        </div>
                                        <p className="mt-5 text-xs font-medium tracking-widest text-foreground/75 uppercase">
                                            {step.phase}
                                        </p>
                                        <h3 className="mt-2 text-lg font-medium tracking-tight">
                                            {step.title}
                                        </h3>
                                        <p className="mt-3 text-sm leading-relaxed text-foreground/75">
                                            {step.body}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </div>

                    <p className="mt-10 border-t border-border pt-6 text-right text-sm text-foreground/75">
                        Less paperwork. More welcome.
                    </p>
                </Container>
            </section>

            <Kiosk />

            {/* Modules */}
            <section
                id="more"
                aria-labelledby="modules-heading"
                className="scroll-mt-24 border-y border-border bg-muted py-10 sm:py-12"
            >
                <Container>
                    <div ref={modulesHeadRef}>
                        <p className="text-xs font-medium tracking-widest text-primary uppercase">
                            Everything else a hotel runs on
                        </p>
                        <h2
                            id="modules-heading"
                            className="mt-5 max-w-3xl text-4xl leading-none font-medium tracking-tighter sm:text-5xl xl:text-6xl"
                        >
                            More of your hotel.
                            <br />
                            <span className="text-primary">
                                Working together.
                            </span>
                        </h2>
                    </div>

                    <div
                        ref={modulesGridRef}
                        className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        {modules.map((module, index) => (
                            <article
                                key={module.title}
                                data-stagger
                                className="rounded-2xl border border-border bg-card p-6 sm:p-7"
                            >
                                {index < 3 && <ModulePreview index={index} />}
                                <module.icon
                                    className="size-5 text-primary"
                                    strokeWidth={1.5}
                                    aria-hidden="true"
                                />
                                <h3 className="mt-5 text-lg font-medium tracking-tight">
                                    {module.title}
                                </h3>
                                <p className="mt-2 text-sm leading-relaxed text-foreground/70">
                                    {module.body}
                                </p>
                            </article>
                        ))}
                    </div>
                </Container>
            </section>
        </>
    );
}
