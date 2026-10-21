import { Head, router } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { DiscardChangesDialog } from '@/components/pms/discard-changes-dialog';
import { EmptyState } from '@/components/pms/empty-state';
import type { DateGridHandle } from '@/components/pms/grid/date-grid';
import { planOptions } from '@/components/pms/rate-calendar/calendar-model';
import type { CalendarFilters } from '@/components/pms/rate-calendar/calendar-model';
import {
    buildPendingPayload,
    pendingErrors,
    stagePending,
} from '@/components/pms/rate-calendar/pending-changes';
import type { PendingChanges } from '@/components/pms/rate-calendar/pending-changes';
import { PendingChangesBar } from '@/components/pms/rate-calendar/pending-changes-bar';
import { RateCalendarGrid } from '@/components/pms/rate-calendar/rate-calendar-grid';
import { RateCalendarToolbar } from '@/components/pms/rate-calendar/rate-calendar-toolbar';
import { RateTabs } from '@/components/pms/rate-calendar/rate-tabs';
import { RatesEndingAlert } from '@/components/pms/rate-calendar/rates-ending-alert';
import { calendar as calendarRoute } from '@/routes/rates';
import { bulk, changes } from '@/routes/rates/calendar';

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
    currency: App.Data.Property.CurrencyData;
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

    const [pending, setPending] = useState<PendingChanges>(new Map());
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [confirmDiscard, setConfirmDiscard] = useState(false);
    const [leaveUrl, setLeaveUrl] = useState<string | null>(null);
    const pendingCount = useRef(0);

    pendingCount.current = pending.size;

    const range = { from: calendar.from, to: calendar.to };

    const save = () => {
        router.put(changes.url(), buildPendingPayload(pending), {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => {
                setPending(new Map());
                setErrors({});
            },
            onError: setErrors,
        });
    };

    const discard = () => {
        setPending(new Map());
        setErrors({});
        setConfirmDiscard(false);
    };

    // Staged edits survive a change of range on this page. Leaving the page asks first.
    useEffect(() => {
        const removeBefore = router.on('before', (event) => {
            const { visit } = event.detail;

            if (
                pendingCount.current === 0 ||
                visit.method !== 'get' ||
                visit.url.pathname === window.location.pathname
            ) {
                return;
            }

            event.preventDefault();
            setLeaveUrl(visit.url.href);
        });
        const warnOnUnload = (event: BeforeUnloadEvent) => {
            if (pendingCount.current > 0) {
                event.preventDefault();
            }
        };

        window.addEventListener('beforeunload', warnOnUnload);

        return () => {
            removeBefore();
            window.removeEventListener('beforeunload', warnOnUnload);
        };
    }, []);

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
                        <div className="relative flex min-h-0 flex-1 flex-col">
                            <RateCalendarGrid
                                gridRef={gridRef}
                                roomTypes={calendar.roomTypes}
                                dates={calendar.dates}
                                today={today}
                                weekendDays={weekendDays}
                                filters={filters}
                                canManage={canManage}
                                canManageInclusions={canManageInclusions}
                                currency={currency.code}
                                pending={pending}
                                onStage={(change) =>
                                    setPending((current) =>
                                        stagePending(current, change),
                                    )
                                }
                            />
                            <PendingChangesBar
                                count={pending.size}
                                errors={pendingErrors(errors, pending)}
                                processing={processing}
                                onSave={save}
                                onDiscard={() => setConfirmDiscard(true)}
                            />
                        </div>
                    </>
                )}
            </RateTabs>
            <DiscardChangesDialog
                open={confirmDiscard || leaveUrl !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setConfirmDiscard(false);
                        setLeaveUrl(null);
                    }
                }}
                title={`Discard ${pending.size} unsaved ${pending.size === 1 ? 'change' : 'changes'}?`}
                onDiscard={() => {
                    const url = leaveUrl;

                    pendingCount.current = 0;
                    discard();
                    setLeaveUrl(null);

                    if (url) {
                        router.visit(url);
                    }
                }}
            />
        </div>
    );
}

RateCalendar.layout = {
    variant: 'fixed',
    breadcrumbs: [{ title: 'Rate calendar', href: calendarRoute() }],
};
