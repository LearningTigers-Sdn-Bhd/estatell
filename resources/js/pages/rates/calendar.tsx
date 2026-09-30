import { Head } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { PageHeader } from '@/components/pms/page-header';
import { planOptions } from '@/components/pms/rate-calendar/calendar-model';
import type { CalendarFilters } from '@/components/pms/rate-calendar/calendar-model';
import { RateCalendarGrid } from '@/components/pms/rate-calendar/rate-calendar-grid';
import { RateCalendarLegend } from '@/components/pms/rate-calendar/rate-calendar-legend';
import { RateCalendarToolbar } from '@/components/pms/rate-calendar/rate-calendar-toolbar';
import { RatesEndingAlert } from '@/components/pms/rate-calendar/rates-ending-alert';
import { calendar as calendarRoute } from '@/routes/rates';

export default function RateCalendar({
    calendar,
    currency,
    today,
    canManage,
}: {
    calendar: App.Data.Rates.RateCalendarData;
    currency: App.Enums.Currency;
    today: string;
    canManage: boolean;
}) {
    const [filters, setFilters] = useState<CalendarFilters>({
        roomTypeId: '',
        ratePlanId: '',
    });

    return (
        <div className="flex flex-1 flex-col gap-6 p-4">
            <Head title="Rate calendar" />
            <PageHeader
                title="Rate calendar"
                description={`Daily prices and restrictions in ${currency}.`}
            />
            <RatesEndingAlert ending={calendar.ending} canManage={canManage} />
            {calendar.roomTypes.length === 0 ? (
                <EmptyState
                    icon={CalendarRange}
                    title="Nothing to show yet"
                    description="Add a room type and a rate plan that sells it, then set its prices."
                />
            ) : (
                <>
                    <RateCalendarToolbar
                        month={calendar.month}
                        roomTypeOptions={calendar.roomTypes.map((roomType) => ({
                            value: String(roomType.id),
                            label: roomType.name,
                        }))}
                        planOptions={planOptions(calendar.roomTypes)}
                        filters={filters}
                        onFiltersChange={setFilters}
                    />
                    <RateCalendarLegend />
                    <RateCalendarGrid
                        roomTypes={calendar.roomTypes}
                        dates={calendar.dates}
                        today={today}
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
