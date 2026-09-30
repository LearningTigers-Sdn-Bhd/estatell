import { Package, SquareArrowOutUpRight } from 'lucide-react';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { edit as editInclusions } from '@/routes/settings/rate-plans/inclusions';

/**
 * A box icon button on a plan row that lists what the plan includes. Staff who manage settings also get a link that
 * opens the Inclusions tab of the plan in a new browser tab, so the calendar keeps its place.
 */
export function InclusionsPopover({
    plan,
    canManage,
}: {
    plan: App.Data.Rates.RateCalendarPlanData;
    /** Whether to show the link to the Inclusions tab. */
    canManage: boolean;
}) {
    if (plan.inclusions.length === 0) {
        return null;
    }

    return (
        <Popover>
            <PopoverTrigger
                render={
                    <Button
                        type="button"
                        variant="outline"
                        size="icon-xs"
                        aria-label={`Inclusions of ${plan.name}`}
                        title="Inclusions"
                    />
                }
            >
                <Package />
            </PopoverTrigger>
            <PopoverContent align="start" className="w-72">
                <PopoverHeader>
                    <PopoverTitle>{plan.name} includes</PopoverTitle>
                </PopoverHeader>
                <ul className="flex flex-col gap-1.5 text-sm">
                    {plan.inclusions.map((inclusion, index) => (
                        <li
                            key={index}
                            className="flex items-baseline justify-between gap-3"
                        >
                            <span className="min-w-0 truncate">
                                {inclusion.name}
                            </span>
                            <span className="shrink-0 text-xs text-muted-foreground">
                                {inclusion.quantity} ×{' '}
                                {inclusion.chargingUnit.toLowerCase()}
                            </span>
                        </li>
                    ))}
                </ul>
                {canManage && (
                    <a
                        href={editInclusions.url({ ratePlan: plan.id })}
                        target="_blank"
                        rel="noopener"
                        className={cn(
                            buttonVariants({ variant: 'outline', size: 'sm' }),
                            'border-foreground/25',
                        )}
                    >
                        Manage inclusions
                        <SquareArrowOutUpRight data-icon="inline-end" />
                        <span className="sr-only">(opens in a new tab)</span>
                    </a>
                )}
            </PopoverContent>
        </Popover>
    );
}
