import type { ReactNode } from 'react';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
import { cn } from '@/lib/utils';
import { dayLabel } from './stay-places';

type Day = App.Data.StayView.DateRateData;

/** The price without trailing zeros when it is whole, and with the currency's decimals when it is not. */
export function formatNightly(
    amount: string,
    currency: App.Data.Property.CurrencyData | null,
): string {
    const value = Number(amount);
    const decimals = currency?.decimals ?? 2;

    return new Intl.NumberFormat(undefined, {
        minimumFractionDigits: Number.isInteger(value) ? 0 : decimals,
        maximumFractionDigits: decimals,
    }).format(value);
}

/** The label of the rate row: what the prices are, in which currency, and for which room type and plan. */
export function NightlyRateLabel({
    currencyCode,
    roomTypeName,
    planName,
}: {
    currencyCode: string | null;
    roomTypeName: string;
    planName: string | null;
}) {
    return (
        <span className="flex min-w-0 flex-col">
            <span className="truncate text-xs font-semibold tracking-wide text-grid-header-foreground uppercase">
                Nightly rate{currencyCode ? ` · ${currencyCode}` : ''}
            </span>
            <span className="truncate text-xs text-grid-muted-foreground">
                {roomTypeName}
                {planName ? ` · ${planName}` : ''}
            </span>
        </span>
    );
}

/** One night's price. A night with no price shows a dash, and a stop sell night is struck through. */
export function NightlyRateCell({
    day,
    currency,
}: {
    day: Day | undefined;
    currency: App.Data.Property.CurrencyData | null;
}) {
    if (!day || day.amount === null) {
        return (
            <div className="flex h-full items-center justify-center text-sm text-grid-muted-foreground">
                <span aria-hidden>–</span>
                <span className="sr-only">No price</span>
            </div>
        );
    }

    return (
        <div
            className={cn(
                'flex h-full items-center justify-center text-sm font-medium tabular-nums',
                day.closed && 'text-grid-muted-foreground line-through',
            )}
        >
            {formatNightly(day.amount, currency)}
            {day.closed && <span className="sr-only"> (stop sell)</span>}
        </div>
    );
}

/**
 * Hover on a price to see every rate plan of the room type for that night, not only the chosen plan. The chosen
 * plan is marked, a night with no price shows a dash and a stop sell night is struck through.
 */
export function NightlyRateHoverCard({
    roomTypeName,
    date,
    plans,
    chosenPlanId,
    currency,
    children,
}: {
    roomTypeName: string;
    date: string;
    plans: { id: number; name: string; day: Day | undefined }[];
    chosenPlanId: number | null;
    currency: App.Data.Property.CurrencyData | null;
    children: ReactNode;
}) {
    return (
        <HoverCard>
            <HoverCardTrigger
                delay={200}
                render={<div className="h-full w-full cursor-default" />}
            >
                {children}
            </HoverCardTrigger>
            <HoverCardContent className="flex w-60 flex-col gap-2">
                <div className="flex flex-col">
                    <span className="text-sm font-medium">{roomTypeName}</span>
                    <span className="text-xs text-muted-foreground">
                        {dayLabel(date)}
                        {currency ? ` · ${currency.code}` : ''}
                    </span>
                </div>
                <ul className="flex flex-col gap-1 text-sm tabular-nums">
                    {plans.map((plan) => (
                        <li
                            key={plan.id}
                            className="flex items-center justify-between gap-3"
                        >
                            <span
                                className={cn(
                                    'truncate',
                                    plan.id === chosenPlanId && 'font-medium',
                                )}
                            >
                                {plan.name}
                            </span>
                            <span
                                className={cn(
                                    plan.day?.closed &&
                                        'text-muted-foreground line-through',
                                )}
                            >
                                {plan.day?.amount
                                    ? formatNightly(plan.day.amount, currency)
                                    : '–'}
                                {plan.day?.closed && (
                                    <span className="sr-only">
                                        {' '}
                                        (stop sell)
                                    </span>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            </HoverCardContent>
        </HoverCard>
    );
}
