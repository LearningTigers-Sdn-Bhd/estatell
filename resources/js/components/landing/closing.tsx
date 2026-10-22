import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowRight, MessageCircle, Copy, Check } from 'lucide-react';
import { Container } from './ui';
import { useScrollReveal, useStaggerReveal } from './use-reveal';
import { privacy, terms } from '@/routes';

const contacts = [
    {
        label: 'WhatsApp',
        value: '+6011-6202 3998',
        copy: '+601162023998',
        href: 'https://wa.me/601162023998',
    },
    {
        label: 'Email',
        value: 'hello@estatell.com',
        copy: 'hello@estatell.com',
        href: 'mailto:hello@estatell.com',
    },
    {
        label: 'Register',
        value: 'estatell.com/register',
        copy: 'https://estatell.com/register',
        href: 'https://estatell.com/register',
    },
];

export function Closing() {
    const copyRef = useScrollReveal();
    const contactsRef = useScrollReveal(120);
    const wordmarkRef = useStaggerReveal(
        0.09,
        '[data-wordmark-letter]',
        'translate-y-full',
        true,
    );

    return (
        <>
            {/* CTA section */}
            <section
                id="contact"
                aria-labelledby="contact-heading"
                className="dark scroll-mt-24 bg-brand-forest pt-10 pb-8 text-foreground sm:pt-14 sm:pb-10"
            >
                <Container>
                    <div ref={copyRef}>
                        <p className="text-xs font-medium tracking-widest text-chart-1 uppercase">
                            Ready when you are
                        </p>
                        <div className="mt-6 grid gap-8 lg:grid-cols-[1.2fr_0.8fr] lg:items-end lg:gap-16">
                            <h2
                                id="contact-heading"
                                className="text-[clamp(2.5rem,12vw,3rem)] leading-none font-medium tracking-tighter sm:text-7xl lg:text-8xl"
                            >
                                Less OTA.
                                <br />
                                <span className="text-chart-1">
                                    More direct.
                                </span>
                            </h2>
                            <p className="max-w-sm text-base leading-relaxed text-foreground/80 lg:pb-2">
                                Lower your OTA dependency. Grow your net revenue
                                with a core built for independent and boutique
                                hotels, and architected to scale to hotel
                                groups.
                            </p>
                        </div>
                        <div className="mt-10 flex flex-wrap items-center gap-4 sm:gap-6">
                            <a
                                href="https://estatell.com/register"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex min-h-14 w-full items-center justify-center gap-8 rounded-full bg-chart-1 px-7 py-4 text-sm font-medium text-background transition-colors hover:bg-foreground motion-reduce:transition-none sm:w-auto"
                            >
                                Register today
                                <span className="sr-only">
                                    {' '}
                                    (opens in a new tab)
                                </span>
                                <ArrowRight
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </a>
                            <a
                                href="https://wa.me/601162023998"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex min-h-12 w-full items-center justify-center gap-3 px-1 py-3 text-sm font-medium text-foreground underline decoration-border underline-offset-8 transition-colors hover:text-chart-1 hover:decoration-chart-1 motion-reduce:transition-none sm:w-auto"
                            >
                                <MessageCircle
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Chat on WhatsApp
                                <span className="sr-only">
                                    {' '}
                                    (opens in a new tab)
                                </span>
                            </a>
                        </div>
                    </div>

                    <div
                        ref={contactsRef}
                        className="mt-12 grid border-t border-border sm:mt-16 lg:mt-20 lg:grid-cols-3"
                    >
                        {contacts.map((contact) => (
                            <div
                                key={contact.label}
                                className="flex min-w-0 items-center justify-between gap-3 border-b border-border py-5 last:border-b-0 sm:py-6 lg:border-r lg:border-b-0 lg:px-6 lg:first:pl-0 lg:last:border-r-0 lg:last:pr-0"
                            >
                                <a
                                    href={contact.href}
                                    target={
                                        contact.href.startsWith('http')
                                            ? '_blank'
                                            : undefined
                                    }
                                    rel="noopener noreferrer"
                                    className="flex min-h-11 min-w-0 flex-col justify-center py-1 transition-colors hover:text-chart-1 motion-reduce:transition-none"
                                >
                                    <span className="block text-xs text-foreground/80">
                                        {contact.label}
                                    </span>
                                    <span className="mt-2 block text-sm font-medium [overflow-wrap:anywhere]">
                                        {contact.value}
                                    </span>
                                    {contact.href.startsWith('http') && (
                                        <span className="sr-only">
                                            {' '}
                                            (opens in a new tab)
                                        </span>
                                    )}
                                </a>
                                <CopyButton
                                    text={contact.copy}
                                    label={contact.label}
                                />
                            </div>
                        ))}
                    </div>
                </Container>
            </section>

            {/* Footer */}
            <footer className="dark bg-brand-forest pb-[calc(2rem+env(safe-area-inset-bottom))] text-sm text-foreground/80 sm:text-xs">
                <Container>
                    <div
                        ref={wordmarkRef}
                        className="[container-type:inline-size] pt-8 pb-8 sm:pt-12 sm:pb-10"
                    >
                        <p
                            className={`text-center font-archivo-black text-[21cqw] leading-none font-normal tracking-tighter text-brand-forest [-webkit-text-stroke:2px_var(--foreground)] sm:[-webkit-text-stroke:4px_var(--foreground)]`}
                        >
                            <span className="sr-only">Estatell</span>
                            <span
                                aria-hidden="true"
                                className="inline-block overflow-hidden px-1 align-top"
                            >
                                {Array.from('Estatell', (letter, index) => (
                                    <span
                                        key={index}
                                        data-wordmark-letter
                                        className="inline-block"
                                    >
                                        {letter}
                                    </span>
                                ))}
                            </span>
                        </p>
                    </div>
                </Container>
                <Container className="flex flex-col gap-5 border-t border-border pt-6 lg:flex-row lg:items-center lg:justify-end lg:gap-10">
                    <nav
                        className="flex flex-wrap gap-x-5 gap-y-2"
                        aria-label="Footer"
                    >
                        <a
                            href="#platform"
                            className="inline-flex min-h-11 items-center transition-colors hover:text-foreground"
                        >
                            Platform
                        </a>
                        <a
                            href="#channels"
                            className="inline-flex min-h-11 items-center transition-colors hover:text-foreground"
                        >
                            Channels
                        </a>
                        <a
                            href="#plans"
                            className="inline-flex min-h-11 items-center transition-colors hover:text-foreground"
                        >
                            Pricing
                        </a>
                        <a
                            href="#contact"
                            className="inline-flex min-h-11 items-center transition-colors hover:text-foreground"
                        >
                            Contact
                        </a>
                        <Link
                            href={terms()}
                            className="inline-flex min-h-11 items-center transition-colors hover:text-foreground"
                        >
                            Terms and Conditions
                        </Link>
                        <Link
                            href={privacy()}
                            className="inline-flex min-h-11 items-center transition-colors hover:text-foreground"
                        >
                            Privacy Policy
                        </Link>
                    </nav>
                </Container>
            </footer>
        </>
    );
}

function CopyButton({ text, label }: { text: string; label: string }) {
    const [status, setStatus] = useState<'idle' | 'copied' | 'error'>('idle');

    useEffect(() => {
        if (status === 'idle') return;
        const timer = window.setTimeout(() => setStatus('idle'), 2000);
        return () => window.clearTimeout(timer);
    }, [status]);

    return (
        <>
            <button
                type="button"
                aria-label={`Copy ${label}`}
                onClick={async () => {
                    try {
                        await navigator.clipboard.writeText(text);
                        setStatus('copied');
                    } catch {
                        setStatus('error');
                    }
                }}
                className="flex size-11 shrink-0 items-center justify-center rounded-full text-foreground/80 transition-colors duration-200 hover:bg-muted hover:text-chart-1 motion-reduce:transition-none"
            >
                {status === 'copied' ? (
                    <Check
                        className="size-4"
                        strokeWidth={2.5}
                        aria-hidden="true"
                    />
                ) : (
                    <Copy className="size-4" aria-hidden="true" />
                )}
            </button>
            <span role="status" className="sr-only">
                {status === 'copied'
                    ? `${label} copied.`
                    : status === 'error'
                      ? `Could not copy ${label}. Select and copy the contact text instead.`
                      : ''}
            </span>
        </>
    );
}
