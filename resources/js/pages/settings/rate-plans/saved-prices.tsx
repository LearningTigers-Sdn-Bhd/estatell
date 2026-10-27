import { Head, Link } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import type { DateGridHandle } from '@/components/pms/grid/date-grid';
import { SavedPricesGrid } from '@/components/pms/rate-calendar/saved-prices-grid';
import { SavedPricesToolbar } from '@/components/pms/rate-calendar/saved-prices-toolbar';
import {
    RatePlanShell,
    ratePlanBreadcrumbs,
} from '@/components/pms/rate-plans/rate-plan-shell';
import { buttonVariants } from '@/components/ui/button';
import { edit as editRoomTypes } from '@/routes/settings/rate-plans/room-types';

type RatePlan = App.Data.Rates.RatePlanData;

export default function RatePlanSavedPrices({
    ratePlan,
    saved,
    pricedUntil,
    weekendDays,
    today,
}: {
    ratePlan: RatePlan;
    saved: App.Data.Rates.RatePlanSavedPricesData;
    pricedUntil: Record<string, string | null>;
    weekendDays: number[];
    today: string;
}) {
    const gridRef = useRef<DateGridHandle>(null);
    const [roomTypeId, setRoomTypeId] = useState('');
    const plan = saved.roomTypes[0]?.plans[0];
    const ownPrices = plan !== undefined && !plan.derived;
    const showPricedUntil = ownPrices && ratePlan.pricingBasis !== 'package';

    return (
        <>
            <Head title={`${ratePlan.name} saved prices`} />
            <RatePlanShell
                ratePlan={ratePlan}
                activeTab="saved-prices"
                panelWidth="full"
            >
                {saved.roomTypes.length === 0 ? (
                    <EmptyState
                        title="No room types"
                        description="Choose the room types this plan sells to see its saved prices."
                        action={
                            <Link
                                href={editRoomTypes.url({
                                    ratePlan: ratePlan.id,
                                })}
                                className={buttonVariants()}
                            >
                                Choose room types
                            </Link>
                        }
                    />
                ) : (
                    <div className="flex flex-col gap-4">
                        <SavedPricesToolbar
                            ratePlanId={ratePlan.id}
                            range={{ from: saved.from, to: saved.to }}
                            today={today}
                            onScrollToToday={() =>
                                gridRef.current?.scrollToDate(today)
                            }
                            roomTypeOptions={saved.roomTypes.map(
                                (roomType) => ({
                                    value: String(roomType.id),
                                    label: roomType.name,
                                }),
                            )}
                            roomTypeId={roomTypeId}
                            onRoomTypeChange={setRoomTypeId}
                        />
                        <SavedPricesGrid
                            gridRef={gridRef}
                            roomTypes={saved.roomTypes}
                            dates={saved.dates}
                            today={today}
                            weekendDays={weekendDays}
                            roomTypeId={roomTypeId}
                        />
                        {showPricedUntil && (
                            <p className="text-sm text-muted-foreground">
                                Priced until{' '}
                                {saved.roomTypes.map((roomType, index) => (
                                    <span key={roomType.id}>
                                        {index > 0 && ' · '}
                                        {roomType.name}{' '}
                                        {pricedUntil[roomType.id] ? (
                                            <DateText
                                                date={pricedUntil[roomType.id]}
                                            />
                                        ) : (
                                            'none'
                                        )}
                                    </span>
                                ))}
                            </p>
                        )}
                    </div>
                )}
            </RatePlanShell>
        </>
    );
}

RatePlanSavedPrices.layout = (props: { ratePlan: RatePlan }) => ({
    breadcrumbs: ratePlanBreadcrumbs(props.ratePlan),
});
