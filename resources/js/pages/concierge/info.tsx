import { Link } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { AmenityGroups } from '@/components/concierge/amenity-groups';
import { GuestFrame } from '@/components/concierge/stay-menu';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';

type Section = { key: string; label: string; url: string };

function Times({ checkIn, checkOut }: { checkIn: string; checkOut: string }) {
    return (
        <dl className="grid grid-cols-2 gap-3">
            <div className="rounded-lg border p-4">
                <dt className="text-sm text-muted-foreground">Check-in from</dt>
                <dd className="text-lg font-semibold tabular-nums">
                    {checkIn}
                </dd>
            </div>
            <div className="rounded-lg border p-4">
                <dt className="text-sm text-muted-foreground">Check-out by</dt>
                <dd className="text-lg font-semibold tabular-nums">
                    {checkOut}
                </dd>
            </div>
        </dl>
    );
}

function TextSection({ title, text }: { title: string; text: string }) {
    return (
        <section className="flex flex-col gap-2">
            <h2 className="text-lg font-semibold">{title}</h2>
            <p className="text-sm leading-relaxed whitespace-pre-wrap">
                {text}
            </p>
        </section>
    );
}

function Transport({
    transport,
}: {
    transport: App.Data.Concierge.ConciergeTransportData;
}) {
    const facts = [
        ['Airport', transport.airport],
        ['Train station', transport.station],
        ['City centre', transport.cityCentre],
        ['Nearest stop', transport.nearestTransitStop],
        [
            'Airport transfer',
            transport.airportTransferOffered
                ? [
                      'Available',
                      transport.airportTransferPrice,
                      transport.airportTransferLead,
                  ]
                      .filter(Boolean)
                      .join(' · ')
                : null,
        ],
        ['Pickup point', transport.pickupPoint],
        ['Shuttle', transport.shuttleSchedule],
    ].filter(([, value]) => value) as [string, string][];

    return (
        <section className="flex flex-col gap-2">
            <h2 className="text-lg font-semibold">Getting here</h2>
            {transport.directions && (
                <p className="text-sm leading-relaxed whitespace-pre-wrap">
                    {transport.directions}
                </p>
            )}
            {facts.length > 0 && (
                <dl className="flex flex-col gap-2">
                    {facts.map(([label, value]) => (
                        <div
                            key={label}
                            className="flex justify-between gap-4 rounded-lg border p-3 text-sm"
                        >
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="text-right font-medium">{value}</dd>
                        </div>
                    ))}
                </dl>
            )}
            {transport.parkingNotes && (
                <p className="text-sm text-muted-foreground">
                    Parking: {transport.parkingNotes}
                </p>
            )}
            {transport.notes && (
                <p className="text-sm text-muted-foreground">
                    {transport.notes}
                </p>
            )}
        </section>
    );
}

function WifiNetwork({
    network,
}: {
    network: App.Data.Concierge.ConciergeWifiData;
}) {
    const [copied, copy] = useClipboard();

    return (
        <li className="flex flex-col gap-2 rounded-lg border p-4">
            <p className="font-medium">{network.name}</p>
            {network.note && (
                <p className="text-sm text-muted-foreground">{network.note}</p>
            )}
            {network.password ? (
                <div className="flex items-center justify-between gap-3">
                    <p className="font-mono text-lg tracking-wide">
                        {network.password}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => copy(network.password ?? '')}
                    >
                        {copied === network.password ? (
                            <Check data-icon="inline-start" />
                        ) : (
                            <Copy data-icon="inline-start" />
                        )}
                        {copied === network.password ? 'Copied' : 'Copy'}
                    </Button>
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">
                    No password. Connect and follow the prompt.
                </p>
            )}
        </li>
    );
}

/** The property guide: about the property, its facilities, policies and FAQs, and its Wi-Fi for a guest who is in the house. */
export default function ConciergeInfo({
    section,
    sections,
    back,
    details,
    policies,
    amenities,
    faqs,
    wifi,
}: {
    section: string;
    sections: Section[];
    back: { href: string; label: string };
    details: App.Data.Concierge.ConciergePropertyData | null;
    policies: App.Data.Concierge.ConciergePoliciesData | null;
    amenities: App.Data.Concierge.ConciergeAmenityData[];
    faqs: App.Data.Concierge.ConciergeFaqData[];
    wifi: App.Data.Concierge.ConciergeWifiData[];
}) {
    return (
        <GuestFrame
            back={back}
            title={
                sections.find((item) => item.key === section)?.label ?? 'Guide'
            }
        >
            <nav aria-label="Guide sections" className="flex flex-wrap gap-2">
                {sections.map((item) => (
                    <Link
                        key={item.key}
                        href={item.url}
                        aria-current={item.key === section ? 'page' : undefined}
                        className={cn(
                            'rounded-full border px-3 py-1 text-sm',
                            item.key === section
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-muted',
                        )}
                    >
                        {item.label}
                    </Link>
                ))}
            </nav>

            {section === 'property' && details && (
                <div className="flex flex-col gap-4">
                    {details.about && (
                        <TextSection
                            title="About the property"
                            text={details.about}
                        />
                    )}
                    <Times
                        checkIn={details.checkInTime}
                        checkOut={details.checkOutTime}
                    />
                    {details.arrivalInstructions && (
                        <TextSection
                            title="Arrival"
                            text={details.arrivalInstructions}
                        />
                    )}
                    {details.departureInstructions && (
                        <TextSection
                            title="Departure"
                            text={details.departureInstructions}
                        />
                    )}
                    {details.addressLines.length > 0 && (
                        <p className="text-pretty">
                            {details.addressLines.join(', ')}
                        </p>
                    )}
                    {details.mapsUrl && (
                        <a
                            href={details.mapsUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-sm underline underline-offset-4"
                        >
                            Open in maps
                        </a>
                    )}
                    {details.transport && (
                        <Transport transport={details.transport} />
                    )}
                    {details.topics.map((topic) => (
                        <TextSection
                            key={topic.id}
                            title={topic.title}
                            text={topic.body}
                        />
                    ))}
                </div>
            )}

            {section === 'policies' && policies && (
                <div className="flex flex-col gap-4">
                    <Times
                        checkIn={policies.checkInTime}
                        checkOut={policies.checkOutTime}
                    />
                    <section className="flex flex-col gap-2">
                        <h2 className="text-lg font-semibold">House rules</h2>
                        {policies.houseRules ? (
                            <p className="text-sm leading-relaxed whitespace-pre-wrap">
                                {policies.houseRules}
                            </p>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                The property has not listed house rules. Ask the
                                front desk.
                            </p>
                        )}
                    </section>
                    {policies.terms.map((term) => (
                        <section
                            key={term.label}
                            className="flex flex-col gap-2"
                        >
                            <h2 className="text-lg font-semibold">
                                {term.label}
                            </h2>
                            <p className="text-sm leading-relaxed whitespace-pre-wrap">
                                {term.text}
                            </p>
                        </section>
                    ))}
                </div>
            )}

            {section === 'amenities' && (
                <div className="flex flex-col gap-4">
                    <AmenityGroups amenities={amenities} />
                </div>
            )}

            {section === 'faqs' && (
                <div className="flex flex-col gap-3">
                    {faqs.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            The property has not listed questions yet.
                        </p>
                    )}
                    {faqs.map((faq) => (
                        <details key={faq.id} className="rounded-lg border p-4">
                            <summary className="cursor-pointer font-medium">
                                {faq.question}
                            </summary>
                            <p className="mt-2 text-sm leading-relaxed text-pretty">
                                {faq.answer}
                            </p>
                        </details>
                    ))}
                </div>
            )}

            {section === 'wifi' && (
                <div className="flex flex-col gap-3">
                    {wifi.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            The property has not listed a Wi-Fi network. Ask the
                            front desk.
                        </p>
                    )}
                    <ul className="flex flex-col gap-3">
                        {wifi.map((network) => (
                            <WifiNetwork key={network.id} network={network} />
                        ))}
                    </ul>
                </div>
            )}
        </GuestFrame>
    );
}
