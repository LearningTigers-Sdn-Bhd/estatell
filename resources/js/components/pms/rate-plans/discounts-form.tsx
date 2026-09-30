import { router, useForm } from '@inertiajs/react';
import { Copy, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { DiscountPreview } from '@/components/pms/rate-plans/discount-preview';
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
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { copy, update } from '@/routes/settings/rate-plans/discounts';

type RatePlan = App.Data.Rates.RatePlanData;
type Discount = App.Data.Rates.RatePlanStayDiscountData;
type Option = { value: string; label: string };
type Row = {
    min_nights: string;
    discount_type: string;
    value: string;
    from_night: string;
};

const unitLabels: Record<string, string> = {
    per_room: 'room',
    per_guest: 'guest',
    per_bed: 'bed',
};

/**
 * Long-stay discounts of a plan: the longest rule a stay qualifies for wins. Direct bookings only.
 */
export function DiscountsForm({
    ratePlan,
    discounts,
    types,
    parentDiscounts,
    currency,
    ageGroups,
    preview,
}: {
    ratePlan: RatePlan;
    discounts: Discount[];
    types: Option[];
    parentDiscounts: App.Data.Rates.RatePlanParentDiscountsData | null;
    currency: App.Enums.Currency;
    ageGroups: App.Data.Property.PropertyAgeGroupData[];
    preview: App.Data.Rates.StayDiscountPreviewData;
}) {
    const form = useForm<{ discounts: Row[] }>({
        discounts: discounts.map((discount) => ({
            min_nights: String(discount.minNights),
            discount_type: discount.discountType,
            value: discount.value,
            from_night: String(discount.fromNight),
        })),
    });
    const guard = useUnsavedChanges(form.isDirty);
    const rows = form.data.discounts;
    const errors = form.errors as Record<string, string | undefined>;
    const unit = unitLabels[ratePlan.pricingBasis] ?? 'room';

    const setRow = (index: number, values: Partial<Row>) => {
        form.setData(
            'discounts',
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );
    };

    const addRow = () => {
        form.setData('discounts', [
            ...rows,
            {
                min_nights: '',
                discount_type: 'percent',
                value: '',
                from_night: '1',
            },
        ]);
    };

    const removeRow = (index: number) => {
        form.setData(
            'discounts',
            rows.filter((_, position) => position !== index),
        );
    };

    const [copying, setCopying] = useState(false);

    const copyParentRules = () =>
        router.post(
            copy.url({ ratePlan: ratePlan.id }),
            {},
            {
                preserveScroll: true,
                onStart: () => setCopying(true),
                onFinish: () => setCopying(false),
            },
        );

    const addButton = (
        <Button type="button" variant="outline" onClick={addRow}>
            <Plus data-icon="inline-start" />
            Add long-stay discount
        </Button>
    );

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

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            {rows.length === 0 ? (
                <EmptyState
                    title="No long-stay discounts"
                    description="Add a rule such as 3 or more nights, 20% off from night 2."
                    action={
                        <div className="flex flex-wrap justify-center gap-2">
                            {addButton}
                            {parentDiscounts && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={copyParentRules}
                                    disabled={copying}
                                >
                                    {copying ? (
                                        <Spinner data-icon="inline-start" />
                                    ) : (
                                        <Copy data-icon="inline-start" />
                                    )}
                                    Copy discounts from{' '}
                                    {parentDiscounts.parentName}
                                </Button>
                            )}
                        </div>
                    }
                />
            ) : (
                <div className="overflow-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Minimum nights</TableHead>
                                <TableHead>Discount</TableHead>
                                <TableHead>Value</TableHead>
                                <TableHead>From night</TableHead>
                                <TableHead className="w-12">
                                    <span className="sr-only">Remove</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row, index) => (
                                <TableRow key={index}>
                                    <TableCell className="align-top">
                                        <InputGroup className="w-32">
                                            <InputGroupInput
                                                aria-label="Minimum nights"
                                                type="number"
                                                min="2"
                                                max="365"
                                                inputMode="numeric"
                                                placeholder="3"
                                                value={row.min_nights}
                                                onChange={(event) =>
                                                    setRow(index, {
                                                        min_nights:
                                                            event.target.value,
                                                    })
                                                }
                                                aria-invalid={
                                                    !!errors[
                                                        `discounts.${index}.min_nights`
                                                    ]
                                                }
                                            />
                                            <InputGroupAddon align="inline-end">
                                                nights
                                            </InputGroupAddon>
                                        </InputGroup>
                                        <FieldError>
                                            {
                                                errors[
                                                    `discounts.${index}.min_nights`
                                                ]
                                            }
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <EnumSelect
                                            aria-label="Discount type"
                                            className="w-48"
                                            options={types}
                                            value={row.discount_type}
                                            onValueChange={(value) =>
                                                setRow(index, {
                                                    discount_type: value,
                                                })
                                            }
                                        />
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <InputGroup className="w-44">
                                            {row.discount_type === 'amount' && (
                                                <InputGroupAddon>
                                                    {currency}
                                                </InputGroupAddon>
                                            )}
                                            <InputGroupInput
                                                aria-label="Discount value"
                                                placeholder={
                                                    row.discount_type ===
                                                    'percent'
                                                        ? '10'
                                                        : '0.00'
                                                }
                                                type="number"
                                                min={
                                                    row.discount_type ===
                                                    'percent'
                                                        ? '1'
                                                        : '0.01'
                                                }
                                                max={
                                                    row.discount_type ===
                                                    'percent'
                                                        ? '100'
                                                        : '9999999999.99'
                                                }
                                                step="0.01"
                                                inputMode="decimal"
                                                value={row.value}
                                                onChange={(event) =>
                                                    setRow(index, {
                                                        value: event.target
                                                            .value,
                                                    })
                                                }
                                                aria-invalid={
                                                    !!errors[
                                                        `discounts.${index}.value`
                                                    ]
                                                }
                                            />
                                            {row.discount_type ===
                                                'percent' && (
                                                <InputGroupAddon align="inline-end">
                                                    %
                                                </InputGroupAddon>
                                            )}
                                        </InputGroup>
                                        <FieldError>
                                            {errors[`discounts.${index}.value`]}
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <InputGroup className="w-32">
                                            <InputGroupAddon>
                                                Night
                                            </InputGroupAddon>
                                            <InputGroupInput
                                                aria-label="From night"
                                                type="number"
                                                min="1"
                                                inputMode="numeric"
                                                placeholder="1"
                                                value={row.from_night}
                                                onChange={(event) =>
                                                    setRow(index, {
                                                        from_night:
                                                            event.target.value,
                                                    })
                                                }
                                                aria-invalid={
                                                    !!errors[
                                                        `discounts.${index}.from_night`
                                                    ]
                                                }
                                            />
                                        </InputGroup>
                                        <FieldError>
                                            {
                                                errors[
                                                    `discounts.${index}.from_night`
                                                ]
                                            }
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Remove discount"
                                            onClick={() => removeRow(index)}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={5}>
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <p className="text-sm font-normal whitespace-normal text-muted-foreground">
                                            The longest rule a stay qualifies
                                            for wins. A percentage is taken off
                                            the night total, an amount per{' '}
                                            {unit}. Direct bookings only.
                                        </p>
                                        {addButton}
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            )}
            <FieldError>{errors.discounts}</FieldError>
            {rows.length > 0 && (
                <DiscountPreview
                    ratePlanId={ratePlan.id}
                    rules={rows}
                    preview={preview}
                    ageGroups={ageGroups}
                    currency={currency}
                />
            )}
            <footer className="flex justify-end gap-2">
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save discounts
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
