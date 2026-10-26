import { useState } from 'react';
import { Container } from './ui';
import { useScrollReveal } from './use-reveal';

export function SavingsCalculator() {
    const [revenue, setRevenue] = useState(100_000);
    const [share, setShare] = useState(20);

    const copyRef = useScrollReveal();
    const calcRef = useScrollReveal(120);

    const moved = revenue * (share / 100);
    const saveLow = moved * (0.18 - 0.1);
    const saveHigh = moved * (0.25 - 0.1);

    return (
        <section
            id="save-ota"
            aria-labelledby="savings-heading"
            className="dark scroll-mt-24 bg-background py-10 text-foreground sm:py-14"
        >
            <Container>
                <div ref={copyRef} className="max-w-3xl">
                    <p className="text-xs font-medium tracking-widest text-chart-1 uppercase">
                        Direct booking platform
                    </p>
                    <h2
                        id="savings-heading"
                        className="mt-5 text-4xl leading-none font-medium tracking-tighter sm:text-5xl xl:text-6xl"
                    >
                        More direct.
                        <br />
                        <span className="text-chart-1">
                            More stays with you.
                        </span>
                    </h2>
                    <p className="mt-6 max-w-2xl text-base leading-relaxed text-foreground/75">
                        Bring more bookings to your hotel through WAStays and
                        WhatsApp, connected to Estatell. Keep more of every
                        stay.
                    </p>
                </div>

                <div className="mt-10 grid items-center gap-10 sm:mt-14 lg:grid-cols-[0.85fr_1.15fr] lg:gap-16">
                    <div className="order-2 grid gap-8 lg:order-1">
                        <div>
                            <span className="text-xs font-medium tracking-widest text-chart-1 uppercase">
                                More ways to find you
                            </span>
                            <h3 className="mt-3 text-2xl font-medium tracking-tight">
                                A booking platform.
                                <br />A conversation that converts.
                            </h3>
                            <p className="mt-4 max-w-md text-sm leading-relaxed text-foreground/75">
                                WAStays helps guests discover and book your
                                hotel. On WhatsApp, AI answers enquiries and
                                guides them through booking, any time of day.
                            </p>
                        </div>
                        <div className="border-t border-border pt-6">
                            <p className="text-lg font-medium tracking-tight">
                                Small shifts. More value per stay.
                            </p>
                            <p className="mt-3 max-w-md text-sm leading-relaxed text-foreground/75">
                                Move a share of your OTA bookings to WAStays and
                                WhatsApp to save 8–15% of that booking value.
                                Try your numbers to see the monthly difference.
                            </p>
                        </div>
                    </div>

                    <div
                        ref={calcRef}
                        className="order-1 rounded-3xl border border-border bg-muted/50 p-6 sm:p-8 lg:order-2"
                    >
                        <p className="text-xs font-medium tracking-widest text-foreground/75 uppercase">
                            Your savings potential
                        </p>
                        <output
                            htmlFor="revenue share"
                            className="mt-6 block"
                            aria-live="off"
                        >
                            <span className="block text-sm text-foreground/75">
                                Estimated monthly savings
                            </span>
                            <span className="mt-3 block text-[clamp(2rem,4.5vw,3.75rem)] leading-none font-medium tracking-tighter text-chart-1 tabular-nums">
                                RM {Math.round(saveLow).toLocaleString('en-MY')}
                            </span>
                            <span className="mt-3 block text-xl tracking-tight text-foreground/75 tabular-nums">
                                to RM{' '}
                                {Math.round(saveHigh).toLocaleString('en-MY')}
                            </span>
                        </output>

                        <div className="mt-9 border-t border-border pt-6">
                            <label
                                htmlFor="revenue"
                                className="flex flex-wrap items-baseline justify-between gap-2 text-sm"
                            >
                                <span className="text-foreground/75">
                                    Monthly OTA revenue
                                </span>
                                <span className="shrink-0 font-medium tabular-nums">
                                    RM {revenue.toLocaleString('en-MY')}
                                </span>
                            </label>
                            <input
                                id="revenue"
                                type="range"
                                min={10_000}
                                max={500_000}
                                step={10_000}
                                value={revenue}
                                onChange={(e) =>
                                    setRevenue(Number(e.target.value))
                                }
                                aria-valuetext={`RM ${revenue.toLocaleString('en-MY')}`}
                                className="mt-2 h-11 w-full cursor-pointer accent-primary [color-scheme:dark]"
                            />
                            <div
                                className="flex justify-between text-xs text-foreground/75"
                                aria-hidden="true"
                            >
                                <span>RM 10,000</span>
                                <span>RM 500,000</span>
                            </div>
                        </div>
                        <div className="mt-7">
                            <label
                                htmlFor="share"
                                className="flex items-baseline justify-between gap-3 text-sm"
                            >
                                <span className="text-foreground/75">
                                    Move to direct
                                </span>
                                <span className="font-medium tabular-nums">
                                    {share}%
                                </span>
                            </label>
                            <input
                                id="share"
                                type="range"
                                min={5}
                                max={50}
                                step={5}
                                value={share}
                                onChange={(e) =>
                                    setShare(Number(e.target.value))
                                }
                                aria-valuetext={`${share}% moved to direct`}
                                className="mt-2 h-11 w-full cursor-pointer accent-primary [color-scheme:dark]"
                            />
                            <div
                                className="flex justify-between text-xs text-foreground/75"
                                aria-hidden="true"
                            >
                                <span>5%</span>
                                <span>50%</span>
                            </div>
                        </div>
                        <p className="mt-7 border-t border-border pt-5 text-xs leading-relaxed text-foreground/75">
                            Based on moving {share}% of OTA revenue to WAStays
                            at a 10% fee, compared with 18–25% OTA commission.
                            Illustrative estimate.
                        </p>
                    </div>
                </div>
                <dl className="mt-12 grid gap-6 border-t border-border pt-8 sm:grid-cols-3 sm:gap-8">
                    <div>
                        <dt className="text-sm text-foreground/75">
                            WAStays &amp; WhatsApp
                        </dt>
                        <dd className="mt-3 text-4xl font-medium tracking-tight text-chart-1">
                            10%
                        </dd>
                        <p className="mt-2 text-xs leading-relaxed text-foreground/75">
                            Commission, including payment gateway fee
                        </p>
                    </div>
                    <div>
                        <dt className="text-sm text-foreground/75">
                            OTA commission
                        </dt>
                        <dd className="mt-3 text-4xl font-medium tracking-tight">
                            18–25%
                        </dd>
                        <p className="mt-2 text-xs leading-relaxed text-foreground/75">
                            Comparison range used in this estimate
                        </p>
                    </div>
                    <div>
                        <dt className="text-sm text-foreground/75">
                            Your own website
                        </dt>
                        <dd className="mt-3 text-4xl font-medium tracking-tight">
                            0%{' '}
                            <span className="text-base tracking-normal">
                                commission
                            </span>
                        </dd>
                        <p className="mt-2 text-xs leading-relaxed text-foreground/75">
                            Booking Engine fee applies
                        </p>
                    </div>
                </dl>
            </Container>
        </section>
    );
}
