import {
    ChildPriceTable,
    type ChildPriceGroup,
    type ChildPriceRow,
} from '@/components/pms/child-price-table';
import { EmptyState } from '@/components/pms/empty-state';
import { FieldError } from '@/components/ui/field';

type Option = { value: string; label: string };

/**
 * The Children rows of a per-guest charge: one row per age group, or an empty state when the property has none.
 * Shared by the charge item edit page and the boat fare Sheet. The caller wraps it in its own section.
 */
export function ChargeItemChildrenField({
    groups,
    rows,
    pricings,
    currency,
    errors,
    enteredWhenCharging,
    onChange,
}: {
    groups: ChildPriceGroup[];
    rows: ChildPriceRow[];
    pricings: Option[];
    currency: App.Data.Property.CurrencyData;
    errors: Record<string, string | undefined>;
    /** An item priced when charging has no amount to take a percentage of. */
    enteredWhenCharging: boolean;
    onChange: (index: number, values: Partial<ChildPriceRow>) => void;
}) {
    if (groups.length === 0) {
        return (
            <EmptyState
                title="No age groups"
                description="Every guest counts as an adult. Add age groups in the property settings to price children separately."
            />
        );
    }

    return (
        <>
            <p className="text-sm text-muted-foreground">
                {enteredWhenCharging
                    ? 'This item has no default amount, so a child price is Free or a fixed amount.'
                    : 'A percentage is taken from the default amount. Adults pay the full amount.'}
            </p>
            <ChildPriceTable
                groups={groups}
                rows={rows}
                pricings={pricings}
                currency={currency}
                errors={errors}
                errorPrefix="child_prices"
                percentDisabled={enteredWhenCharging}
                onChange={onChange}
            />
            <FieldError>{errors.child_prices}</FieldError>
        </>
    );
}
