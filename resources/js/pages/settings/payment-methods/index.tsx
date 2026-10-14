import { Head, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, CreditCard, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
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
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
import {
    index,
    reorder,
    store,
    update,
} from '@/routes/settings/payment-methods';

type PaymentMethod = App.Data.Catalogues.PaymentMethodData;
type ChargeItem = App.Data.Catalogues.SurchargeChargeItemData;
type Option = { value: string; label: string };
type TypeOption = Option & { requiresReference: boolean };

type FormData = {
    name: string;
    type: string;
    code: string;
    surcharge_type: string;
    surcharge_value: string;
    surcharge_charge_item_id: string;
    requires_reference: boolean;
    active: boolean;
};

function formValues(
    paymentMethod: PaymentMethod | null,
    types: TypeOption[],
): FormData {
    if (paymentMethod === null) {
        return {
            name: '',
            type: types[0]?.value ?? '',
            code: '',
            surcharge_type: 'none',
            surcharge_value: '',
            surcharge_charge_item_id: '',
            requires_reference: types[0]?.requiresReference ?? false,
            active: true,
        };
    }

    return {
        name: paymentMethod.name,
        type: paymentMethod.type,
        code: paymentMethod.code,
        surcharge_type: paymentMethod.surchargeType,
        surcharge_value: paymentMethod.surchargeValue ?? '',
        surcharge_charge_item_id: String(
            paymentMethod.surchargeChargeItemId ?? '',
        ),
        requires_reference: paymentMethod.requiresReference,
        active: paymentMethod.active,
    };
}

function PaymentMethodForm({
    paymentMethod,
    types,
    chargeItems,
    surchargeTypes,
    currency,
    onDone,
}: {
    paymentMethod: PaymentMethod | null;
    types: TypeOption[];
    chargeItems: ChargeItem[];
    surchargeTypes: Option[];
    currency: App.Data.Property.CurrencyData;
    onDone: () => void;
}) {
    const form = useForm<FormData>(formValues(paymentMethod, types));
    const isSystem = paymentMethod?.isSystem ?? false;
    const availableChargeItems = chargeItems.filter(
        (chargeItem) =>
            chargeItem.active ||
            chargeItem.id === paymentMethod?.surchargeChargeItemId,
    );
    const chargeItemOptions = availableChargeItems.map((chargeItem) => ({
        value: String(chargeItem.id),
        label: chargeItem.active
            ? chargeItem.label
            : `${chargeItem.label} (inactive)`,
    }));

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onDone };

        if (paymentMethod) {
            form.put(update.url({ paymentMethod: paymentMethod.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    const changeType = (value: string) => {
        form.setData((data) => ({
            ...data,
            type: value,
            requires_reference:
                types.find((type) => type.value === value)?.requiresReference ??
                data.requires_reference,
        }));
    };

    const changeSurchargeType = (value: string) => {
        form.setData('surcharge_type', value);

        if (value === 'none') {
            form.setData('surcharge_value', '');
            form.setData('surcharge_charge_item_id', '');
            form.clearErrors('surcharge_value', 'surcharge_charge_item_id');
        }
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <FieldGroup className="flex-1 overflow-y-auto px-4">
                <Field data-invalid={!!form.errors.name}>
                    <FieldLabel htmlFor="payment-method-name">Name</FieldLabel>
                    <Input
                        id="payment-method-name"
                        placeholder="e.g. DuitNow QR"
                        name="name"
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
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!form.errors.type}>
                        <FieldLabel htmlFor="payment-method-type">
                            Type
                        </FieldLabel>
                        {paymentMethod ? (
                            <Input
                                id="payment-method-type"
                                value={paymentMethod.typeLabel}
                                readOnly
                            />
                        ) : (
                            <EnumSelect
                                id="payment-method-type"
                                name="type"
                                options={types}
                                value={form.data.type}
                                onValueChange={changeType}
                                aria-invalid={!!form.errors.type}
                            />
                        )}
                        <FieldDescription>
                            {paymentMethod
                                ? 'The type cannot change after the method is added.'
                                : 'Sets the report category. It cannot change later.'}
                        </FieldDescription>
                        <FieldError>{form.errors.type}</FieldError>
                    </Field>
                    <Field data-invalid={!!form.errors.code}>
                        <FieldLabel htmlFor="payment-method-code">
                            Code
                        </FieldLabel>
                        <Input
                            id="payment-method-code"
                            placeholder="e.g. DUITNOW"
                            name="code"
                            maxLength={20}
                            value={form.data.code}
                            onChange={(event) =>
                                form.setData(
                                    'code',
                                    event.target.value.toUpperCase(),
                                )
                            }
                            readOnly={isSystem}
                            aria-invalid={!!form.errors.code}
                        />
                        <FieldDescription>
                            {isSystem
                                ? 'Preset methods keep their code.'
                                : 'Used in reports and accounting exports.'}
                        </FieldDescription>
                        <FieldError>{form.errors.code}</FieldError>
                    </Field>
                </div>
                <Field data-invalid={!!form.errors.surcharge_type}>
                    <FieldLabel htmlFor="payment-method-surcharge-type">
                        Surcharge
                    </FieldLabel>
                    <EnumSelect
                        id="payment-method-surcharge-type"
                        name="surcharge_type"
                        options={surchargeTypes}
                        value={form.data.surcharge_type}
                        onValueChange={changeSurchargeType}
                        aria-invalid={!!form.errors.surcharge_type}
                    />
                    <FieldDescription>
                        A surcharge posts as a separate charge when this method
                        is used.
                    </FieldDescription>
                    <FieldError>{form.errors.surcharge_type}</FieldError>
                </Field>
                {form.data.surcharge_type !== 'none' && (
                    <>
                        <Field data-invalid={!!form.errors.surcharge_value}>
                            <FieldLabel htmlFor="payment-method-surcharge-value">
                                Surcharge value
                            </FieldLabel>
                            <InputGroup>
                                {form.data.surcharge_type === 'fixed' && (
                                    <InputGroupAddon>
                                        {currency.code}
                                    </InputGroupAddon>
                                )}
                                <InputGroupInput
                                    id="payment-method-surcharge-value"
                                    placeholder="0.00"
                                    name="surcharge_value"
                                    type="number"
                                    min="0.01"
                                    max={
                                        form.data.surcharge_type === 'percent'
                                            ? '100'
                                            : '9999999999.99'
                                    }
                                    step="0.01"
                                    inputMode="decimal"
                                    value={form.data.surcharge_value}
                                    onChange={(event) =>
                                        form.setData(
                                            'surcharge_value',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={!!form.errors.surcharge_value}
                                />
                                {form.data.surcharge_type === 'percent' && (
                                    <InputGroupAddon align="inline-end">
                                        %
                                    </InputGroupAddon>
                                )}
                            </InputGroup>
                            <FieldError>
                                {form.errors.surcharge_value}
                            </FieldError>
                        </Field>
                        <Field
                            data-invalid={
                                !!form.errors.surcharge_charge_item_id
                            }
                        >
                            <FieldLabel htmlFor="payment-method-charge-item">
                                Surcharge charge item
                            </FieldLabel>
                            <Select
                                name="surcharge_charge_item_id"
                                items={chargeItemOptions}
                                value={
                                    form.data.surcharge_charge_item_id || null
                                }
                                onValueChange={(value) =>
                                    form.setData(
                                        'surcharge_charge_item_id',
                                        (value as string | null) ?? '',
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="payment-method-charge-item"
                                    aria-invalid={
                                        !!form.errors.surcharge_charge_item_id
                                    }
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent alignItemWithTrigger={false}>
                                    <SelectGroup>
                                        {chargeItemOptions.map((chargeItem) => (
                                            <SelectItem
                                                key={chargeItem.value}
                                                value={chargeItem.value}
                                            >
                                                {chargeItem.label}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                            <FieldDescription>
                                Only active charge items can be newly selected.
                            </FieldDescription>
                            <FieldError>
                                {form.errors.surcharge_charge_item_id}
                            </FieldError>
                        </Field>
                    </>
                )}
                <Field orientation="horizontal">
                    <Switch
                        id="payment-method-reference"
                        name="requires_reference"
                        checked={form.data.requires_reference}
                        onCheckedChange={(checked) =>
                            form.setData('requires_reference', checked)
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="payment-method-reference">
                            Require a reference
                        </FieldLabel>
                        <FieldDescription>
                            Staff must enter an approval code or transfer
                            reference.
                        </FieldDescription>
                    </FieldContent>
                </Field>
                <Field orientation="horizontal">
                    <Switch
                        id="payment-method-active"
                        name="active"
                        checked={form.data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="payment-method-active">
                            Available for payments
                        </FieldLabel>
                        <FieldDescription>
                            Staff can record payments with this method. Turn it
                            off to hide the method without deleting it.
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
                    {paymentMethod ? 'Save changes' : 'Add payment method'}
                </Button>
            </SheetFooter>
        </form>
    );
}

function PaymentMethodTable({
    paymentMethods,
    surchargeTypes,
    currency,
    onEdit,
    onMove,
    onToggleActive,
    togglingIds,
}: {
    paymentMethods: PaymentMethod[];
    surchargeTypes: Option[];
    currency: App.Data.Property.CurrencyData;
    onEdit: (paymentMethod: PaymentMethod) => void;
    onMove: (from: number, to: number) => void;
    onToggleActive: (paymentMethod: PaymentMethod, active: boolean) => void;
    togglingIds: Set<number>;
}) {
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.code,
    });
    const activeMethods = paymentMethods.filter(
        (paymentMethod) => paymentMethod.active,
    );
    const activePositions = new Map(
        activeMethods.map((paymentMethod, position) => [
            paymentMethod.id,
            position,
        ]),
    );
    const surchargeLabel = (paymentMethod: PaymentMethod) => {
        if (
            paymentMethod.surchargeType === 'none' ||
            paymentMethod.surchargeValue === null
        ) {
            return surchargeTypes.find((type) => type.value === 'none')?.label;
        }

        const value =
            paymentMethod.surchargeType === 'fixed'
                ? money.format(Number(paymentMethod.surchargeValue))
                : `${Number(paymentMethod.surchargeValue).toLocaleString()}%`;

        return paymentMethod.surchargeChargeItem
            ? `${value} · ${paymentMethod.surchargeChargeItem}`
            : value;
    };

    return (
        <div className="overflow-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Available</TableHead>
                        <TableHead>Payment method</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Transaction code</TableHead>
                        <TableHead>Reference</TableHead>
                        <TableHead>Surcharge</TableHead>
                        <TableHead>
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {paymentMethods.map((paymentMethod) => {
                        const activePosition = activePositions.get(
                            paymentMethod.id,
                        );

                        return (
                            <TableRow key={paymentMethod.id}>
                                <TableCell>
                                    <Switch
                                        size="sm"
                                        checked={paymentMethod.active}
                                        disabled={togglingIds.has(
                                            paymentMethod.id,
                                        )}
                                        aria-label={`Make ${paymentMethod.name} ${paymentMethod.active ? 'unavailable' : 'available'}`}
                                        onCheckedChange={(active) =>
                                            onToggleActive(
                                                paymentMethod,
                                                active,
                                            )
                                        }
                                    />
                                </TableCell>
                                <TableCell>
                                    <span className="font-medium">
                                        {paymentMethod.name}
                                    </span>
                                </TableCell>
                                <TableCell>{paymentMethod.typeLabel}</TableCell>
                                <TableCell>
                                    {paymentMethod.transactionCode}
                                </TableCell>
                                <TableCell>
                                    {paymentMethod.requiresReference
                                        ? 'Required'
                                        : 'Optional'}
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {surchargeLabel(paymentMethod)}
                                </TableCell>
                                <TableCell>
                                    <div className="flex justify-end gap-1">
                                        {activePosition !== undefined ? (
                                            <>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${paymentMethod.name} up`}
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
                                                    aria-label={`Move ${paymentMethod.name} down`}
                                                    disabled={
                                                        activePosition ===
                                                        activeMethods.length - 1
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
                                        ) : (
                                            <Badge variant="secondary">
                                                Inactive
                                            </Badge>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                onEdit(paymentMethod)
                                            }
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

export default function PaymentMethodsIndex({
    paymentMethods,
    types,
    chargeItems,
    surchargeTypes,
    currency,
}: {
    paymentMethods: PaymentMethod[];
    types: TypeOption[];
    chargeItems: ChargeItem[];
    surchargeTypes: Option[];
    currency: App.Data.Property.CurrencyData;
}) {
    const [editing, setEditing] = useState<PaymentMethod | null | undefined>(
        undefined,
    );
    const [togglingIds, setTogglingIds] = useState<Set<number>>(new Set());
    const [query, setQuery] = useState('');
    const filtered = useMemo(() => {
        const normalized = query.trim().toLocaleLowerCase();

        const matching = normalized
            ? paymentMethods.filter((paymentMethod) =>
                  [
                      paymentMethod.name,
                      paymentMethod.typeLabel,
                      paymentMethod.transactionCode,
                  ].some((value) =>
                      value.toLocaleLowerCase().includes(normalized),
                  ),
              )
            : [...paymentMethods];

        return matching.sort(
            (first, second) => Number(second.active) - Number(first.active),
        );
    }, [paymentMethods, query]);

    const move = (from: number, to: number) => {
        const activeFiltered = filtered.filter(
            (paymentMethod) => paymentMethod.active,
        );
        const movingId = activeFiltered[from]?.id;
        const targetId = activeFiltered[to]?.id;
        const order = paymentMethods
            .filter((paymentMethod) => paymentMethod.active)
            .map((paymentMethod) => paymentMethod.id);

        if (movingId === undefined || targetId === undefined) {
            return;
        }

        const sourceIndex = order.indexOf(movingId);
        const targetIndex = order.indexOf(targetId);
        order.splice(targetIndex, 0, ...order.splice(sourceIndex, 1));

        router.put(reorder.url(), { order }, { preserveScroll: true });
    };

    const toggleActive = (paymentMethod: PaymentMethod, active: boolean) => {
        const setToggling = (toggling: boolean) => {
            setTogglingIds((current) => {
                const next = new Set(current);

                if (toggling) {
                    next.add(paymentMethod.id);
                } else {
                    next.delete(paymentMethod.id);
                }

                return next;
            });
        };

        router
            .optimistic<{ paymentMethods: PaymentMethod[] }>((props) => ({
                paymentMethods: props.paymentMethods.map((method) =>
                    method.id === paymentMethod.id
                        ? { ...method, active }
                        : method,
                ),
            }))
            .put(
                update.url({ paymentMethod: paymentMethod.id }),
                { ...formValues(paymentMethod, types), active },
                {
                    preserveScroll: true,
                    onStart: () => setToggling(true),
                    onFinish: () => setToggling(false),
                },
            );
    };

    return (
        <>
            <Head title="Payment methods" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Payment methods"
                    description="The ways staff can record and classify payments."
                    actions={
                        <Button onClick={() => setEditing(null)}>
                            <Plus data-icon="inline-start" />
                            New payment method
                        </Button>
                    }
                />

                {paymentMethods.length === 0 ? (
                    <EmptyState
                        icon={CreditCard}
                        title="No payment methods"
                        description="Add the first way guests can pay."
                        action={
                            <Button onClick={() => setEditing(null)}>
                                <Plus data-icon="inline-start" />
                                New payment method
                            </Button>
                        }
                    />
                ) : (
                    <>
                        <Field>
                            <FieldLabel htmlFor="payment-method-search">
                                Search payment methods
                            </FieldLabel>
                            <Input
                                id="payment-method-search"
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Search by name, type, or code"
                            />
                        </Field>

                        {filtered.length > 0 ? (
                            <PaymentMethodTable
                                paymentMethods={filtered}
                                surchargeTypes={surchargeTypes}
                                currency={currency}
                                onEdit={setEditing}
                                onMove={move}
                                onToggleActive={toggleActive}
                                togglingIds={togglingIds}
                            />
                        ) : (
                            <EmptyState
                                title="No matching payment methods"
                                description="Change the search to see another method."
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
                            {editing
                                ? `Edit ${editing.name}`
                                : 'New payment method'}
                        </SheetTitle>
                        <SheetDescription>
                            Configure its availability, references, and optional
                            surcharge.
                        </SheetDescription>
                    </SheetHeader>
                    {editing !== undefined && (
                        <PaymentMethodForm
                            key={editing?.id ?? 'new'}
                            paymentMethod={editing}
                            types={types}
                            chargeItems={chargeItems}
                            surchargeTypes={surchargeTypes}
                            currency={currency}
                            onDone={() => setEditing(undefined)}
                        />
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}

PaymentMethodsIndex.layout = {
    breadcrumbs: [{ title: 'Payment methods', href: index() }],
};
