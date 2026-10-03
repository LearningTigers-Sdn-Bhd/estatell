import { Head } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
import { useRef, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import type { DateGridHandle } from '@/components/pms/grid/date-grid';
import { planOptions } from '@/components/pms/rate-calendar/calendar-model';
import type { CalendarFilters } from '@/components/pms/rate-calendar/calendar-model';
import { RateCalendarGrid } from '@/components/pms/rate-calendar/rate-calendar-grid';
import { RateCalendarToolbar } from '@/components/pms/rate-calendar/rate-calendar-toolbar';
import { RateTabs } from '@/components/pms/rate-calendar/rate-tabs';
import { RatesEndingAlert } from '@/components/pms/rate-calendar/rates-ending-alert';
import { calendar as calendarRoute } from '@/routes/rates';
import { bulk } from '@/routes/rates/calendar';

/**
 * A Grid calendar page: no page header. The toolbar is the top of the page and the grid takes the rest of the window.
 */
export default function RateCalendar({
    calendar,
    currency,
    today,
    weekendDays,
    canManage,
    canManageInclusions,
}: {
    calendar: App.Data.Rates.RateCalendarData;
    currency: App.Enums.Currency;
    today: string;
    weekendDays: number[];
    canManage: boolean;
    canManageInclusions: boolean;
}) {
    const gridRef = useRef<DateGridHandle>(null);
    const [filters, setFilters] = useState<CalendarFilters>({
        roomTypeId: '',
        ratePlanId: '',
    });

    const range = { from: calendar.from, to: calendar.to };

    return (
        <div className="flex min-h-0 flex-1 flex-col pt-4">
            <Head title="Rate calendar" />
            <RateTabs
                activeTab="calendar"
                calendarHref={calendarRoute.url(undefined, { query: range })}
                bulkHref={bulk.url(undefined, {
                    query: {
                        ...range,
                        ...(filters.ratePlanId
                            ? { plan: filters.ratePlanId }
                            : {}),
                    },
                })}
                canManage={canManage}
                className="flex min-h-0 flex-1 flex-col gap-4 [&>[data-slot=tabs-list]]:mx-4"
                contentClassName="flex min-h-0 flex-1 flex-col gap-4"
            >
                {calendar.ending.length > 0 && (
                    <div className="px-4">
                        <RatesEndingAlert
                            ending={calendar.ending}
                            canManage={canManage}
                        />
                    </div>
                )}
                {calendar.roomTypes.length === 0 ? (
                    <EmptyState
                        icon={CalendarRange}
                        title="Nothing to show yet"
                        description="Add a room type and a rate plan that sells it, then set its prices."
                    />
                ) : (
                    <>
                        <div className="px-4">
                            <RateCalendarToolbar
                                range={range}
                                today={today}
                                onScrollToToday={() =>
                                    gridRef.current?.scrollToDate(today)
                                }
                                roomTypeOptions={calendar.roomTypes.map(
                                    (roomType) => ({
                                        value: String(roomType.id),
                                        label: roomType.name,
                                    }),
                                )}
                                planOptions={planOptions(calendar.roomTypes)}
                                filters={filters}
                                onFiltersChange={setFilters}
                            />
                        </div>
                        <RateCalendarGrid
                            gridRef={gridRef}
                            roomTypes={calendar.roomTypes}
                            dates={calendar.dates}
                            today={today}
                            weekendDays={weekendDays}
                            filters={filters}
                            canManage={canManage}
                            canManageInclusions={canManageInclusions}
                            currency={currency}
                        />
                    </>
                )}
            </RateTabs>
        </div>
    );
}

RateCalendar.layout = {
    variant: 'fixed',
    breadcrumbs: [{ title: 'Rate calendar', href: calendarRoute() }],
};
