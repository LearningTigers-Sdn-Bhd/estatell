import { useForm } from '@inertiajs/react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
            <div className="overflow-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Age group</TableHead>
                            <TableHead>Price</TableHead>
                            <TableHead>Value</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row, index) => {
                            const price = childPrices[index];

                            return (
                                <TableRow key={row.age_group_id}>
                                    <TableCell className="align-top">
                                        <span className="font-medium">
                                            {price.label}
                                        </span>{' '}
                                        <span className="text-muted-foreground tabular-nums">
                                            {price.minAge}–{price.maxAge}
                                        </span>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <EnumSelect
                                            aria-label={`Price for ${price.label}`}
                                            options={pricings}
                                            value={row.pricing}
                                            onValueChange={(value) =>
                                                setRow(index, {
                                                    pricing: value,
                                                    value:
                                                        value === 'free'
                                                            ? ''
                                                            : row.value,
                                                })
                                            }
                                            aria-invalid={
                                                !!errors[
                                                    `prices.${index}.pricing`
                                                ]
                                            }
                                        />
                                        <FieldError>
                                            {errors[`prices.${index}.pricing`]}
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        {row.pricing !== 'free' && (
                                            <>
                                                <InputGroup className="sm:max-w-48">
                                                    {row.pricing ===
                                                        'fixed' && (
                                                        <InputGroupAddon>
                                                            {currency}
                                                        </InputGroupAddon>
                                                    )}
                                                    <InputGroupInput
                                                        aria-label={`Value for ${price.label}`}
                                                        type="number"
                                                        min={
                                                            row.pricing ===
                                                            'percent'
                                                                ? '1'
                                                                : '0'
                                                        }
                                                        max={
                                                            row.pricing ===
                                                            'percent'
                                                                ? '100'
                                                                : '9999999999.99'
                                                        }
                                                        step="0.01"
                                                        inputMode="decimal"
                                                        value={row.value}
                                                        onChange={(event) =>
                                                            setRow(index, {
                                                                value: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                        aria-invalid={
                                                            !!errors[
                                                                `prices.${index}.value`
                                                            ]
                                                        }
                                                    />
                                                    {row.pricing ===
                                                        'percent' && (
                                                        <InputGroupAddon align="inline-end">
                                                            %
                                                        </InputGroupAddon>
                                                    )}
                                                </InputGroup>
                                                <FieldError>
                                                    {
                                                        errors[
                                                            `prices.${index}.value`
                                                        ]
                                                    }
                                                </FieldError>
                                            </>
                                        )}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
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
