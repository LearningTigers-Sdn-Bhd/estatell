import { KeyRound, QrCode, RefreshCw, ScanLine } from 'lucide-react';
import { Container } from './ui';
import { useScrollReveal, useStaggerReveal } from './use-reveal';

const steps = [
    {
        icon: QrCode,
        title: 'Scan your booking',
        body: 'Guests who pre-checked in scan the QR from their email or WhatsApp.',
    },
    {
        icon: ScanLine,
        title: 'Verify your ID',
        body: 'Scan a passport or ID and capture the guest’s signature.',
    },
    {
        icon: KeyRound,
        title: 'Collect the key',
        body: 'A room key or smart-lock PIN, with a printed receipt.',
    },
];

export function Kiosk() {
    const copyRef = useScrollReveal();
    const artRef = useScrollReveal(120);
    const stepsRef = useStaggerReveal(0.08);

    return (
        <section
            id="kiosk"
            aria-labelledby="kiosk-heading"
            className="scroll-mt-24 py-10 sm:py-14"
        >
            <Container className="grid items-center gap-x-16 gap-y-10 lg:grid-cols-2">
                <div ref={copyRef}>
                    <p className="text-xs font-medium tracking-widest text-primary uppercase">
                        Self check-in kiosk
                    </p>
                    <h2
                        id="kiosk-heading"
                        className="mt-5 text-4xl leading-none font-medium tracking-tighter sm:text-5xl xl:text-6xl"
                    >
                        Arrive. Scan.
                        <br />
                        <span className="text-primary">Settle in.</span>
                    </h2>
                    <p className="mt-6 max-w-md text-base leading-relaxed text-foreground/70">
                        A warm welcome, even when the front desk is busy. Let
                        guests check in, verify their details and collect a key
                        at their own pace.
                    </p>
                </div>
                <figure
                    ref={artRef}
                    className="lg:col-start-2 lg:row-span-2 lg:row-start-1"
                >
                    <div className="overflow-hidden rounded-3xl bg-muted">
                        <img
                            src="/images/landing/self-checkin-kiosk-v2.webp"
                            alt="Freestanding black and silver Estatell kiosk with a hotel welcome screen offering booking QR scanning, ID verification and key collection, in a warm stone lobby."
                            width={1200}
                            height={1200}
                            className="h-auto w-full"
                        />
                    </div>
                    <figcaption className="mt-3 text-xs text-foreground/70">
                        Illustrative hardware and screen. Options vary by
                        property.
                    </figcaption>
                </figure>
                <div ref={stepsRef}>
                    <ol className="grid gap-6">
                        {steps.map((step, index) => (
                            <li
                                key={step.title}
                                data-stagger
                                className="flex gap-5 border-t border-border pt-5"
                            >
                                <span className="pt-1 text-xs font-medium text-primary">
                                    0{index + 1}
                                </span>
                                <div className="flex-1">
                                    <h3 className="text-lg font-medium tracking-tight">
                                        {step.title}
                                    </h3>
                                    <p className="mt-1 max-w-sm text-sm leading-relaxed text-foreground/70">
                                        {step.body}
                                    </p>
                                </div>
                                <step.icon
                                    className="mt-1 size-5 shrink-0 text-primary"
                                    strokeWidth={1.5}
                                    aria-hidden="true"
                                />
                            </li>
                        ))}
                    </ol>
                    <div className="mt-7 flex items-start gap-3 rounded-xl bg-primary/5 p-4 text-sm text-foreground">
                        <RefreshCw
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <p>
                            <span className="font-medium">
                                Connected to your PMS.
                            </span>{' '}
                            Guest records and room status stay in sync, so your
                            team sees every arrival.
                        </p>
                    </div>
                </div>
            </Container>
        </section>
    );
}
