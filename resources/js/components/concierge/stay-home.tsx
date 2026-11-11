import { Link } from '@inertiajs/react';
import {
    BookOpen,
    SprayCan,
    ChevronDown,
    CircleAlert,
    CircleCheck,
    ConciergeBell,
    FileText,
    ListChecks,
    LogOut,
    MapPin,
    Phone,
    Sparkles,
    Wifi,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    amenities,
    bill,
    nearby,
    requests,
    stayContact,
    stayInfo,
} from '@/routes/concierge';

type Stay = App.Data.Concierge.ConciergeStayData;

const tileClass =
    'flex items-start gap-3 rounded-lg border p-4 transition-colors hover:bg-muted';

function Tile({
    title,
    body,
    icon: Icon,
    href,
    done,
}: {
    title: string;
    body: string;
    icon: LucideIcon;
    href?: string;
    /** A tile that only shows a state, such as "Check-out asked". */
    done?: boolean;
}) {
    const content = (
        <>
            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <Icon className="size-5" aria-hidden />
            </span>
            <span className="flex flex-col gap-0.5">
                <span className="font-medium">{title}</span>
                <span className="text-sm text-pretty text-muted-foreground">
                    {body}
                </span>
            </span>
        </>
    );

    return href && !done ? (
        <Link href={href} className={tileClass}>
            {content}
        </Link>
    ) : (
        <div className={tileClass}>{content}</div>
    );
}

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-3">
            <h2 className="text-lg font-semibold">{title}</h2>
            <div className="flex flex-col gap-3">{children}</div>
        </section>
    );
}

/** The screen of a guest who is checked in: their stay, the things they do most, the property's services and their documents. */
export function StayHome({ stay }: { stay: Stay }) {
    const token = stay.token;

    return (
        <div className="flex flex-col gap-6">
            <dl className="grid grid-cols-3 gap-3 rounded-lg border p-4 text-sm">
                <div className="flex flex-col gap-0.5">
                    <dt className="text-muted-foreground">Booking</dt>
                    <dd className="font-medium break-all">{stay.reference}</dd>
                </div>
                <div className="flex flex-col gap-0.5">
                    <dt className="text-muted-foreground">Stay</dt>
                    <dd className="font-medium">
                        {stay.arrivalDate} to {stay.departureDate}
                    </dd>
                </div>
                <div className="flex flex-col gap-0.5">
                    <dt className="text-muted-foreground">Nights</dt>
                    <dd className="font-medium tabular-nums">{stay.nights}</dd>
                </div>
            </dl>

            <Section title="Stay actions">
                <Tile
                    title="Housekeeping"
                    body="Cleaning or supplies"
                    icon={SprayCan}
                    href={requests.url(
                        { token },
                        { query: { type: 'housekeeping' } },
                    )}
                />
                <Tile
                    title="Report an issue"
                    body="Tell us what is wrong"
                    icon={CircleAlert}
                    href={requests.url(
                        { token },
                        { query: { type: 'complaint' } },
                    )}
                />
                {stay.checkOutAsked ? (
                    <Tile
                        title="Check-out asked"
                        body="The front desk has your request."
                        icon={CircleCheck}
                        done
                    />
                ) : (
                    <Tile
                        title="Check out"
                        body="See your bill, pay and check out"
                        icon={LogOut}
                        href={bill.url({ token })}
                    />
                )}
            </Section>

            <Section title="Property services">
                <Tile
                    title="Wi-Fi"
                    body="The network names and passwords"
                    icon={Wifi}
                    href={stayInfo.url({ token, section: 'wifi' })}
                />
                <Tile
                    title="Contact us"
                    body="Phone, email and the map"
                    icon={Phone}
                    href={stayContact.url({ token })}
                />
                <Tile
                    title="Nearby places"
                    body="Places worth a visit close by"
                    icon={MapPin}
                    href={nearby.url({ token })}
                />
                <Tile
                    title="Property guide"
                    body="About the property, policies and FAQs"
                    icon={BookOpen}
                    href={stayInfo.url({ token })}
                />
                <Tile
                    title="Hotel facilities"
                    body="What the property offers"
                    icon={Sparkles}
                    href={amenities.url({ token })}
                />
            </Section>

            <details className="group rounded-lg border">
                <summary className="flex cursor-pointer items-center justify-between gap-3 p-4 font-medium">
                    <span className="flex items-center gap-3">
                        <ListChecks
                            className="size-5 text-muted-foreground"
                            aria-hidden
                        />
                        More actions
                    </span>
                    <ChevronDown
                        className="size-4 transition-transform group-open:rotate-180"
                        aria-hidden
                    />
                </summary>
                <ul className="flex flex-col gap-2 border-t p-3">
                    <li>
                        <Link
                            href={requests.url({ token })}
                            className="flex items-center gap-3 rounded-lg p-2 text-sm hover:bg-muted"
                        >
                            <ConciergeBell
                                className="size-5 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                            My requests
                        </Link>
                    </li>
                    <li>
                        <Link
                            href={bill.url({ token })}
                            className="flex items-center gap-3 rounded-lg p-2 text-sm hover:bg-muted"
                        >
                            <FileText
                                className="size-5 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                            My bill
                        </Link>
                    </li>
                    {stay.documents.map((document) => (
                        <li key={document.url}>
                            <a
                                href={document.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="flex items-center gap-3 rounded-lg p-2 text-sm hover:bg-muted"
                            >
                                <FileText
                                    className="size-5 shrink-0 text-muted-foreground"
                                    aria-hidden
                                />
                                {document.label}
                            </a>
                        </li>
                    ))}
                </ul>
            </details>
        </div>
    );
}
