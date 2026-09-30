import { Head } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
import { useRef, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import type { DateGridHandle } from '@/components/pms/grid/date-grid';
import { planOptions } from '@/components/pms/rate-calendar/calendar-model';
import type { CalendarFilters } from '@/components/pms/rate-calendar/calendar-model';
import { RateCalendarGrid } from '@/components/pms/rate-calendar/rate-calendar-grid';
import { RateCalendarToolbar } from '@/components/pms/rate-calendar/rate-calendar-toolbar';
import { RatesEndingAlert } from '@/components/pms/rate-calendar/rates-ending-alert';
import { calendar as calendarRoute } from '@/routes/rates';

/**
 * A Grid calendar page: no page header. The toolbar is the top of the page and the grid takes the rest of the window.
 */
export default function RateCalendar({
    calendar,
    today,
    weekendDays,
    canManage,
}: {
    calendar: App.Data.Rates.RateCalendarData;
    today: string;
    weekendDays: number[];
    canManage: boolean;
}) {
    const gridRef = useRef<DateGridHandle>(null);
    const [filters, setFilters] = useState<CalendarFilters>({
        roomTypeId: '',
        ratePlanId: '',
    });

    return (
        <div className="flex flex-1 flex-col gap-4 pt-4">
            <Head title="Rate calendar" />
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
                            range={{ from: calendar.from, to: calendar.to }}
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
                    />
                </>
            )}
        </div>
    );
}

RateCalendar.layout = {
    breadcrumbs: [{ title: 'Rate calendar', href: calendarRoute() }],
};
