import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ExternalLink, Info } from 'lucide-react';
import { useRef, useState } from 'react';
import { formatDate } from '@/components/pms/date-text';
import type { DateGridHandle } from '@/components/pms/grid/date-grid';
import {
    ReservationPeekSheet,
    type PeekTarget,
} from '@/components/pms/group/reservation-peek-sheet';
import { StayViewTimeline } from '@/components/pms/stay-view/stay-view-timeline';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button, buttonVariants } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useElementWidth } from '@/hooks/use-element-width';
import { useInitials } from '@/hooks/use-initials';
import { timeline as propertyTimeline } from '@/routes/front-desk';
import {
    dashboard as groupDashboard,
    timeline as groupTimeline,
} from '@/routes/group';

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

/** The compact Timeline View's label column, and the narrowest night of a stretched view. */
const COMPACT_LABEL_WIDTH = 176;
const MIN_WIDE_COLUMN = 64;

/** The first and last day of the month that holds the date. */
function monthOf(date: string): { from: string; to: string } {
    const [year, month] = date.split('-').map(Number);

    return {
        from: new Date(Date.UTC(year, month - 1, 1)).toISOString().slice(0, 10),
        to: new Date(Date.UTC(year, month, 0)).toISOString().slice(0, 10),
    };
}

/** `2026-11-01` as "November 2026". */
function monthName(date: string): string {
    const [year, month] = date.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, 1)).toLocaleDateString('en-GB', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
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
function PropertyTimeline({
    block,
    days,
    isMonth,
    onPeek,
}: {
    block: Block;
    days: number;
    isMonth: boolean;
    onPeek: (target: PeekTarget) => void;
}) {
    const gridRef = useRef<DateGridHandle>(null);
    const contentRef = useRef<HTMLDivElement>(null);
    const width = useElementWidth(contentRef);
    const getInitials = useInitials();
    const { property } = block;
    const height = Math.min(Math.max(block.rooms.length, 3) * 36 + 96, 520);
    // The month is compact; a shorter range stretches its nights across the card, so the names on the bars show.
    const columnWidth =
        isMonth || width === 0
            ? undefined
            : Math.max(
                  MIN_WIDE_COLUMN,
                  Math.floor((width - COMPACT_LABEL_WIDTH) / days),
              );

    const openStay = (unitId: number) => {
        const stay = block.stays.find((item) => item.unitId === unitId);

        if (stay && block.canViewReservations) {
            onPeek({
                propertyCode: property.code,
                reservationId: stay.reservationId,
            });
        }
    };

    return (
        <Card size="sm" className="gap-0 overflow-hidden py-0">
            <CardHeader className="border-b py-2">
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
                    <Tooltip>
                        <TooltipTrigger
                            render={
                                <button
                                    type="button"
                                    aria-label={`About ${property.name}`}
                                    className="rounded-full text-muted-foreground outline-none hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50"
                                />
                            }
                        >
                            <Info className="size-4" />
                        </TooltipTrigger>
                        <TooltipContent>
                            ID {property.code} · {block.stays.length} stays ·{' '}
                            {block.rooms.length} rooms and beds
                        </TooltipContent>
                    </Tooltip>
                </CardTitle>
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
                ref={contentRef}
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
                        facilities={[]}
                        facilityCells={[]}
                        filters={FILTERS}
                        showRates={false}
                        rateChoice={{ ratePlanId: null }}
                        currency={null}
                        canViewReservations={false}
                        canViewEvents={false}
                        canManageReservations={false}
                        canOverrideRestrictions={false}
                        canWaiveFees={false}
                        canBlockRooms={false}
                        onOpenStay={openStay}
                        onBlockRoom={() => undefined}
                        density="compact"
                        columnWidth={columnWidth}
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
    today,
    properties,
}: {
    group: Group;
    from: string;
    to: string;
    today: string;
    properties: Block[];
}) {
    const [loading, setLoading] = useState(false);
    const [peek, setPeek] = useState<PeekTarget>(null);
    const days = length(from, to);
    const month = monthOf(from);
    const isMonth = from === month.from && to === month.to;
    const visit = (start: string, end: string) =>
        router.get(
            groupTimeline.url({ propertyGroup: group.id }),
            { from: start, to: end },
            {
                preserveScroll: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );
    const go = (direction: -1 | 1) => {
        if (isMonth) {
            const next = monthOf(
                addDays(direction === 1 ? to : from, direction),
            );

            visit(next.from, next.to);

            return;
        }

        const start = addDays(from, direction * days);

        visit(start, addDays(start, days - 1));
    };

    return (
        <>
            <Head title={`${group.name} timeline`} />

            <div className="flex flex-1 flex-col gap-3 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label={isMonth ? 'Earlier month' : 'Earlier dates'}
                        disabled={loading}
                        onClick={() => go(-1)}
                    >
                        <ChevronLeft />
                    </Button>
                    <span className="min-w-44 text-center text-sm font-medium tabular-nums">
                        {isMonth
                            ? monthName(from)
                            : `${formatDate(from)} – ${formatDate(to)}`}
                    </span>
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label={isMonth ? 'Later month' : 'Later dates'}
                        disabled={loading}
                        onClick={() => go(1)}
                    >
                        <ChevronRight />
                    </Button>
                    <ToggleGroup
                        value={[isMonth ? 'month' : 'weeks']}
                        onValueChange={(value: string[]) => {
                            if (value[0] === 'month' && !isMonth) {
                                visit(month.from, month.to);
                            } else if (value[0] === 'weeks' && isMonth) {
                                // From today when the month holds it, else from the month's first day.
                                const start =
                                    today >= from && today <= to ? today : from;

                                visit(start, addDays(start, 13));
                            }
                        }}
                        variant="outline"
                        size="sm"
                        className="ml-auto"
                    >
                        <ToggleGroupItem value="month">Month</ToggleGroupItem>
                        <ToggleGroupItem value="weeks">2 weeks</ToggleGroupItem>
                    </ToggleGroup>
                </div>

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
                            days={days}
                            isMonth={isMonth}
                            onPeek={setPeek}
                        />
                    ))
                )}
            </div>
            <ReservationPeekSheet
                groupId={group.id}
                target={peek}
                onClose={() => setPeek(null)}
            />
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
