import { Head } from '@inertiajs/react';
import { ChartGantt } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import type { DateGridHandle } from '@/components/pms/grid/date-grid';
import { StayPeekSheet } from '@/components/pms/stay-view/stay-peek-sheet';
import { StayViewFilters } from '@/components/pms/stay-view/stay-view-filters';
import { StayViewTimeline } from '@/components/pms/stay-view/stay-view-timeline';
import { StayViewToolbar } from '@/components/pms/stay-view/stay-view-toolbar';
import {
    queryString,
    viewQuery,
} from '@/components/pms/stay-view/stay-view-url';
import type { DateWindow } from '@/components/pms/stay-view/stay-view-url';
import {
    planChoices,
    resolveChoice,
} from '@/components/pms/stay-view/nightly-rate';
import { useStayViewPreferences } from '@/components/pms/stay-view/use-stay-view-preferences';
import { timeline } from '@/routes/front-desk';

/**
 * A Grid calendar page: no page header. The toolbar is the top of the page and the grid takes the rest of the window.
 */
export default function Timeline({
    window: stayWindow,
    rooms,
    stays,
    blocks,
    dayUses,
    rates,
    filters: initialFilters,
    groupingOptions,
    statusOptions,
    weekendDays,
    currency,
    canViewReservations,
    canManageReservations,
}: {
    window: App.Data.StayView.StayViewWindowData;
    rooms: App.Data.StayView.RoomRowData[];
    stays: App.Data.StayView.StayData[];
    blocks: App.Data.StayView.BlockData[];
    dayUses: App.Data.StayView.DayUseData[];
    rates: App.Data.StayView.RatePriceRowData[];
    filters: App.Data.StayView.StayViewFiltersData;
    groupingOptions: { value: string; label: string }[];
    statusOptions: { value: string; label: string }[];
    weekendDays: number[];
    currency: App.Data.Property.CurrencyData | null;
    canViewReservations: boolean;
    canManageReservations: boolean;
}) {
    const gridRef = useRef<DateGridHandle>(null);
    const [filters, setFilters] = useState(initialFilters);
    const [peekUnitId, setPeekUnitId] = useState<number | null>(null);
    const [preferences, updatePreferences] = useStayViewPreferences();
    const ratePlanId = resolveChoice(rates, preferences.rateChoice).ratePlanId;
    const range: DateWindow = { from: stayWindow.from, to: stayWindow.to };

    const hrefFor = (target: DateWindow) =>
        timeline.url() + queryString(viewQuery(target, filters));

    // The filters live in the URL so a view can be bookmarked. Only the address changes; nothing is requested.
    useEffect(() => {
        window.history.replaceState(window.history.state, '', hrefFor(range));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filters, range.from, range.to]);

    return (
        <div className="flex min-h-0 flex-1 flex-col gap-4 pt-4">
            <Head title="Timeline View" />
            {rooms.length === 0 ? (
                <EmptyState
                    icon={ChartGantt}
                    title="No rooms yet"
                    description="Add a room type and its rooms in settings, then the stays show here."
                />
            ) : (
                <>
                    <div className="px-4">
                        <StayViewToolbar
                            window={range}
                            today={stayWindow.today}
                            filters={filters}
                            onFiltersChange={setFilters}
                            groupingOptions={groupingOptions}
                            hrefFor={hrefFor}
                            onScrollToToday={() =>
                                gridRef.current?.scrollToDate(stayWindow.today)
                            }
                        />
                    </div>
                    <div className="relative flex min-h-0 flex-1 flex-col">
                        <StayViewTimeline
                            gridRef={gridRef}
                            window={stayWindow}
                            weekendDays={weekendDays}
                            rooms={rooms}
                            stays={stays}
                            blocks={blocks}
                            dayUses={dayUses}
                            rates={rates}
                            filters={filters}
                            showRates={preferences.showRates}
                            rateChoice={preferences.rateChoice}
                            currency={currency}
                            canViewReservations={canViewReservations}
                            canManageReservations={canManageReservations}
                            onOpenStay={setPeekUnitId}
                            corner={
                                <StayViewFilters
                                    rooms={rooms}
                                    filters={filters}
                                    onFiltersChange={setFilters}
                                    statusOptions={statusOptions}
                                    showRates={preferences.showRates}
                                    onShowRatesChange={(showRates) =>
                                        updatePreferences({ showRates })
                                    }
                                    planOptions={planChoices(rates).map(
                                        (plan) => ({
                                            value: String(plan.id),
                                            label: plan.name,
                                        }),
                                    )}
                                    ratePlanId={
                                        ratePlanId === null
                                            ? ''
                                            : String(ratePlanId)
                                    }
                                    onRatePlanChange={(planId) =>
                                        updatePreferences({
                                            rateChoice: { ratePlanId: planId },
                                        })
                                    }
                                />
                            }
                        />
                    </div>
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

Timeline.layout = {
    variant: 'fixed',
    breadcrumbs: [{ title: 'Timeline View', href: timeline() }],
};
