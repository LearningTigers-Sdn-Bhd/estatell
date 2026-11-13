import {
    Check,
    ChevronDown,
    Copy,
    ExternalLink,
    LogIn,
    LogOut,
    MapPin,
    MessageCircleQuestion,
    Search,
    Wifi,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useClipboard } from '@/hooks/use-clipboard';

type Property = App.Data.Concierge.ConciergePropertyData;
type Policies = App.Data.Concierge.ConciergePoliciesData;

function TextSection({ title, text }: { title: string; text: string }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    <h2>{title}</h2>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-sm leading-7 whitespace-pre-wrap">{text}</p>
            </CardContent>
        </Card>
    );
}

function Times({ checkIn, checkOut }: { checkIn: string; checkOut: string }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    <h2>Arrival & departure</h2>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <dl className="grid grid-cols-2 divide-x">
                    <div className="flex flex-col gap-2 pr-4">
                        <dt className="flex items-center gap-2 text-sm text-muted-foreground">
                            <LogIn className="size-4" aria-hidden />
                            Check-in from
                        </dt>
                        <dd className="font-heading text-3xl font-medium tabular-nums">
                            {checkIn}
                        </dd>
                    </div>
                    <div className="flex flex-col gap-2 pl-4">
                        <dt className="flex items-center gap-2 text-sm text-muted-foreground">
                            <LogOut className="size-4" aria-hidden />
                            Check-out by
                        </dt>
                        <dd className="font-heading text-3xl font-medium tabular-nums">
                            {checkOut}
                        </dd>
                    </div>
                </dl>
            </CardContent>
        </Card>
    );
}

function Facts({ facts }: { facts: [string, string | null][] }) {
    return (
        <dl className="flex flex-col divide-y">
            {facts
                .filter(([, value]) => value)
                .map(([label, value]) => (
                    <div
                        key={label}
                        className="grid gap-1 py-3 first:pt-0 last:pb-0 sm:grid-cols-3 sm:gap-4"
                    >
                        <dt className="text-sm text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="text-sm leading-6 whitespace-pre-wrap sm:col-span-2">
                            {value}
                        </dd>
                    </div>
                ))}
        </dl>
    );
}

function Transport({
    transport,
}: {
    transport: App.Data.Concierge.ConciergeTransportData;
}) {
    const travel: [string, string | null][] = [
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
    ];
    const parking: [string, string | null][] = [
        ['Availability', transport.parkingAvailability],
        ['Parking type', transport.parkingType],
        ['Price', transport.parkingPrice],
        [
            'Spaces',
            transport.parkingSpaces ? String(transport.parkingSpaces) : null,
        ],
        ['Height limit', transport.parkingHeightLimit],
        ['EV charging', transport.parkingEvCharging ? 'Available' : null],
        ['Reservation', transport.parkingBookingRequired ? 'Book ahead' : null],
    ];

    return (
        <div className="grid gap-6 lg:grid-cols-2">
            {(transport.directions ||
                transport.notes ||
                travel.some(([, value]) => value)) && (
                <Card>
                    <CardHeader>
                        <CardTitle>
                            <h2>Getting here</h2>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-5">
                        {transport.directions && (
                            <p className="text-sm leading-7 whitespace-pre-wrap">
                                {transport.directions}
                            </p>
                        )}
                        <Facts facts={travel} />
                        {transport.notes && (
                            <p className="text-sm leading-6 whitespace-pre-wrap text-muted-foreground">
                                {transport.notes}
                            </p>
                        )}
                    </CardContent>
                </Card>
            )}
            {(transport.parkingNotes || parking.some(([, value]) => value)) && (
                <Card>
                    <CardHeader>
                        <CardTitle>
                            <h2>Parking</h2>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-5">
                        <Facts facts={parking} />
                        {transport.parkingNotes && (
                            <p className="text-sm leading-7 whitespace-pre-wrap">
                                {transport.parkingNotes}
                            </p>
                        )}
                    </CardContent>
                </Card>
            )}
        </div>
    );
}

export function PropertyOverview({ details }: { details: Property }) {
    return (
        <div className="flex flex-col gap-6">
            <div className="grid gap-6 lg:grid-cols-2">
                <Times
                    checkIn={details.checkInTime}
                    checkOut={details.checkOutTime}
                />
                {(details.addressLines.length > 0 || details.mapsUrl) && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                <h2 className="flex items-center gap-2">
                                    <MapPin className="size-4" aria-hidden />
                                    Find us
                                </h2>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col items-start gap-4">
                            {details.addressLines.length > 0 && (
                                <address className="text-sm leading-6 not-italic">
                                    {details.addressLines.join(', ')}
                                </address>
                            )}
                            {details.mapsUrl && (
                                <a
                                    href={details.mapsUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className={buttonVariants({
                                        variant: 'outline',
                                        className: 'min-h-11',
                                    })}
                                >
                                    Open in maps
                                    <ExternalLink
                                        data-icon="inline-end"
                                        aria-hidden
                                    />
                                    <span className="sr-only">
                                        Opens in a new tab
                                    </span>
                                </a>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
            {details.about && (
                <TextSection title="About the property" text={details.about} />
            )}
            {(details.arrivalInstructions || details.departureInstructions) && (
                <div className="grid gap-6 lg:grid-cols-2">
                    {details.arrivalInstructions && (
                        <TextSection
                            title="When you arrive"
                            text={details.arrivalInstructions}
                        />
                    )}
                    {details.departureInstructions && (
                        <TextSection
                            title="Before you leave"
                            text={details.departureInstructions}
                        />
                    )}
                </div>
            )}
            {details.transport && <Transport transport={details.transport} />}
            {details.topics.length > 0 && (
                <div className="grid gap-6 lg:grid-cols-2">
                    {details.topics.map((topic) => (
                        <TextSection
                            key={topic.id}
                            title={topic.title}
                            text={topic.body}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

export function PropertyPolicies({ policies }: { policies: Policies }) {
    return (
        <div className="flex flex-col gap-6">
            <div className="grid gap-6 lg:grid-cols-2">
                <Times
                    checkIn={policies.checkInTime}
                    checkOut={policies.checkOutTime}
                />
                {(policies.smoking || policies.pets) && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                <h2>Good to know</h2>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Facts
                                facts={[
                                    ['Smoking', policies.smoking],
                                    ['Pets', policies.pets],
                                ]}
                            />
                        </CardContent>
                    </Card>
                )}
            </div>
            <TextSection
                title="House rules"
                text={
                    policies.houseRules ||
                    'The property has not listed house rules. Ask the front desk.'
                }
            />
            <div className="grid gap-6 lg:grid-cols-2">
                {(
                    [
                        ['Room terms', policies.roomPolicy],
                        ['Payment and deposits', policies.paymentPolicy],
                        ['Other policies', policies.otherPolicies],
                    ] as [string, string | null][]
                ).map(
                    ([title, text]) =>
                        text && (
                            <TextSection
                                key={title}
                                title={title}
                                text={text}
                            />
                        ),
                )}
                {policies.terms.map((term) => (
                    <TextSection
                        key={term.label}
                        title={term.label}
                        text={term.text}
                    />
                ))}
            </div>
        </div>
    );
}

export function PropertyFaqs({
    faqs,
}: {
    faqs: App.Data.Concierge.ConciergeFaqData[];
}) {
    const [query, setQuery] = useState('');
    const needle = query.trim().toLowerCase();
    const shown = faqs.filter(
        (faq) =>
            !needle ||
            `${faq.question} ${faq.answer}`.toLowerCase().includes(needle),
    );
    const topics = [...new Set(shown.map((faq) => faq.topic ?? ''))];

    if (faqs.length === 0) {
        return (
            <EmptyState
                icon={MessageCircleQuestion}
                title="No questions listed yet"
                description="If you need help, get in touch with the front desk."
            />
        );
    }

    return (
        <div className="flex max-w-3xl flex-col gap-6">
            {faqs.length >= 6 && (
                <Input
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Search questions"
                    aria-label="Search questions"
                    className="min-h-11"
                />
            )}
            {shown.length === 0 && (
                <EmptyState
                    icon={Search}
                    title="No matching questions"
                    description="Try a different word, or ask the front desk."
                />
            )}
            {topics.map((topic) => (
                <section key={topic} className="flex flex-col gap-3">
                    {topic && (
                        <h2 className="font-heading text-lg font-medium">
                            {topic}
                        </h2>
                    )}
                    {shown
                        .filter((faq) => (faq.topic ?? '') === topic)
                        .map((faq) => (
                            <details
                                key={faq.id}
                                className="group overflow-hidden rounded-xl border bg-card"
                            >
                                <summary className="flex min-h-14 cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                                    {faq.question}
                                    <ChevronDown
                                        className="size-4 shrink-0 transition-transform group-open:rotate-180"
                                        aria-hidden
                                    />
                                </summary>
                                <p className="border-t px-5 py-4 text-sm leading-7 whitespace-pre-wrap">
                                    {faq.answer}
                                </p>
                            </details>
                        ))}
                </section>
            ))}
        </div>
    );
}

function WifiNetwork({
    network,
}: {
    network: App.Data.Concierge.ConciergeWifiData;
}) {
    const [copied, copy] = useClipboard();

    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    <h2 className="flex flex-wrap items-center gap-2">
                        <Wifi className="size-4" aria-hidden />
                        {network.name}
                        {network.primary && (
                            <Badge variant="secondary">Main network</Badge>
                        )}
                    </h2>
                </CardTitle>
                {network.note && (
                    <CardDescription>{network.note}</CardDescription>
                )}
            </CardHeader>
            <CardContent>
                {network.password ? (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-muted/50 p-4">
                        <div className="flex min-w-0 flex-col gap-1">
                            <p className="text-xs text-muted-foreground">
                                Password
                            </p>
                            <p className="font-mono text-lg break-all">
                                {network.password}
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => copy(network.password ?? '')}
                        >
                            {copied === network.password ? (
                                <Check data-icon="inline-start" />
                            ) : (
                                <Copy data-icon="inline-start" />
                            )}
                            {copied === network.password
                                ? 'Copied'
                                : 'Copy password'}
                        </Button>
                    </div>
                ) : (
                    <p className="text-sm leading-6 text-muted-foreground">
                        No password. Connect and follow the prompt.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

export function PropertyWifi({
    wifi,
}: {
    wifi: App.Data.Concierge.ConciergeWifiData[];
}) {
    return wifi.length === 0 ? (
        <EmptyState
            icon={Wifi}
            title="No Wi-Fi details listed"
            description="Ask the front desk for the network and password."
        />
    ) : (
        <div className="grid gap-6 lg:grid-cols-2">
            {wifi.map((network) => (
                <WifiNetwork key={network.id} network={network} />
            ))}
        </div>
    );
}
