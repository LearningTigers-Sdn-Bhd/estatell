import {
    Building2,
    CalendarRange,
    Copy,
    DoorOpen,
    Globe,
    Hash,
    Info,
    LayoutDashboard,
    Mail,
    MapPin,
    Phone,
    ReceiptText,
    Tag,
    ToggleRight,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { PanelHeading } from '@/components/pms/panel-heading';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';

type Property = App.Data.Platform.AdminPropertyData;
type Overview = App.Data.Platform.AdminPropertyOverviewData;
type BookingWebsite = App.Data.Platform.AdminPropertyBookingWebsiteData;

const published: App.Data.Shared.BadgeData = {
    value: 'published',
    label: 'Published',
    tone: 'success',
};
const notPublished: App.Data.Shared.BadgeData = {
    value: 'not_published',
    label: 'Not published',
    tone: 'neutral',
};

/** One At a glance figure: a muted label with its icon, the number, and a short note. */
function Figure({
    icon: Icon,
    label,
    value,
    note,
}: {
    icon: LucideIcon;
    label: string;
    value: ReactNode;
    note: string;
}) {
    return (
        <div className="flex flex-col gap-0.5 rounded-lg bg-muted/50 p-3">
            <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Icon aria-hidden className="size-3.5 shrink-0" />
                {label}
            </span>
            <span className="font-heading text-2xl font-medium tabular-nums">
                {value}
            </span>
            <span className="text-xs text-muted-foreground">{note}</span>
        </div>
    );
}

/** A Property details row: icon and label in a fixed column, the value left-aligned beside it so a long one wraps under itself. */
function DetailRow({
    icon: Icon,
    label,
    wide = false,
    children,
}: {
    icon: LucideIcon;
    label: string;
    wide?: boolean;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'grid grid-cols-[9rem_minmax(0,1fr)] items-baseline gap-3 text-sm',
                wide && 'md:col-span-2',
            )}
        >
            <dt className="flex items-center gap-2 text-muted-foreground">
                <Icon aria-hidden className="size-4 shrink-0 self-center" />
                {label}
            </dt>
            <dd className="min-w-0 wrap-break-word">{children}</dd>
        </div>
    );
}

function NotSet() {
    return <span className="text-muted-foreground">Not set</span>;
}

/**
 * The booking website line: the microsite address (a link once published) with a copy button, or API only.
 */
function BookingWebsiteValue({ site }: { site: BookingWebsite }) {
    const [, copy] = useClipboard();

    if (site.webAddress === null) {
        return <span>API only: the owner's own website</span>;
    }

    const address = site.webAddress.replace(/^https?:\/\//, '');

    return (
        <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            {site.published ? (
                <a
                    href={site.webAddress}
                    target="_blank"
                    rel="noreferrer"
                    className="break-all underline underline-offset-4"
                >
                    {address}
                </a>
            ) : (
                <span className="break-all text-muted-foreground">
                    {address}
                </span>
            )}
            <Tooltip>
                <TooltipTrigger
                    render={
                        <Button
                            variant="ghost"
                            size="icon-xs"
                            aria-label="Copy link"
                            onClick={async () => {
                                if (await copy(site.webAddress ?? '')) {
                                    toast.add({
                                        title: 'Link copied.',
                                        type: 'success',
                                    });
                                }
                            }}
                        />
                    }
                >
                    <Copy />
                </TooltipTrigger>
                <TooltipContent>Copy link</TooltipContent>
            </Tooltip>
            <StatusBadge status={site.published ? published : notPublished} />
        </span>
    );
}

/**
 * The Overview tab body, shared by the admin and partner property pages. With `overview` it starts with the At a
 * glance figures and shows the booking website line while Booking engine is on.
 */
export function PropertyOverview({
    property,
    overview,
}: {
    property: Property;
    overview?: Overview;
}) {
    const cityLine = [property.postalCode, property.city]
        .filter(Boolean)
        .join(' ');
    const address = [
        property.addressLine1,
        property.addressLine2,
        [
            cityLine,
            property.stateCode,
            property.countryName ?? property.countryCode,
        ]
            .filter(Boolean)
            .join(', '),
    ].filter(Boolean);

    return (
        <div className="flex flex-col gap-6">
            {overview && (
                <section className="flex flex-col gap-4">
                    <PanelHeading
                        icon={LayoutDashboard}
                        title="At a glance"
                        description="How far this property is set up, and whether it is in use."
                    />
                    <div className="grid grid-cols-2 gap-2 lg:grid-cols-5">
                        <Figure
                            icon={DoorOpen}
                            label="Rooms"
                            value={overview.rooms}
                            note={
                                overview.roomTypes === 1
                                    ? '1 room type'
                                    : `${overview.roomTypes} room types`
                            }
                        />
                        <Figure
                            icon={Tag}
                            label="Rate plans"
                            value={overview.ratePlans}
                            note="Not archived"
                        />
                        <Figure
                            icon={CalendarRange}
                            label="Bookings"
                            value={overview.bookingsLast30Days}
                            note="Made in the last 30 days"
                        />
                        <Figure
                            icon={Users}
                            label="Staff"
                            value={property.staffCount}
                            note={`${overview.staffVerified} verified`}
                        />
                        <Figure
                            icon={ToggleRight}
                            label="Features"
                            value={
                                <>
                                    {overview.featuresOn}
                                    <span className="text-sm text-muted-foreground">
                                        {' '}
                                        of {overview.featuresTotal}
                                    </span>
                                </>
                            }
                            note="On"
                        />
                    </div>
                </section>
            )}

            <section className="flex flex-col gap-4">
                <PanelHeading
                    icon={Info}
                    title="Property details"
                    description="The address, contact and registration the owner gave for this property."
                />
                <dl className="grid grid-cols-1 gap-x-8 gap-y-3 px-2 md:grid-cols-2">
                    <DetailRow icon={MapPin} label="Address">
                        {address.length > 0 ? (
                            address.map((line) => <p key={line}>{line}</p>)
                        ) : (
                            <NotSet />
                        )}
                    </DetailRow>
                    <DetailRow icon={Building2} label="Type">
                        {property.type ?? <NotSet />}
                    </DetailRow>
                    <DetailRow icon={Mail} label="Contact email">
                        {property.contactEmail ? (
                            <span className="break-all">
                                {property.contactEmail}
                            </span>
                        ) : (
                            <NotSet />
                        )}
                    </DetailRow>
                    <DetailRow icon={Phone} label="Contact phone">
                        {property.contactPhone ?? <NotSet />}
                    </DetailRow>
                    <DetailRow icon={Hash} label="Registration">
                        {property.registrationNumber ?? <NotSet />}
                    </DetailRow>
                    <DetailRow icon={ReceiptText} label="TIN">
                        {property.tin ?? <NotSet />}
                    </DetailRow>
                    {overview?.bookingWebsite && (
                        <DetailRow icon={Globe} label="Booking website" wide>
                            <BookingWebsiteValue
                                site={overview.bookingWebsite}
                            />
                        </DetailRow>
                    )}
                </dl>
            </section>
        </div>
    );
}
