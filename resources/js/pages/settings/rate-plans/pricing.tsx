import { Head, Link } from '@inertiajs/react';
import { EmptyState } from '@/components/pms/empty-state';
import { PricingForm } from '@/components/pms/rate-plans/pricing-form';
import {
    RatePlanShell,
    ratePlanBreadcrumbs,
} from '@/components/pms/rate-plans/rate-plan-shell';
import { RatesCoverage } from '@/components/pms/rate-plans/rates-coverage';
import { buttonVariants } from '@/components/ui/button';
import { edit as editRoomTypes } from '@/routes/settings/rate-plans/room-types';

type RatePlan = App.Data.Rates.RatePlanData;

export default function RatePlanPricing({
    ratePlan,
    roomTypes,
    currency,
    weekendNights,
    weekendNightsOptions,
    today,
}: {
    ratePlan: RatePlan;
    roomTypes: App.Data.Rates.RatePricingRoomTypeData[];
    currency: App.Enums.Currency;
    weekendNights: string;
    weekendNightsOptions: App.Data.Property.WeekendNightsOptionData[];
    today: string;
}) {
    return (
        <>
            <Head title={`${ratePlan.name} pricing`} />
            <RatePlanShell
                ratePlan={ratePlan}
                activeTab="pricing"
                panelWidth="full"
            >
                {roomTypes.length === 0 ? (
                    <EmptyState
                        title="No room types"
                        description="Choose the room types this plan sells before you set prices."
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
                    <div className="flex flex-col gap-6">
                        <RatesCoverage
                            ratePlan={ratePlan}
                            roomTypes={roomTypes}
                        />
                        <PricingForm
                            ratePlan={ratePlan}
                            roomTypes={roomTypes}
                            currency={currency}
                            weekendNights={weekendNights}
                            weekendNightsOptions={weekendNightsOptions}
                            today={today}
                        />
                    </div>
                )}
            </RatePlanShell>
        </>
    );
}

RatePlanPricing.layout = (props: { ratePlan: RatePlan }) => ({
    breadcrumbs: ratePlanBreadcrumbs(props.ratePlan),
});
