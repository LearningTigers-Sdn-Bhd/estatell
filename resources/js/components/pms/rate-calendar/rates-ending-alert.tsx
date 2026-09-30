import { useForm } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { extend } from '@/routes/rates/calendar';

function EndingPlan({
    ending,
    canManage,
}: {
    ending: App.Data.Rates.RateCalendarEndingData;
    canManage: boolean;
}) {
    const form = useForm({});

    return (
        <li className="flex flex-wrap items-center gap-x-3 gap-y-2">
            <span>
                <strong>{ending.ratePlanName}</strong>:{' '}
                {ending.roomTypeNames.join(', ')}
            </span>
            {canManage && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={form.processing}
                    focusableWhenDisabled
                    onClick={() =>
                        form.post(extend.url({ ratePlan: ending.ratePlanId }), {
                            preserveScroll: true,
                        })
                    }
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Extend 12 months
                </Button>
            )}
        </li>
    );
}

/**
 * The rates ending warning: plans with their own prices whose room types have no price for the next 60 days.
 * Staff who can manage rates get an Extend button for each plan.
 */
export function RatesEndingAlert({
    ending,
    canManage,
}: {
    ending: App.Data.Rates.RateCalendarEndingData[];
    canManage: boolean;
}) {
    if (ending.length === 0) {
        return null;
    }

    return (
        <Alert>
            <TriangleAlert />
            <AlertTitle>Prices are running out</AlertTitle>
            <AlertDescription className="flex flex-col gap-3">
                <span>
                    These room types have no price for the next 60 days. Extend
                    copies the last 7 days forward for 12 months. Stop sell and
                    closed dates are not copied.
                </span>
                <ul className="flex flex-col gap-2">
                    {ending.map((plan) => (
                        <EndingPlan
                            key={plan.ratePlanId}
                            ending={plan}
                            canManage={canManage}
                        />
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}
