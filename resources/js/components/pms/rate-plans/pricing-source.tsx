import { router } from '@inertiajs/react';
import { Link2, PencilLine } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { DerivationForm } from '@/components/pms/rate-plans/derivation-form';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { update } from '@/routes/settings/rate-plans/pricing/derivation';

type RatePlan = App.Data.Rates.RatePlanData;
type Derivation = App.Data.Rates.RatePlanDerivationData;
type Source = 'direct' | 'follow';

/**
 * Step 1 of the Pricing tab: set prices directly, or follow another plan. `direct` is rendered by the caller.
 */
export function PricingSource({
    ratePlan,
    derivation,
    derivations,
    currency,
    hasOwnPrices,
    direct,
    whileFollowing,
}: {
    ratePlan: RatePlan;
    derivation: Derivation;
    derivations: { value: string; label: string }[];
    currency: App.Enums.Currency;
    hasOwnPrices: boolean;
    direct: ReactNode;
    /** Shown below the follow form while the plan follows another, for example a package's arrival days. */
    whileFollowing?: ReactNode;
}) {
    const following = derivation.parentRatePlanId !== null;
    const [source, setSource] = useState<Source>(
        following ? 'follow' : 'direct',
    );
    const [stopping, setStopping] = useState(false);
    const blocked = !following && derivation.cannotFollowReason !== null;

    const stopFollowing = () =>
        router.put(
            update.url({ ratePlan: ratePlan.id }),
            { parent_rate_plan_id: null },
            {
                preserveScroll: true,
                onStart: () => setStopping(true),
                onFinish: () => setStopping(false),
            },
        );

    const followItem = (
        <ToggleGroupItem value="follow" disabled={blocked}>
            <Link2 data-icon="inline-start" />
            Follow another plan
        </ToggleGroupItem>
    );

    return (
        <div className="flex flex-col gap-6">
            <Field className="w-auto">
                <FieldLabel id="pricing-source-label">Prices</FieldLabel>
                <ToggleGroup
                    variant="outline"
                    spacing={0}
                    value={[source]}
                    onValueChange={(value) =>
                        value[0] && setSource(value[0] as Source)
                    }
                    aria-labelledby="pricing-source-label"
                >
                    <ToggleGroupItem value="direct">
                        <PencilLine data-icon="inline-start" />
                        Set prices directly
                    </ToggleGroupItem>
                    {blocked ? (
                        <Tooltip>
                            <TooltipTrigger
                                render={<span className="-ml-px inline-flex" />}
                            >
                                {followItem}
                            </TooltipTrigger>
                            <TooltipContent>
                                {derivation.cannotFollowReason}
                            </TooltipContent>
                        </Tooltip>
                    ) : (
                        followItem
                    )}
                </ToggleGroup>
            </Field>
            {source === 'follow' ? (
                <DerivationForm
                    ratePlan={ratePlan}
                    derivation={derivation}
                    derivations={derivations}
                    currency={currency}
                    hasOwnPrices={hasOwnPrices}
                />
            ) : following ? (
                <Alert className="max-w-3xl">
                    <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
                        This plan follows another plan's prices. Stop following
                        to set its own prices.
                        <Button
                            variant="outline"
                            onClick={stopFollowing}
                            disabled={stopping}
                        >
                            Stop following
                        </Button>
                    </AlertDescription>
                </Alert>
            ) : (
                direct
            )}
            {following && whileFollowing}
        </div>
    );
}
