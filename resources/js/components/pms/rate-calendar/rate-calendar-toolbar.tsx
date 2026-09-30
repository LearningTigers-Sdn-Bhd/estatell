import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { EnumSelect } from '@/components/pms/enum-select';
import { buttonVariants } from '@/components/ui/button';
import { calendar } from '@/routes/rates';
import { monthLabel, shiftMonth } from './calendar-model';
import type { CalendarFilters } from './calendar-model';

type Option = { value: string; label: string };

/**
 * Month links and the room type and plan filters. A month link asks the server for that month, and the
 * neighbouring months are prefetched. The filters change the loaded data in the browser.
 */
export function RateCalendarToolbar({
    month,
    roomTypeOptions,
    planOptions,
    filters,
    onFiltersChange,
}: {
    month: string;
    roomTypeOptions: Option[];
    planOptions: Option[];
    filters: CalendarFilters;
    onFiltersChange: (filters: CalendarFilters) => void;
}) {
    const link = (target: string) => ({
        href: calendar.url(undefined, { query: { month: target } }),
        prefetch: true,
        preserveState: true,
        preserveScroll: true,
        only: ['calendar'],
    });

    return (
        <div className="flex flex-wrap items-center gap-3">
            <div className="flex items-center gap-2">
                <Link
                    {...link(shiftMonth(month, -1))}
                    aria-label="Previous month"
                    className={buttonVariants({
                        variant: 'outline',
                        size: 'icon',
                    })}
                >
                    <ChevronLeft />
                </Link>
                <h2 className="min-w-36 text-center font-heading text-base font-semibold">
                    {monthLabel(month)}
                </h2>
                <Link
                    {...link(shiftMonth(month, 1))}
                    aria-label="Next month"
                    className={buttonVariants({
                        variant: 'outline',
                        size: 'icon',
                    })}
                >
                    <ChevronRight />
                </Link>
            </div>
            <EnumSelect
                aria-label="Room type"
                allLabel="All room types"
                options={roomTypeOptions}
                value={filters.roomTypeId}
                onValueChange={(roomTypeId) =>
                    onFiltersChange({ ...filters, roomTypeId })
                }
            />
            <EnumSelect
                aria-label="Rate plan"
                allLabel="All rate plans"
                options={planOptions}
                value={filters.ratePlanId}
                onValueChange={(ratePlanId) =>
                    onFiltersChange({ ...filters, ratePlanId })
                }
            />
        </div>
    );
}
