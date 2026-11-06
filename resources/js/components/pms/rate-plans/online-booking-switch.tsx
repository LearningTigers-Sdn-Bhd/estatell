import { router } from '@inertiajs/react';
import { useState } from 'react';
import BookingWebsiteController from '@/actions/App/Http/Controllers/BookingEngine/BookingWebsiteController';
import { Switch } from '@/components/ui/switch';

type Row = { id: number; name: string; bookableOnline: boolean };

/**
 * The "book online" switch of one rate plan, used on Settings › Booking website › Rates, and the Rate plans list.
 * It flips at once and follows the server's answer. It is async so a quick next switch does not cancel this save.
 */
export function OnlineBookingSwitch({
    ratePlan,
    disabled = false,
}: {
    ratePlan: Row;
    disabled?: boolean;
}) {
    const [saving, setSaving] = useState(false);

    function save(bookable: boolean) {
        router
            .optimistic<{ ratePlans: Row[] }>((props) => ({
                ratePlans: props.ratePlans.map((plan) =>
                    plan.id === ratePlan.id
                        ? { ...plan, bookableOnline: bookable }
                        : plan,
                ),
            }))
            .put(
                BookingWebsiteController.updateRates.url(),
                { rate_plan_id: ratePlan.id, bookable },
                {
                    async: true,
                    preserveScroll: true,
                    preserveState: true,
                    onStart: () => setSaving(true),
                    onFinish: () => setSaving(false),
                },
            );
    }

    return (
        <Switch
            size="sm"
            checked={ratePlan.bookableOnline}
            disabled={disabled || saving}
            aria-label={`Turn ${ratePlan.name} ${ratePlan.bookableOnline ? 'off' : 'on'} for online booking`}
            onCheckedChange={save}
        />
    );
}
