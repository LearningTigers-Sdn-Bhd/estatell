import { useState } from 'react';
import { Layers, Globe2, Radio } from 'lucide-react';
import { Container } from './ui';
import { useScrollReveal } from './use-reveal';

const tiers = [
    { band: '0 – 40%', rate: 200 },
    { band: '40 – 60%', rate: 225 },
    { band: '60 – 80%', rate: 250 },
    { band: '80 – 100%', rate: 300 },
];

const points = [
    {
        icon: Layers,
        title: 'Pre-set tiers',
        body: 'Define the rate for each occupancy band once.',
    },
    {
        icon: Globe2,
        title: 'Price by guest market',
        body: 'Set rates by where guests come from, such as Europe or Asia, and hold your rate for the markets you want to attract.',
    },
    {
        icon: Radio,
        title: 'Pushed to every channel',
        body: 'The Channel Manager sends new rates to OTAs and your direct channel.',
    },
];

export function PricingEngine() {
    const [occupancy, setOccupancy] = useState(65);
    const activeIndex =
        occupancy < 40 ? 0 : occupancy < 60 ? 1 : occupancy < 80 ? 2 : 3;
    const activeRate = tiers[activeIndex].rate;

    const demoRef = useScrollReveal();
    const copyRef = useScrollReveal(120);

    return (
        <section
            id="pricing-engine"
            aria-labelledby="pricing-engine-heading"
            className="scroll-mt-24 py-10 sm:py-14"
        >
            <Container>
                <div
                    ref={copyRef}
                    className="grid gap-6 lg:grid-cols-[1.1fr_0.9fr] lg:items-end lg:gap-20"
                >
                    <div>
                        <p className="text-xs font-medium tracking-widest text-primary uppercase">
                            Dynamic pricing
                        </p>
                        <h2
                            id="pricing-engine-heading"
                            className="mt-5 text-4xl leading-none font-medium tracking-tighter sm:text-5xl xl:text-6xl"
                        >
                            Demand changes.
                            <br />
                            <span className="text-primary">
                                Your rates keep up.
                            </span>
                        </h2>
                    </div>
                    <p className="max-w-md text-base leading-relaxed text-foreground/70">
                        Set occupancy-based rate tiers once. The engine adjusts
                        pricing automatically as occupancy changes, then pushes
                        the new rate to every connected channel.
                    </p>
                </div>

                <div
                    ref={demoRef}
                    className="mt-12 grid gap-8 border-y border-border py-8 sm:py-10 lg:grid-cols-[1.1fr_0.9fr] lg:gap-20"
                >
                    <div>
                        <p className="text-xs font-medium tracking-widest text-foreground/70 uppercase">
                            Try the pricing engine
                        </p>
                        <output
                            htmlFor="occupancy"
                            className="mt-5 flex flex-wrap items-baseline gap-x-4 gap-y-2"
                            aria-live="off"
                        >
                            <span className="text-5xl leading-none font-medium tracking-tighter text-primary tabular-nums sm:text-7xl">
                                RM {activeRate}
                            </span>
                            <span className="text-sm text-foreground/70">
                                Current room rate
                            </span>
                        </output>
                        <div className="mt-8">
                            <label
                                htmlFor="occupancy"
                                className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2 text-sm"
                            >
                                <span className="font-medium">
                                    Current occupancy
                                </span>
                                <span className="shrink-0 text-xl font-medium tabular-nums">
                                    {occupancy}%
                                </span>
                            </label>
                            <input
                                id="occupancy"
                                type="range"
                                min={0}
                                max={100}
                                step={5}
                                value={occupancy}
                                onChange={(e) =>
                                    setOccupancy(Number(e.target.value))
                                }
                                aria-valuetext={`${occupancy}% occupancy, room rate RM ${activeRate}`}
                                className="mt-2 h-11 w-full cursor-pointer accent-primary"
                            />
                            <div
                                className="flex justify-between text-xs text-foreground/70"
                                aria-hidden="true"
                            >
                                <span>0% occupancy</span>
                                <span>100%</span>
                            </div>
                        </div>
                        <p className="mt-5 text-xs leading-relaxed text-foreground/70">
                            Illustrative rates. Move the slider to see the
                            active tier change.
                        </p>
                    </div>

                    <table className="w-full self-center text-left text-sm">
                        <caption className="sr-only">
                            Example occupancy-based rate tiers
                        </caption>
                        <thead>
                            <tr className="border-b border-border text-xs text-foreground/70">
                                <th
                                    scope="col"
                                    className="px-3 pb-4 font-normal"
                                >
                                    Occupancy band
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 pb-4 text-right font-normal"
                                >
                                    Room rate
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {tiers.map((tier, index) => (
                                <tr
                                    key={tier.band}
                                    aria-current={
                                        index === activeIndex
                                            ? 'true'
                                            : undefined
                                    }
                                    className={`border-b border-border transition-colors last:border-b-0 motion-reduce:transition-none ${index === activeIndex ? 'bg-primary/5 text-primary' : 'text-foreground/70'}`}
                                >
                                    <th
                                        scope="row"
                                        className="px-3 py-4 font-normal"
                                    >
                                        <span className="inline-flex flex-wrap items-center gap-2">
                                            {tier.band}
                                            {index === activeIndex && (
                                                <span className="text-xs font-medium tracking-wide uppercase">
                                                    Active
                                                </span>
                                            )}
                                        </span>
                                    </th>
                                    <td className="px-3 py-4 text-right font-medium whitespace-nowrap tabular-nums">
                                        RM {tier.rate}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-9 grid gap-8 sm:grid-cols-3 sm:gap-6 lg:gap-12">
                    {points.map((point) => (
                        <div key={point.title}>
                            <point.icon
                                className="size-5 text-primary"
                                strokeWidth={1.5}
                                aria-hidden="true"
                            />
                            <h3 className="mt-4 text-base font-medium tracking-tight">
                                {point.title}
                            </h3>
                            <p className="mt-2 text-sm leading-relaxed text-foreground/70">
                                {point.body}
                            </p>
                        </div>
                    ))}
                </div>
            </Container>
        </section>
    );
}
