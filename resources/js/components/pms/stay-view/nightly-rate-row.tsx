import { EnumSelect } from '@/components/pms/enum-select';
import { cn } from '@/lib/utils';
import { planChoices, roomTypeChoices } from './nightly-rate';
import type { RateChoice } from './nightly-rate';

type Row = App.Data.StayView.RatePriceRowData;
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

/** The label of the rate row: which room type and which rate plan the prices are for. */
export function NightlyRateLabel({
    rates,
    choice,
    onChange,
}: {
    rates: Row[];
    choice: RateChoice;
    onChange: (choice: RateChoice) => void;
}) {
    return (
        <div className="flex min-w-0 flex-1 flex-col gap-1">
            <EnumSelect
                aria-label="Room type for the rate row"
                className="h-7 w-full text-xs"
                options={roomTypeChoices(rates).map((type) => ({
                    value: String(type.id),
                    label: type.name,
                }))}
                value={
                    choice.roomTypeId === null ? '' : String(choice.roomTypeId)
                }
                onValueChange={(value) =>
                    onChange({ roomTypeId: Number(value), ratePlanId: null })
                }
            />
            <EnumSelect
                aria-label="Rate plan for the rate row"
                className="h-7 w-full text-xs"
                options={planChoices(rates, choice.roomTypeId).map((plan) => ({
                    value: String(plan.id),
                    label: plan.name,
                }))}
                value={
                    choice.ratePlanId === null ? '' : String(choice.ratePlanId)
                }
                onValueChange={(value) =>
                    onChange({ ...choice, ratePlanId: Number(value) })
                }
            />
        </div>
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
