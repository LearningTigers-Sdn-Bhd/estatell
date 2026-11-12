import { Link } from '@inertiajs/react';
import {
    BedDouble,
    CalendarClock,
    Mail,
    Moon,
    Pencil,
    Phone,
    Store,
    Tag,
    TriangleAlert,
    User,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { show } from '@/routes/guests';

type Guest = App.Data.Guests.GuestData;
type Summary = App.Data.Guests.GuestStaySummaryData;

/** One label and value line, as in the reservation summary. */
function Row({
    icon: Icon,
    label,
    children,
}: {
    icon: LucideIcon;
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex items-baseline justify-between gap-3 text-sm">
            <dt className="flex shrink-0 items-center gap-2 text-muted-foreground">
                <Icon aria-hidden className="size-4 shrink-0 self-center" />
                {label}
            </dt>
            <dd className="min-w-0 text-right wrap-break-word">{children}</dd>
        </div>
    );
}

/** A phone or email line, or an amber "No email" when it is missing. */
function ContactLine({
    icon: Icon,
    value,
    missing,
}: {
    icon: LucideIcon;
    value: string | null;
    missing: string;
}) {
    return (
        <p className="flex items-center gap-2 text-sm break-all">
            <Icon
                aria-hidden
                className="size-4 shrink-0 text-muted-foreground"
            />
            {value ?? (
                <span className="inline-flex items-center gap-1.5 text-warning">
                    {missing}
                    <TriangleAlert aria-hidden className="size-4" />
                </span>
            )}
        </p>
    );
}

/**
 * The side panel of the guest page: contact, the stay figures and tags. A missing email or phone asks staff to collect
 * it. The server counts every figure. See docs/11-guests/01-guest-profiles.md#stay-history.
 */
export function GuestSummary({
    guest,
    summary,
    currency,
    showEditLink,
}: {
    guest: Guest;
    summary: Summary;
    currency: App.Data.Property.CurrencyData | null;
    /** The Details tab is the edit form, so the link shows on the other tabs. */
    showEditLink: boolean;
}) {
    const archived = guest.anonymizedAt !== null;
    const missingContact = !archived && (!guest.email || !guest.phone);
    const noStays = summary.stays === 0;

    return (
        <div className="flex flex-col gap-4">
            <section className="flex flex-col gap-1 ps-3 pt-2">
                <h2 className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                    <User aria-hidden className="size-4" />
                    Contact
                </h2>
                {archived ? (
                    <p className="text-sm text-muted-foreground">
                        Removed when the guest was archived.
                    </p>
                ) : (
                    <>
                        <ContactLine
                            icon={Phone}
                            value={guest.phone}
                            missing="No phone"
                        />
                        <ContactLine
                            icon={Mail}
                            value={guest.email}
                            missing="No email"
                        />
                    </>
                )}
                {missingContact && (
                    <div className="flex flex-wrap items-center justify-between gap-2 pt-1">
                        <p className="text-sm text-muted-foreground">
                            Ask at the next stay.
                        </p>
                        {showEditLink && (
                            <Link
                                href={show.url({ guest: guest.id })}
                                className={buttonVariants({
                                    variant: 'outline',
                                    size: 'sm',
                                })}
                            >
                                <Pencil data-icon="inline-start" />
                                Edit details
                            </Link>
                        )}
                    </div>
                )}
            </section>
            <Separator />
            <dl className="flex flex-col gap-2">
                <Row icon={BedDouble} label="Stays">
                    {summary.stays}
                    {!noStays && (
                        <span className="block text-xs text-muted-foreground">
                            {summary.staysLastYear} in the last 12 months
                        </span>
                    )}
                </Row>
                <Row icon={Moon} label="Nights">
                    {summary.nights}
                    {summary.dayUses > 0 && (
                        <span className="block text-xs text-muted-foreground">
                            {summary.dayUses} day{' '}
                            {summary.dayUses === 1 ? 'use' : 'uses'}
                        </span>
                    )}
                </Row>
                <Row icon={Wallet} label="Spend">
                    <span className="font-semibold">
                        <MoneyText amount={summary.spend} currency={currency} />
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        Without tax
                    </span>
                </Row>
                <Row icon={CalendarClock} label="Last visit">
                    {summary.inHouse ? (
                        'In house now'
                    ) : summary.lastVisit ? (
                        <DateText date={summary.lastVisit} />
                    ) : (
                        '—'
                    )}
                </Row>
                <Row icon={Store} label="Source">
                    {summary.source ?? '—'}
                    {summary.sourceKind && (
                        <span className="block text-xs text-muted-foreground">
                            {summary.sourceKind}
                        </span>
                    )}
                </Row>
            </dl>
            <Separator />
            <section className="flex flex-col gap-2">
                <h2 className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                    <Tag aria-hidden className="size-4" />
                    Tags
                </h2>
                {guest.tags.length > 0 ? (
                    <span className="flex flex-wrap gap-1">
                        {guest.tags.map((tag) => (
                            <Badge key={tag.id} variant="outline">
                                {tag.name}
                            </Badge>
                        ))}
                    </span>
                ) : (
                    <p className="text-sm text-muted-foreground">No tags</p>
                )}
            </section>
        </div>
    );
}
