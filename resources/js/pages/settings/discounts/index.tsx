import { Head, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Percent, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import {
    MultiCombobox,
    type MultiComboboxOption,
} from '@/components/pms/multi-combobox';
import { PageHeader } from '@/components/pms/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index, reorder, store, update } from '@/routes/settings/discounts';

type Discount = App.Data.Catalogues.DiscountData;
type Charge = App.Data.Catalogues.DiscountChargeData;
type Option = { value: string; label: string };

type FormData = {
    name: string;
    code: string;
    type: App.Enums.DiscountType;
    value: string;
    allow_override: boolean;
    scope: App.Enums.DiscountScope;
    active: boolean;
    transaction_code_ids: string[];
};

function formValues(discount: Discount | null): FormData {
    return {
        name: discount?.name ?? '',
        code: discount?.code ?? '',
        type: discount?.type ?? 'percent',
        value: discount?.value ?? '',
        allow_override: discount?.allowOverride ?? false,
        scope: discount?.scope ?? 'room_only',
        active: discount?.active ?? true,
        transaction_code_ids: (discount?.transactionCodeIds ?? []).map(String),
    };
}

const typeDescriptions: Record<App.Enums.DiscountType, string> = {
    percent: 'A share of each charge, such as 10% off for staff.',
    fixed: 'A set amount, such as MYR 50 off for a complaint.',
    open: 'Staff type the amount each time.',
};

const scopeDescriptions: Record<App.Enums.DiscountScope, string> = {
    room_only:
        'Room nights, no-show, cancellation, late checkout and early departure charges. Only these discounts can be used on the booking form.',
    all_charges: 'Every charge on the folio. Taxes are never discounted.',
    selected: 'Only the charges you choose below.',
};

function DiscountForm({
    discount,
    charges,
    types,
    scopes,
    currency,
    onDone,
}: {
    discount: Discount | null;
    charges: Charge[];
    types: Option[];
    scopes: Option[];
    currency: App.Enums.Currency;
    onDone: () => void;
}) {
    const form = useForm<FormData>(formValues(discount));
    const isStarter = discount?.isStarter ?? false;
    const chargeItems: MultiComboboxOption[] = charges.map((charge) => ({
        value: String(charge.id),
        label: `${charge.name} · ${charge.code}${charge.active ? '' : ' (inactive)'}`,
    }));

    const setType = (type: App.Enums.DiscountType) => {
        form.setData((data) => ({
            ...data,
            type,
            value: type === 'open' ? '' : data.value,
            allow_override: type === 'open',
        }));
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onDone };

        if (discount) {
            form.put(update.url({ discount: discount.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <FieldGroup className="flex-1 overflow-y-auto px-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!form.errors.name}>
                        <FieldLabel htmlFor="discount-name">Name</FieldLabel>
                        <Input
                            id="discount-name"
                            placeholder="e.g. Staff rate"
                            maxLength={100}
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            aria-invalid={!!form.errors.name}
                            autoFocus
                        />
                        <FieldError>{form.errors.name}</FieldError>
                    </Field>
                    <Field data-invalid={!!form.errors.code}>
                        <FieldLabel htmlFor="discount-code">Code</FieldLabel>
                        <Input
                            id="discount-code"
                            placeholder="e.g. STAFF"
                            maxLength={20}
                            value={form.data.code}
                            onChange={(event) =>
                                form.setData(
                                    'code',
                                    event.target.value.toUpperCase(),
                                )
                            }
                            disabled={isStarter}
                            aria-invalid={!!form.errors.code}
                        />
                        <FieldDescription>
                            {isStarter
                                ? 'The starter discount keeps its code.'
                                : 'Used in reports and accounting exports.'}
                        </FieldDescription>
                        <FieldError>{form.errors.code}</FieldError>
                    </Field>
                </div>
                <FieldSet>
                    <FieldLegend variant="label">Discount</FieldLegend>
                    <RadioGroup
                        value={form.data.type}
                        onValueChange={(value) =>
                            setType(value as App.Enums.DiscountType)
                        }
                    >
                        {types.map((option) => (
                            <FieldLabel
                                key={option.value}
                                htmlFor={`discount-type-${option.value}`}
                            >
                                <Field orientation="horizontal">
                                    <RadioGroupItem
                                        id={`discount-type-${option.value}`}
                                        value={option.value}
                                    />
                                    <FieldContent>
                                        <span className="text-sm font-medium">
                                            {option.label}
                                        </span>
                                        <FieldDescription>
                                            {
                                                typeDescriptions[
                                                    option.value as App.Enums.DiscountType
                                                ]
                                            }
                                        </FieldDescription>
                                    </FieldContent>
                                </Field>
                            </FieldLabel>
                        ))}
                    </RadioGroup>
                    <FieldError>{form.errors.type}</FieldError>
                </FieldSet>
                {form.data.type !== 'open' && (
                    <Field data-invalid={!!form.errors.value}>
                        <FieldLabel htmlFor="discount-value">
                            {form.data.type === 'percent'
                                ? 'Percentage'
                                : 'Amount'}
                        </FieldLabel>
                        <InputGroup className="sm:max-w-60">
                            {form.data.type === 'fixed' && (
                                <InputGroupAddon>{currency}</InputGroupAddon>
                            )}
                            <InputGroupInput
                                id="discount-value"
                                placeholder={
                                    form.data.type === 'percent' ? '10' : '0.00'
                                }
                                type="number"
                                min={
                                    form.data.type === 'percent' ? '1' : '0.01'
                                }
                                max={
                                    form.data.type === 'percent'
                                        ? '100'
                                        : '9999999999.99'
                                }
                                step="0.01"
                                inputMode="decimal"
                                value={form.data.value}
                                onChange={(event) =>
                                    form.setData('value', event.target.value)
                                }
                                aria-invalid={!!form.errors.value}
                            />
                            {form.data.type === 'percent' && (
                                <InputGroupAddon align="inline-end">
                                    %
                                </InputGroupAddon>
                            )}
                        </InputGroup>
                        <FieldError>{form.errors.value}</FieldError>
                    </Field>
                )}
                {form.data.type === 'fixed' && (
                    <Field orientation="horizontal">
                        <Switch
                            id="discount-override"
                            checked={form.data.allow_override}
                            onCheckedChange={(checked) =>
                                form.setData('allow_override', checked)
                            }
                        />
                        <FieldContent>
                            <FieldLabel htmlFor="discount-override">
                                Allow amount override
                            </FieldLabel>
                            <FieldDescription>
                                Staff with the open discount permission may
                                change the amount when applying it.
                            </FieldDescription>
                        </FieldContent>
                    </Field>
                )}
                <FieldSet>
                    <FieldLegend variant="label">Applies to</FieldLegend>
                    <RadioGroup
                        value={form.data.scope}
                        onValueChange={(value) =>
                            form.setData(
                                'scope',
                                value as App.Enums.DiscountScope,
                            )
                        }
                    >
                        {scopes.map((option) => (
                            <FieldLabel
                                key={option.value}
                                htmlFor={`discount-scope-${option.value}`}
                            >
                                <Field orientation="horizontal">
                                    <RadioGroupItem
                                        id={`discount-scope-${option.value}`}
                                        value={option.value}
                                    />
                                    <FieldContent>
                                        <span className="text-sm font-medium">
                                            {option.label}
                                        </span>
                                        <FieldDescription>
                                            {
                                                scopeDescriptions[
                                                    option.value as App.Enums.DiscountScope
                                                ]
                                            }
                                        </FieldDescription>
                                    </FieldContent>
                                </Field>
                            </FieldLabel>
                        ))}
                    </RadioGroup>
                    <FieldError>{form.errors.scope}</FieldError>
                </FieldSet>
                {form.data.scope === 'selected' && (
                    <Field data-invalid={!!form.errors.transaction_code_ids}>
                        <FieldLabel htmlFor="discount-charges">
                            Charges
                        </FieldLabel>
                        <MultiCombobox
                            id="discount-charges"
                            name="transaction_code_ids"
                            items={chargeItems}
                            value={form.data.transaction_code_ids}
                            onValueChange={(value) =>
                                form.setData('transaction_code_ids', value)
                            }
                            placeholder="Choose charges"
                            invalid={!!form.errors.transaction_code_ids}
                        />
                        <FieldError>
                            {form.errors.transaction_code_ids}
                        </FieldError>
                    </Field>
                )}
                <Field orientation="horizontal">
                    <Switch
                        id="discount-active"
                        checked={form.data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="discount-active">
                            Available to apply
                        </FieldLabel>
                        <FieldDescription>
                            Staff can apply this discount. Turn it off to hide
                            it without deleting it.
                        </FieldDescription>
                    </FieldContent>
                </Field>
            </FieldGroup>
            <SheetFooter>
                <SheetClose render={<Button type="button" variant="outline" />}>
                    Cancel
                </SheetClose>
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    {discount ? 'Save changes' : 'Create discount'}
                </Button>
            </SheetFooter>
        </form>
    );
}

function DiscountTable({
    discounts,
    charges,
    scopes,
    currency,
    onEdit,
    onMove,
    onToggleActive,
    togglingIds,
}: {
    discounts: Discount[];
    charges: Charge[];
    scopes: Option[];
    currency: App.Enums.Currency;
    onEdit: (discount: Discount) => void;
    onMove: (from: number, to: number) => void;
    onToggleActive: (discount: Discount, active: boolean) => void;
    togglingIds: Set<number>;
}) {
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    });
    const chargeNames = new Map(
        charges.map((charge) => [charge.id, charge.name]),
    );
    const scopeLabel = (value: string) =>
        scopes.find((option) => option.value === value)?.label ?? value;
    const activeDiscounts = discounts.filter((discount) => discount.active);
    const activePositions = new Map(
        activeDiscounts.map((discount, position) => [discount.id, position]),
    );

    const pricing = (discount: Discount) => {
        if (discount.type === 'open' || discount.value === null) {
            return (
                <span className="text-muted-foreground">Enter when used</span>
            );
        }

        if (discount.type === 'percent') {
            return `${Number(discount.value).toFixed(2)}%`;
        }

        return (
            <>
                {money.format(Number(discount.value))}
                {discount.allowOverride && (
                    <div className="text-xs text-muted-foreground">
                        Override allowed
                    </div>
                )}
            </>
        );
    };

    return (
        <div className="overflow-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Available</TableHead>
                        <TableHead>Discount</TableHead>
                        <TableHead>Code</TableHead>
                        <TableHead className="text-right">Amount</TableHead>
                        <TableHead>Applies to</TableHead>
                        <TableHead>
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {discounts.map((discount) => {
                        const activePosition = activePositions.get(discount.id);

                        return (
                            <TableRow key={discount.id}>
                                <TableCell>
                                    <Switch
                                        size="sm"
                                        checked={discount.active}
                                        disabled={togglingIds.has(discount.id)}
                                        aria-label={`Make ${discount.name} ${discount.active ? 'unavailable' : 'available'}`}
                                        onCheckedChange={(active) =>
                                            onToggleActive(discount, active)
                                        }
                                    />
                                </TableCell>
                                <TableCell className="font-medium">
                                    {discount.name}
                                </TableCell>
                                <TableCell className="font-mono text-xs">
                                    {discount.code}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {pricing(discount)}
                                </TableCell>
                                <TableCell>
                                    {discount.scope === 'selected'
                                        ? discount.transactionCodeIds
                                              .map((id) => chargeNames.get(id))
                                              .filter(Boolean)
                                              .join(', ')
                                        : scopeLabel(discount.scope)}
                                </TableCell>
                                <TableCell>
                                    <div className="flex justify-end gap-1">
                                        {activePosition !== undefined && (
                                            <>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${discount.name} up`}
                                                    disabled={
                                                        activePosition === 0
                                                    }
                                                    onClick={() =>
                                                        onMove(
                                                            activePosition,
                                                            activePosition - 1,
                                                        )
                                                    }
                                                >
                                                    <ArrowUp />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${discount.name} down`}
                                                    disabled={
                                                        activePosition ===
                                                        activeDiscounts.length -
                                                            1
                                                    }
                                                    onClick={() =>
                                                        onMove(
                                                            activePosition,
                                                            activePosition + 1,
                                                        )
                                                    }
                                                >
                                                    <ArrowDown />
                                                </Button>
                                            </>
                                        )}
                                        {!discount.active && (
                                            <Badge variant="secondary">
                                                Inactive
                                            </Badge>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => onEdit(discount)}
                                        >
                                            Edit
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}

export default function DiscountsIndex({
    discounts,
    charges,
    types,
    scopes,
    currency,
}: {
    discounts: Discount[];
    charges: Charge[];
    types: Option[];
    scopes: Option[];
    currency: App.Enums.Currency;
}) {
    const [editing, setEditing] = useState<Discount | null | undefined>(
        undefined,
    );
    const [togglingIds, setTogglingIds] = useState<Set<number>>(new Set());
    const [query, setQuery] = useState('');
    const filtered = useMemo(() => {
        const normalized = query.trim().toLocaleLowerCase();

        const matching = normalized
            ? discounts.filter((discount) =>
                  [discount.name, discount.code].some((value) =>
                      value.toLocaleLowerCase().includes(normalized),
                  ),
              )
            : [...discounts];

        return matching.sort(
            (first, second) => Number(second.active) - Number(first.active),
        );
    }, [discounts, query]);
    const active = filtered.filter((discount) => discount.active);

    const move = (from: number, to: number) => {
        const order = discounts
            .filter((discount) => discount.active)
            .map((discount) => discount.id);
        const movingId = active[from]?.id;
        const targetId = active[to]?.id;

        if (movingId === undefined || targetId === undefined) {
            return;
        }

        const sourceIndex = order.indexOf(movingId);
        const targetIndex = order.indexOf(targetId);
        order.splice(targetIndex, 0, ...order.splice(sourceIndex, 1));

        router.put(reorder.url(), { order }, { preserveScroll: true });
    };

    const toggleActive = (discount: Discount, active: boolean) => {
        const setToggling = (toggling: boolean) => {
            setTogglingIds((current) => {
                const next = new Set(current);

                if (toggling) {
                    next.add(discount.id);
                } else {
                    next.delete(discount.id);
                }

                return next;
            });
        };

        router
            .optimistic<{ discounts: Discount[] }>((props) => ({
                discounts: props.discounts.map((item) =>
                    item.id === discount.id ? { ...item, active } : item,
                ),
            }))
            .put(
                update.url({ discount: discount.id }),
                {
                    ...formValues(discount),
                    active,
                },
                {
                    preserveScroll: true,
                    onStart: () => setToggling(true),
                    onFinish: () => setToggling(false),
                },
            );
    };

    return (
        <>
            <Head title="Discounts" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Discounts"
                    description="The discounts staff can apply on a folio or at booking."
                    actions={
                        <Button onClick={() => setEditing(null)}>
                            <Plus data-icon="inline-start" />
                            New discount
                        </Button>
                    }
                />

                {discounts.length === 0 ? (
                    <EmptyState
                        icon={Percent}
                        title="No discounts yet"
                        description="Add the first discount staff can apply."
                        action={
                            <Button onClick={() => setEditing(null)}>
                                <Plus data-icon="inline-start" />
                                New discount
                            </Button>
                        }
                    />
                ) : (
                    <>
                        <Field>
                            <FieldLabel htmlFor="discount-search">
                                Search discounts
                            </FieldLabel>
                            <Input
                                id="discount-search"
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Search by name or code"
                            />
                        </Field>

                        {filtered.length > 0 ? (
                            <DiscountTable
                                discounts={filtered}
                                charges={charges}
                                scopes={scopes}
                                currency={currency}
                                onEdit={setEditing}
                                onMove={move}
                                onToggleActive={toggleActive}
                                togglingIds={togglingIds}
                            />
                        ) : (
                            <EmptyState
                                title="No matching discounts"
                                description="Change the search or add a new discount."
                            />
                        )}
                    </>
                )}
            </div>

            <Sheet
                open={editing !== undefined}
                onOpenChange={(open) => !open && setEditing(undefined)}
            >
                <SheetContent className="data-[side=right]:sm:max-w-xl">
                    <SheetHeader>
                        <SheetTitle>
                            {editing ? `Edit ${editing.name}` : 'New discount'}
                        </SheetTitle>
                        <SheetDescription>
                            Set how much this discount takes off and which
                            charges it applies to.
                        </SheetDescription>
                    </SheetHeader>
                    {editing !== undefined && (
                        <DiscountForm
                            key={editing?.id ?? 'new'}
                            discount={editing}
                            charges={charges}
                            types={types}
                            scopes={scopes}
                            currency={currency}
                            onDone={() => setEditing(undefined)}
                        />
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}

DiscountsIndex.layout = {
    breadcrumbs: [{ title: 'Discounts', href: index() }],
};
