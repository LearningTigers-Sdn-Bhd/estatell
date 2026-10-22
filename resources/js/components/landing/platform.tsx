import { Plus } from 'lucide-react';
import { Container } from './ui';
import { useScrollReveal } from './use-reveal';

const modules = [
    {
        title: 'Reservations',
        points: [
            'Room types, rates and availability',
            'Real-time booking status',
            'Direct and OTA bookings in one list',
            'Guest name and stay details per room',
        ],
    },
    {
        title: 'Front desk & check-in',
        points: [
            'Guest pre-check-in visible before arrival',
            'Documents and deposit captured in advance',
            "Optional self check-in from the guest's phone",
        ],
    },
    {
        title: 'Housekeeping',
        points: [
            'Priority task list for staff',
            'Guest requests arrive by room',
            'Track delivery and fulfillment time',
            'Complaints logged and routed',
        ],
    },
    {
        title: 'Dashboard & reports',
        points: [
            'Arrivals, departures and occupancy',
            'Bookings, guest conversations and F&B orders',
            'Pre-check-in completions at a glance',
        ],
    },
    {
        title: 'Guest database',
        points: [
            'Every direct booking builds a database you own',
            'Remarket without paying OTA commission again',
            'Know their preferences, welcome them back',
        ],
    },
    {
        title: 'E-invoicing',
        points: [
            'Compliant invoices and receipts on every booking',
            'Connected straight to the PMS',
            'No separate billing system',
        ],
    },
];

export function Platform() {
    const sectionRef = useScrollReveal();

    return (
        <section id="platform" className="scroll-mt-24 py-10 sm:py-14 lg:py-16">
            <Container>
                <div
                    ref={sectionRef}
                    className="grid gap-12 lg:grid-cols-[1fr_1fr] lg:gap-20"
                >
                    <div>
                        <p className="text-xs font-medium tracking-widest text-foreground/70 uppercase">
                            The Estatell platform
                        </p>
                        <h2 className="mt-5 max-w-lg text-4xl leading-none font-medium tracking-tighter sm:text-5xl">
                            Every detail.
                            <br />
                            One connected stay.
                        </h2>
                        <p className="mt-6 max-w-md text-base leading-relaxed text-foreground/70">
                            Bring your PMS, Channel Manager and Booking Engine
                            together. Less switching systems. More looking after
                            your guests.
                        </p>

                        <div className="mt-10 border-t border-border">
                            {modules.map((module, index) => (
                                <details
                                    key={module.title}
                                    name="platform-features"
                                    open={index === 0}
                                    className="group border-b border-border"
                                >
                                    <summary className="flex cursor-pointer list-none items-center justify-between gap-4 py-5 text-lg font-medium tracking-tight transition-colors group-open:text-primary hover:text-primary [&::-webkit-details-marker]:hidden">
                                        <h3>{module.title}</h3>
                                        <Plus
                                            className="size-4 shrink-0 transition-transform duration-200 group-open:rotate-45 motion-reduce:transition-none"
                                            strokeWidth={1.5}
                                            aria-hidden="true"
                                        />
                                    </summary>
                                    <ul className="grid gap-2 pr-7 pb-6 text-sm leading-relaxed text-foreground/70">
                                        {module.points.map((point) => (
                                            <li key={point}>{point}</li>
                                        ))}
                                    </ul>
                                </details>
                            ))}
                        </div>
                    </div>

                    <figure className="self-start lg:sticky lg:top-28">
                        <div className="relative aspect-4/3 overflow-hidden bg-muted lg:aspect-4/5">
                            <img
                                src="/images/landing/hotel-host.webp"
                                alt="A hotel host working at a timber reception desk in a sunlit boutique hotel."
                                className="absolute inset-0 size-full object-cover"
                            />
                        </div>
                        <figcaption className="mt-4 flex flex-col gap-2 border-t border-border pt-4 sm:flex-row sm:items-baseline sm:justify-between sm:gap-5">
                            <p className="text-sm font-medium">
                                Behind every effortless stay.
                            </p>
                            <span className="text-xs text-foreground/70">
                                The hotel operating core
                            </span>
                        </figcaption>
                    </figure>
                </div>
            </Container>
        </section>
    );
}
