import { Head } from '@inertiajs/react';
import { DoorOpen } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { filterStays } from '@/components/pms/stay-view/filter-stays';
import {
    roomDays,
    showStates,
    summarizeDay,
} from '@/components/pms/stay-view/room-day';
import { RoomsBoard } from '@/components/pms/stay-view/rooms-board';
import { StayPeekSheet } from '@/components/pms/stay-view/stay-peek-sheet';
import { StayViewFilters } from '@/components/pms/stay-view/stay-view-filters';
import { StayViewToolbar } from '@/components/pms/stay-view/stay-view-toolbar';
import {
    dayQuery,
    queryString,
} from '@/components/pms/stay-view/stay-view-url';
import type { DateWindow } from '@/components/pms/stay-view/stay-view-url';
import { rooms as roomsRoute } from '@/routes/front-desk';

/** The order of the day summary line. */
const SUMMARY_ORDER: App.Enums.RoomDayState[] = [
    'arriving',
    'departing',
    'in_house',
    'vacant',
    'blocked',
];

/**
 * A Board page: the toolbar, the day summary and one card for each room or bed on one date. The cards, the filters and
 * the summary are derived in the browser from the loaded data; only another date asks the server again.
 */
export default function Rooms({
    window: stayWindow,
    rooms,
    stays,
    blocks,
    filters: initialFilters,
    groupingOptions,
    statusOptions,
    showOptions,
    stateBadges,
    currency,
    canViewReservations,
}: {
    window: App.Data.StayView.StayViewWindowData;
    rooms: App.Data.StayView.RoomRowData[];
    stays: App.Data.StayView.StayData[];
    blocks: App.Data.StayView.BlockData[];
    filters: App.Data.StayView.StayViewFiltersData;
    groupingOptions: { value: string; label: string }[];
    statusOptions: { value: string; label: string }[];
    showOptions: { value: string; label: string }[];
    stateBadges: App.Data.Shared.BadgeData[];
    currency: App.Data.Property.CurrencyData | null;
    canViewReservations: boolean;
}) {
    const [filters, setFilters] = useState(initialFilters);
    const [peekUnitId, setPeekUnitId] = useState<number | null>(null);
    const date = stayWindow.from;
    const range: DateWindow = { from: date, to: date };
    const badges = Object.fromEntries(
        stateBadges.map((badge) => [badge.value, badge]),
    );

    const hrefFor = (target: DateWindow) =>
        roomsRoute.url() + queryString(dayQuery(target.from, filters));

    // The filters live in the URL so a view can be bookmarked. Only the address changes; nothing is requested.
    useEffect(() => {
        window.history.replaceState(window.history.state, '', hrefFor(range));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filters, date]);

    const visible = filterStays(rooms, stays, filters);
    const days = roomDays(visible.rooms, visible.stays, blocks, date);
    const summary = summarizeDay(days);
    const cards = showStates(days, filters.show);

    return (
        <div className="flex flex-col gap-4">
            <Head title="Room View" />
            {rooms.length === 0 ? (
                <EmptyState
                    icon={DoorOpen}
                    title="No rooms yet"
                    description="Add a room type and its rooms in settings, then the rooms show here."
                />
            ) : (
                <>
                    <StayViewToolbar
                        view="rooms"
                        window={range}
                        today={stayWindow.businessDate}
                        filters={filters}
                        onFiltersChange={setFilters}
                        groupingOptions={groupingOptions}
                        showOptions={showOptions}
                        hrefFor={hrefFor}
                        onScrollToToday={() => undefined}
                        filtersButton={
                            <StayViewFilters
                                rooms={rooms}
                                filters={filters}
                                onFiltersChange={setFilters}
                                statusOptions={statusOptions}
                                className="w-auto"
                                description="Choose which rooms show on the board."
                            />
                        }
                    />
                    <p className="flex flex-wrap gap-x-2 text-sm text-muted-foreground tabular-nums">
                        {SUMMARY_ORDER.map((state, index) => (
                            <span key={state}>
                                {index > 0 && <span aria-hidden>· </span>}
                                {badges[state]?.label} {summary[state]}
                            </span>
                        ))}
                    </p>
                    {cards.length === 0 ? (
                        <EmptyState
                            icon={DoorOpen}
                            title="No rooms match"
                            description="Change the filters to see more rooms."
                        />
                    ) : (
                        <RoomsBoard
                            days={cards}
                            grouping={filters.groupBy}
                            badges={badges}
                            onOpenStay={setPeekUnitId}
                        />
                    )}
                    <StayPeekSheet
                        stay={
                            stays.find((stay) => stay.unitId === peekUnitId) ??
                            null
                        }
                        stays={stays}
                        rooms={rooms}
                        currency={currency}
                        canViewReservations={canViewReservations}
                        onSelect={setPeekUnitId}
                        onClose={() => setPeekUnitId(null)}
                    />
                </>
            )}
        </div>
    );
}

Rooms.layout = {
    breadcrumbs: [{ title: 'Room View', href: roomsRoute() }],
};
