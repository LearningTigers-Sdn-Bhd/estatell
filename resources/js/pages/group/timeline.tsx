import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ExternalLink } from 'lucide-react';
import { useRef, useState } from 'react';
import { formatDate } from '@/components/pms/date-text';
import type { DateGridHandle } from '@/components/pms/grid/date-grid';
import { PageHeader } from '@/components/pms/page-header';
import { StayViewTimeline } from '@/components/pms/stay-view/stay-view-timeline';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useInitials } from '@/hooks/use-initials';
import { timeline as propertyTimeline } from '@/routes/front-desk';
import {
    dashboard as groupDashboard,
    timeline as groupTimeline,
} from '@/routes/group';
import { show } from '@/routes/reservations';

type Block = App.Data.Group.GroupTimelinePropertyData;
type Group = { id: number; name: string };

const FILTERS: App.Data.StayView.StayViewFiltersData = {
    roomGroupIds: [],
    roomTypeIds: [],
    statuses: [],
    search: '',
    groupBy: 'room_type',
    show: [],
};

/** A `Y-m-d` date moved by whole days, in UTC so it never shifts. */
function addDays(date: string, days: number): string {
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + days);

    return value.toISOString().slice(0, 10);
}

/** The number of nights between two `Y-m-d` dates, counting both ends. */
function length(from: string, to: string): number {
    return (
        Math.round(
            (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) /
                86_400_000,
        ) + 1
    );
}

/**
 * One property's Timeline View, read only: bars open the booking in the property when the role there allows, and
 * every change happens in the property's own Timeline View.
 */
function PropertyTimeline({ block }: { block: Block }) {
    const gridRef = useRef<DateGridHandle>(null);
    const getInitials = useInitials();
    const { property } = block;
    const height = Math.min(Math.max(block.rooms.length, 3) * 48 + 96, 480);

    const openStay = (unitId: number) => {
        const stay = block.stays.find((item) => item.unitId === unitId);

        if (stay && block.canViewReservations) {
            router.visit(
                show.url({
                    property: property.code,
                    reservation: stay.reservationId,
                }),
            );
        }
    };

    return (
        <Card size="sm" className="gap-0 overflow-hidden py-0">
            <CardHeader className="border-b py-3">
                <CardTitle className="flex items-center gap-2">
                    <Avatar className="overflow-hidden rounded-md after:rounded-md">
                        <AvatarImage
                            src={property.logoUrl ?? undefined}
                            alt=""
                            className="rounded-md object-contain"
                        />
                        <AvatarFallback className="rounded-md bg-primary text-xs font-semibold text-primary-foreground">
                            {getInitials(property.name)}
                        </AvatarFallback>
                    </Avatar>
                    {property.name}
                </CardTitle>
                <CardDescription>
                    ID {property.code} · {block.stays.length} stays ·{' '}
                    {block.rooms.length} rooms and beds
                </CardDescription>
                <CardAction>
                    <Link
                        href={
                            propertyTimeline.url({ property: property.code }) +
                            `?from=${block.window.from}&to=${block.window.to}`
                        }
                        className={buttonVariants({
                            variant: 'outline',
                            size: 'sm',
                        })}
                    >
                        Open Timeline View
                        <ExternalLink data-icon="inline-end" />
                    </Link>
                </CardAction>
            </CardHeader>
            <CardContent
                className="relative flex flex-col p-0"
                style={{ height }}
            >
                {block.rooms.length === 0 ? (
                    <p className="p-4 text-sm text-muted-foreground">
                        No rooms yet.
                    </p>
                ) : (
                    <StayViewTimeline
                        gridRef={gridRef}
                        window={block.window}
                        weekendDays={block.weekendDays}
                        rooms={block.rooms}
                        stays={block.stays}
                        blocks={block.blocks}
                        dayUses={block.dayUses}
                        rates={[]}
                        filters={FILTERS}
                        showRates={false}
                        rateChoice={{ ratePlanId: null }}
                        currency={null}
                        canViewReservations={false}
                        canManageReservations={false}
                        canOverrideRestrictions={false}
                        canWaiveFees={false}
                        canBlockRooms={false}
                        onOpenStay={openStay}
                        onBlockRoom={() => undefined}
                    />
                )}
            </CardContent>
        </Card>
    );
}

export default function GroupTimeline({
    group,
    from,
    to,
    properties,
}: {
    group: Group;
    from: string;
    to: string;
    properties: Block[];
}) {
    const [loading, setLoading] = useState(false);
    const days = length(from, to);
    const go = (start: string) =>
        router.get(
            groupTimeline.url({ propertyGroup: group.id }),
            { from: start, to: addDays(start, days - 1) },
            {
                preserveScroll: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );

    return (
        <>
            <Head title={`${group.name} timeline`} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Timeline View"
                    description="Every property's rooms and beds for the same dates. Open a booking or a property's own Timeline View to change anything."
                    actions={
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Earlier dates"
                                disabled={loading}
                                onClick={() => go(addDays(from, -days))}
                            >
                                <ChevronLeft />
                            </Button>
                            <span className="text-sm tabular-nums">
                                {formatDate(from)} – {formatDate(to)}
                            </span>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Later dates"
                                disabled={loading}
                                onClick={() => go(addDays(from, days))}
                            >
                                <ChevronRight />
                            </Button>
                        </div>
                    }
                />

                {properties.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Your role does not show the front desk at these
                        properties.
                    </p>
                ) : (
                    properties.map((block) => (
                        <PropertyTimeline
                            key={block.property.code}
                            block={block}
                        />
                    ))
                )}
            </div>
        </>
    );
}

GroupTimeline.layout = (props: { group: Group }) => ({
    breadcrumbs: [
        {
            title: props.group.name,
            href: groupDashboard({ propertyGroup: props.group.id }),
        },
        {
            title: 'Timeline View',
            href: groupTimeline({ propertyGroup: props.group.id }),
        },
    ],
});
