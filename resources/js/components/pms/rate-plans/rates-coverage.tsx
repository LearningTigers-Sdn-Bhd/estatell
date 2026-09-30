import { useForm } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { extend } from '@/routes/settings/rate-plans/pricing';

type RatePlan = App.Data.Rates.RatePlanData;
type RoomType = App.Data.Rates.RatePricingRoomTypeData;

/**
 * The rates ending warning with an "Extend 12 months" button, shown when a room type's prices end
 * within 60 days. How far each room type is priced shows on its row in the pricing table.
 */
export function RatesCoverage({
    ratePlan,
    roomTypes,
}: {
    ratePlan: RatePlan;
    roomTypes: RoomType[];
}) {
    const form = useForm({});
    const ending = roomTypes.filter((roomType) => roomType.ending);

    if (ending.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3">
            {
                <Alert>
                    <TriangleAlert />
                    <AlertTitle>Prices are running out</AlertTitle>
                    <AlertDescription className="flex flex-col items-start gap-3">
                        <span>
                            {ending.map((roomType) => roomType.name).join(', ')}{' '}
                            {ending.length === 1 ? 'has' : 'have'} no price for
                            the next 60 days. Extend copies the last 7 days
                            forward for 12 months. Stop sell and closed dates
                            are not copied.
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={form.processing}
                            focusableWhenDisabled
                            onClick={() =>
                                form.post(
                                    extend.url({ ratePlan: ratePlan.id }),
                                    {
                                        preserveScroll: true,
                                    },
                                )
                            }
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Extend 12 months
                        </Button>
                    </AlertDescription>
                </Alert>
            }
        </div>
    );
}
