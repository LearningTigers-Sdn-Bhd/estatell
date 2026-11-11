import { useEffect, useState } from 'react';
import { CheckinPhoneArt, PinArt } from './pin-art';
import type { Visual } from './services';
import { prefersReducedMotion, useStaggerReveal } from './use-reveal';

const otas = [
    { name: 'Booking.com', src: '/images/landing/ota/bookingcom.png' },
    { name: 'Agoda', src: '/images/landing/ota/agoda.png' },
    { name: 'Expedia', src: '/images/landing/ota/expedia.png' },
    { name: 'Airbnb', src: '/images/landing/ota/airbnb.png' },
    { name: 'Traveloka', src: '/images/landing/ota/traveloka.png' },
    { name: 'Trip.com', src: '/images/landing/ota/tripcom.png' },
];

// Steps: 0 every channel open, 1 one channel sells the room, 2 the others close, 3 hold, then loop.
function useSyncStep() {
    const [step, setStep] = useState(0);

    useEffect(() => {
        if (prefersReducedMotion()) {
            setStep(2);

            return;
        }

        const timer = setInterval(
            () => setStep((value) => (value + 1) % 4),
            1500,
        );

        return () => clearInterval(timer);
    }, []);

    return step;
}

function SyncPanel() {
    const step = useSyncStep();

    return (
        <figure>
            <div
                aria-hidden="true"
                className="rounded-2xl bg-foreground p-5 text-background shadow-2xl shadow-background/40 sm:p-6"
            >
                <div className="flex items-baseline justify-between gap-4 border-b border-background/15 pb-4">
                    <span className="text-lg font-medium tracking-tight">
                        Deluxe king
                    </span>
                    <span className="text-sm text-background/70">
                        Availability by channel
                    </span>
                </div>
                <ul className="divide-y divide-background/10">
                    {otas.map((ota, index) => {
                        const sold = step >= 1 && index === 0;
                        const closed = step >= 2 && index !== 0;

                        return (
                            <li
                                key={ota.name}
                                className="flex items-center justify-between gap-4 py-3"
                            >
                                <img
                                    src={ota.src}
                                    alt=""
                                    width={88}
                                    height={28}
                                    loading="eager"
                                    className="h-6 w-auto max-w-24 object-contain mix-blend-multiply"
                                />
                                <span
                                    className={`inline-flex min-w-20 justify-center rounded-md px-3 py-1 text-xs font-medium transition-colors duration-500 motion-reduce:transition-none ${
                                        sold
                                            ? 'bg-brand-forest text-foreground'
                                            : closed
                                              ? 'bg-background/15 text-background'
                                              : 'text-background/70'
                                    }`}
                                >
                                    {sold ? 'Sold' : closed ? 'Closed' : 'Open'}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            </div>
            <figcaption className="mt-3 text-xs text-foreground/70">
                Illustrative. A room sold on one channel closes on the others.
            </figcaption>
        </figure>
    );
}

const chat = [
    {
        from: 'Guest',
        text: 'Hi! Is there parking, and what time is breakfast?',
    },
    {
        from: 'Hotel',
        text: 'Guest parking is beside the lobby. Breakfast is served from 7 to 10 am in the ground-floor restaurant.',
    },
    { from: 'Guest', text: 'Perfect, thank you. That’s everything we needed.' },
];

function ChatPanel() {
    const ref = useStaggerReveal(0.45);

    return (
        <figure>
            <div
                ref={ref}
                className="rounded-2xl border border-foreground/15 bg-foreground/5 p-5 sm:p-6"
            >
                <p className="border-b border-foreground/15 pb-4 text-sm text-foreground/75">
                    AI Concierge on WhatsApp
                </p>
                <ul className="mt-5 flex flex-col gap-3">
                    {chat.map((message) => (
                        <li
                            key={message.text}
                            data-stagger
                            className={`max-w-[85%] rounded-2xl px-4 py-3 text-sm leading-relaxed ${
                                message.from === 'Guest'
                                    ? 'self-end rounded-br-sm bg-primary text-primary-foreground'
                                    : 'self-start rounded-bl-sm bg-foreground/10 text-foreground'
                            }`}
                        >
                            <span className="sr-only">{message.from}: </span>
                            {message.text}
                        </li>
                    ))}
                </ul>
            </div>
            <figcaption className="mt-3 text-xs text-foreground/70">
                Illustrative conversation.
            </figcaption>
        </figure>
    );
}

function PhonePanel() {
    const [step, setStep] = useState(0);

    useEffect(() => {
        const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
        let timer: ReturnType<typeof setInterval> | undefined;
        const start = () => {
            clearInterval(timer);
            if (motion.matches) {
                setStep(2);
                return;
            }
            timer = setInterval(
                () => setStep((value) => (value + 1) % 3),
                3200,
            );
        };
        start();
        motion.addEventListener('change', start);
        return () => {
            clearInterval(timer);
            motion.removeEventListener('change', start);
        };
    }, []);

    return (
        <figure className="mx-auto w-full max-w-60">
            <svg
                viewBox="-12 -8 184 320"
                role="img"
                aria-label="Self check-in: scan the lobby QR, verify identity and payment, then receive your room PIN."
                className="w-full overflow-visible drop-shadow-xl"
            >
                <CheckinPhoneArt step={step} />
            </svg>
        </figure>
    );
}

export function ServiceVisual({ visual }: { visual: Visual }) {
    switch (visual.type) {
        case 'photo':
            return (
                <figure className="max-w-lg lg:ml-auto">
                    <img
                        src={visual.src}
                        alt={visual.alt}
                        width={visual.width}
                        height={visual.height}
                        loading="eager"
                        fetchPriority="high"
                        className="relative h-auto w-full rounded-2xl object-cover"
                    />
                </figure>
            );
        case 'pin':
            return <PinArt className="mx-auto w-full max-w-sm" />;
        case 'chat':
            return <ChatPanel />;
        case 'sync':
            return <SyncPanel />;
        case 'phone':
            return <PhonePanel />;
    }
}
