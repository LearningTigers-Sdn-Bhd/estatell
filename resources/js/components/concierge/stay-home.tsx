import { Link } from '@inertiajs/react';
import { DocumentList } from '@/components/concierge/checked-out-step';
import {
    BookOpen,
    SprayCan,
    ChevronDown,
    ChevronRight,
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
    'group flex min-w-0 items-center gap-3 border-b border-border/30 px-2 py-5 transition-colors hover:bg-muted/50 focus-visible:rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';

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
            <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-muted/60 text-primary">
                <Icon className="size-5" aria-hidden />
            </span>
            <span className="flex min-w-0 flex-1 flex-col gap-1">
                <span className="font-medium">{title}</span>
                <span className="text-sm text-pretty text-muted-foreground">
                    {body}
                </span>
            </span>
            {href && !done && (
                <ChevronRight
                    className="size-4 shrink-0 text-muted-foreground"
                    aria-hidden
                />
            )}
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
        <section className="flex flex-col gap-1">
            <h2 className="text-lg font-semibold">{title}</h2>
            <div className="grid gap-x-6 sm:grid-cols-2 lg:grid-cols-3">
                {children}
            </div>
        </section>
    );
}

/** The screen of a guest who is checked in: their stay, the things they do most, the property's services and their documents. */
export function StayHome({ stay }: { stay: Stay }) {
    const token = stay.token;

    return (
        <div className="flex flex-col gap-8 sm:gap-10">
            <dl className="grid grid-cols-2 gap-x-6 gap-y-5 text-sm sm:grid-cols-4">
                <div className="flex flex-col gap-0.5">
                    <dt className="text-muted-foreground">Booking</dt>
                    <dd className="font-medium break-all">{stay.reference}</dd>
                </div>
                <div className="flex flex-col gap-0.5">
                    <dt className="text-muted-foreground">Arrival</dt>
                    <dd className="font-medium tabular-nums">
                        {stay.arrivalDate}
                    </dd>
                </div>
                <div className="flex flex-col gap-0.5">
                    <dt className="text-muted-foreground">Departure</dt>
                    <dd className="font-medium tabular-nums">
                        {stay.departureDate}
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

            <details className="group rounded-xl border border-border/40">
                <summary className="flex cursor-pointer items-center justify-between gap-3 p-4 font-medium">
                    <span className="flex items-center gap-3">
                        <ListChecks
                            className="size-5 text-muted-foreground"
                            aria-hidden
                        />
                        Requests, bill &amp; documents
                    </span>
                    <ChevronDown
                        className="size-4 transition-transform group-open:rotate-180"
                        aria-hidden
                    />
                </summary>
                <div className="flex flex-col gap-6 border-t border-border/40 p-5 sm:p-6">
                    <nav
                        aria-label="Requests and bill"
                        className="grid gap-4 sm:grid-cols-2"
                    >
                        <Link
                            href={requests.url({ token })}
                            className="flex min-h-20 items-center gap-3 rounded-xl bg-muted/40 p-4 transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                        >
                            <ConciergeBell
                                className="size-5 shrink-0 text-primary"
                                aria-hidden
                            />
                            <span className="flex min-w-0 flex-1 flex-col gap-1">
                                <span className="text-sm font-medium">
                                    My requests
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    See status and front desk updates
                                </span>
                            </span>
                            <ChevronRight
                                className="size-4 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                        </Link>
                        <Link
                            href={bill.url({ token })}
                            className="flex min-h-20 items-center gap-3 rounded-xl bg-muted/40 p-4 transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                        >
                            <FileText
                                className="size-5 shrink-0 text-primary"
                                aria-hidden
                            />
                            <span className="flex min-w-0 flex-1 flex-col gap-1">
                                <span className="text-sm font-medium">
                                    My bill
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    Charges, payments and check-out
                                </span>
                            </span>
                            <ChevronRight
                                className="size-4 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                        </Link>
                    </nav>
                    <DocumentList documents={stay.documents} />
                </div>
            </details>
        </div>
    );
}
