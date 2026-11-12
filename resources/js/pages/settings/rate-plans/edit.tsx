import { Head } from '@inertiajs/react';
import type { EnumOption } from '@/components/pms/enum-select';
import { DetailsForm } from '@/components/pms/rate-plans/details-form';
import {
    RatePlanShell,
    ratePlanBreadcrumbs,
} from '@/components/pms/rate-plans/rate-plan-shell';

type RatePlan = App.Data.Rates.RatePlanData;

export default function RatePlanEdit({
    ratePlan,
    currency,
    nightsLockedReason,
    groupRateOptions,
    onlineBooking,
}: {
    ratePlan: RatePlan;
    currency: App.Data.Property.CurrencyData;
    nightsLockedReason: string | null;
    groupRateOptions: EnumOption[];
    onlineBooking: boolean;
}) {
    return (
        <>
            <Head title={ratePlan.name} />
            <RatePlanShell ratePlan={ratePlan} activeTab="details">
                <DetailsForm
                    ratePlan={ratePlan}
                    currency={currency}
                    nightsLockedReason={nightsLockedReason}
                    groupRateOptions={groupRateOptions}
                    onlineBooking={onlineBooking}
                />
            </RatePlanShell>
        </>
    );
}

RatePlanEdit.layout = (props: Parameters<typeof ratePlanBreadcrumbs>[0]) => ({
    breadcrumbs: ratePlanBreadcrumbs(props, 'details'),
});
