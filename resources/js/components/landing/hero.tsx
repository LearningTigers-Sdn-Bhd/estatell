import { ArrowRight } from 'lucide-react';
import { useHeroTimeline } from './use-reveal';
import { LivingHotel } from './living-hotel';
import { WelcomeScreen } from './welcome-screen';

export function Hero() {
    const containerRef = useHeroTimeline();

    return (
        <>
            <WelcomeScreen />
            <section
                aria-labelledby="hero-heading"
                className="dark relative isolate flex min-h-dvh flex-col overflow-hidden bg-background text-foreground"
            >
                <img
                    src="/images/landing/boutique-hotel-lobby.webp"
                    alt=""
                    className="hero-background absolute inset-0 size-full object-cover object-[60%_center]"
                />
                <div
                    aria-hidden="true"
                    className="absolute inset-0 bg-background/45"
                />
                <div
                    aria-hidden="true"
                    className="absolute inset-0 bg-gradient-to-r from-background/95 via-background/65 to-background/15"
                />
                <div
                    aria-hidden="true"
                    className="absolute inset-0 bg-gradient-to-t from-background via-transparent to-background/20"
                />

                <div
                    ref={containerRef}
                    className="relative mx-auto flex w-full max-w-7xl flex-1 flex-col justify-center px-5 pt-28 pb-8 sm:px-8 sm:pt-32 sm:pb-10 [@media(max-height:600px)]:pt-24"
                >
                    <div className="grid items-center gap-5 lg:grid-cols-[1fr_1fr] lg:gap-8 [@media(max-height:699px)]:grid-cols-1">
                        <div className="max-w-xl min-w-0">
                            <p
                                data-hero-eyebrow
                                className="text-xs font-medium tracking-widest text-chart-1 uppercase"
                            >
                                The hotel operating core
                            </p>
                            <h1
                                id="hero-heading"
                                data-hero-headline
                                className="mt-5 text-[clamp(2.5rem,5vw,4.5rem)] leading-none font-medium tracking-tighter text-foreground [@media(max-height:600px)]:text-4xl"
                            >
                                Your hotel.
                                <br />
                                <span className="text-chart-1">
                                    One connected core.
                                </span>
                            </h1>
                            <p
                                data-hero-lede
                                className="mt-6 max-w-md text-base leading-relaxed text-foreground/75"
                            >
                                Run your hotel from one system, not five.
                                Property Management, Channel Manager and Booking
                                Engine — unified with AI, automation and
                                self-service. Built for independent and boutique
                                hotels.
                            </p>
                            <div
                                data-hero-cta
                                className="mt-7 flex flex-wrap items-center gap-x-6 gap-y-3"
                            >
                                <a
                                    href="#contact"
                                    className="inline-flex min-h-12 w-full items-center justify-center gap-3 rounded-full bg-chart-1 px-4 py-3 text-sm font-medium text-background transition-colors hover:bg-foreground motion-reduce:transition-none sm:w-auto sm:gap-4 sm:px-5"
                                >
                                    Book a 20-minute walkthrough
                                    <ArrowRight
                                        className="size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                </a>
                            </div>
                        </div>

                        <div className="mx-auto hidden w-44 min-w-0 sm:w-56 lg:block lg:w-[min(100%,54dvh)] [@media(max-height:699px)]:hidden [@media(min-height:850px)]:block">
                            <LivingHotel />
                        </div>
                    </div>

                    <div
                        data-hero-stats
                        className="mt-10 grid max-w-xl grid-cols-3 gap-3 border-t border-border pt-5 sm:gap-8 [@media(max-height:600px)]:mt-6"
                    >
                        {[
                            { value: '3-in-1', label: 'Core systems' },
                            { value: '24/7', label: 'AI coverage' },
                            { value: '9+', label: 'OTA channels' },
                        ].map((stat) => (
                            <div
                                key={stat.label}
                                className="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:gap-3"
                            >
                                <span className="text-xl font-medium tracking-tight text-foreground">
                                    {stat.value}
                                </span>
                                <span className="text-xs text-foreground/75 sm:text-sm">
                                    {stat.label}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            </section>
        </>
    );
}
