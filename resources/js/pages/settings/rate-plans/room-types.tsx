import { Head } from '@inertiajs/react';
import {
    RatePlanShell,
    ratePlanBreadcrumbs,
} from '@/components/pms/rate-plans/rate-plan-shell';
import { RoomTypesForm } from '@/components/pms/rate-plans/room-types-form';

type RatePlan = App.Data.Rates.RatePlanData;

export default function RatePlanRoomTypes({
    ratePlan,
    roomTypes,
    sellUnits,
}: {
    ratePlan: RatePlan;
    roomTypes: App.Data.Rates.RatePlanRoomTypeChoiceData[];
    sellUnits: { value: string; label: string }[];
}) {
    return (
        <>
            <Head title={`${ratePlan.name} room types`} />
            <RatePlanShell ratePlan={ratePlan} activeTab="room-types">
                <RoomTypesForm
                    ratePlan={ratePlan}
                    roomTypes={roomTypes}
                    sellUnits={sellUnits}
                />
            </RatePlanShell>
        </>
    );
}

RatePlanRoomTypes.layout = (props: { ratePlan: RatePlan }) => ({
    breadcrumbs: ratePlanBreadcrumbs(props.ratePlan),
});
