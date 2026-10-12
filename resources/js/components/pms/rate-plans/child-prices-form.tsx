import { useForm } from '@inertiajs/react';
import { EmptyState } from '@/components/pms/empty-state';
import { ChildPriceTable } from '@/components/pms/child-price-table';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { update } from '@/routes/settings/rate-plans/child-prices';

type RatePlan = App.Data.Rates.RatePlanData;
type ChildPrice = App.Data.Rates.RatePlanChildPriceData;
type Option = { value: string; label: string };
type Row = { age_group_id: number; pricing: string; value: string };

/**
 * One row per age group of the property. A percent is of the plan's 1-adult price for the night.
 */
export function ChildPricesForm({
    ratePlan,
    childPrices,
    pricings,
    currency,
}: {
    ratePlan: RatePlan;
    childPrices: ChildPrice[];
    pricings: Option[];
    currency: App.Enums.Currency;
}) {
    const form = useForm<{ prices: Row[] }>({
        prices: childPrices.map((price) => ({
            age_group_id: price.ageGroupId,
            pricing: price.pricing,
            value: price.value ?? '',
        })),
    });
    const guard = useUnsavedChanges(form.isDirty);
    const rows = form.data.prices;
    const errors = form.errors as Record<string, string | undefined>;

    const setRow = (index: number, values: Partial<Row>) => {
        form.setData(
            'prices',
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );
    };

    const save = (onSaved?: () => void) => {
        form.put(update.url({ ratePlan: ratePlan.id }), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                form.setDefaults();
                onSaved?.();
            },
        });
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        save();
    };

    if (childPrices.length === 0) {
        return (
            <EmptyState
                title="No age groups"
                description="Every guest counts as an adult. Add age groups in the property settings to price children separately."
            />
        );
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-5">
            <p className="text-sm text-muted-foreground">
                A percentage is taken from the price of one adult for the night.
            </p>
            <ChildPriceTable
                groups={childPrices}
                rows={rows}
                pricings={pricings}
                currency={currency}
                errors={errors}
                errorPrefix="prices"
                onChange={setRow}
            />
            <FieldError>{errors.prices}</FieldError>
            <footer className="flex justify-end">
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save child prices
                </Button>
            </footer>
            <UnsavedChangesDialog
                guard={guard}
                name={ratePlan.name}
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </form>
    );
}
