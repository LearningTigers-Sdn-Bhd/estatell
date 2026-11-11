import { Link } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
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
    const groups = Object.entries(
        amenities.reduce<Record<string, string[]>>((all, amenity) => {
            (all[amenity.category] ??= []).push(amenity.name);

            return all;
        }, {}),
    );

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
                    <Times
                        checkIn={details.checkInTime}
                        checkOut={details.checkOutTime}
                    />
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
                </div>
            )}

            {section === 'amenities' && (
                <div className="flex flex-col gap-4">
                    {groups.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            The property has not listed its facilities yet.
                        </p>
                    )}
                    {groups.map(([category, names]) => (
                        <section key={category} className="flex flex-col gap-2">
                            <h2 className="text-lg font-semibold">
                                {category}
                            </h2>
                            <ul className="flex flex-wrap gap-2">
                                {names.map((name) => (
                                    <li
                                        key={name}
                                        className="rounded-full border px-3 py-1 text-sm"
                                    >
                                        {name}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}
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
