import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
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
import { Input } from '@/components/ui/input';
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
import { update } from '@/routes/settings/rate-plans/discounts';

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
    currency,
}: {
    ratePlan: RatePlan;
    discounts: Discount[];
    types: Option[];
    currency: App.Enums.Currency;
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
        <form onSubmit={submit} className="flex flex-col gap-5">
            <p className="text-sm text-muted-foreground">
                A stay gets the longest rule it qualifies for. A percentage is
                taken off the night total. An amount is taken off per {unit}.
                From night 2 keeps the first night at full price. Direct
                bookings only.
            </p>
            {rows.length === 0 ? (
                <EmptyState
                    title="No long-stay discounts"
                    description="Add a rule such as 3 or more nights, 20% off from night 2."
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
                                        <Input
                                            aria-label="Minimum nights"
                                            className="sm:max-w-24"
                                            type="number"
                                            min="2"
                                            max="365"
                                            inputMode="numeric"
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
                                        <InputGroup className="sm:max-w-48">
                                            {row.discount_type === 'amount' && (
                                                <InputGroupAddon>
                                                    {currency}
                                                </InputGroupAddon>
                                            )}
                                            <InputGroupInput
                                                aria-label="Discount value"
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
                                        <Input
                                            aria-label="From night"
                                            className="sm:max-w-24"
                                            type="number"
                                            min="1"
                                            inputMode="numeric"
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
                    </Table>
                </div>
            )}
            <FieldError>{errors.discounts}</FieldError>
            <footer className="flex justify-between gap-2">
                <Button type="button" variant="outline" onClick={addRow}>
                    <Plus data-icon="inline-start" />
                    Add long-stay discount
                </Button>
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
