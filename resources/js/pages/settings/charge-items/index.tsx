import { Head, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, ReceiptText } from 'lucide-react';
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
import { Textarea } from '@/components/ui/textarea';
import { index, reorder, store, update } from '@/routes/settings/charge-items';

type ChargeItem = App.Data.Catalogues.ChargeItemData;
type TransactionCode = App.Data.Catalogues.ChargeTransactionCodeData;
type Option = { value: string; label: string };

type FormData = {
    name: string;
    description: string;
    transaction_code_id: string;
    default_amount: string;
    charging_unit: string;
    allow_override: boolean;
    active: boolean;
};

function formValues(
    chargeItem: ChargeItem | null,
    transactionCodes: TransactionCode[],
): FormData {
    return {
        name: chargeItem?.name ?? '',
        description: chargeItem?.description ?? '',
        transaction_code_id: String(
            chargeItem?.transactionCodeId ??
                transactionCodes.find((code) => code.active)?.id ??
                '',
        ),
        default_amount: chargeItem?.defaultAmount ?? '',
        charging_unit: chargeItem?.chargingUnit ?? 'per_item',
        allow_override: chargeItem?.allowOverride ?? true,
        active: chargeItem?.active ?? true,
    };
}

function ChargeItemForm({
    chargeItem,
    transactionCodes,
    chargingUnits,
    currency,
    onDone,
}: {
    chargeItem: ChargeItem | null;
    transactionCodes: TransactionCode[];
    chargingUnits: Option[];
    currency: App.Enums.Currency;
    onDone: () => void;
}) {
    const form = useForm<FormData>(formValues(chargeItem, transactionCodes));
    const availableCodes = transactionCodes.filter(
        (code) => code.active || code.id === chargeItem?.transactionCodeId,
    );
    const codeItems = availableCodes.map((code) => ({
        value: String(code.id),
        label: code.active ? code.label : `${code.label} (inactive)`,
    }));

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onDone };

        if (chargeItem) {
            form.put(update.url({ chargeItem: chargeItem.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <FieldGroup className="flex-1 overflow-y-auto px-4">
                <Field data-invalid={!!form.errors.name}>
                    <FieldLabel htmlFor="charge-item-name">Name</FieldLabel>
                    <Input
                        id="charge-item-name"
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
                <Field data-invalid={!!form.errors.description}>
                    <FieldLabel htmlFor="charge-item-description">
                        Description
                    </FieldLabel>
                    <Textarea
                        id="charge-item-description"
                        maxLength={2000}
                        value={form.data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                        aria-invalid={!!form.errors.description}
                    />
                    <FieldDescription>
                        Optional guidance shown to staff when choosing a charge.
                    </FieldDescription>
                    <FieldError>{form.errors.description}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.transaction_code_id}>
                    <FieldLabel htmlFor="charge-item-code">
                        Transaction code
                    </FieldLabel>
                    <Select
                        items={codeItems}
                        value={form.data.transaction_code_id}
                        onValueChange={(value) =>
                            form.setData(
                                'transaction_code_id',
                                (value as string | null) ?? '',
                            )
                        }
                    >
                        <SelectTrigger
                            id="charge-item-code"
                            aria-invalid={!!form.errors.transaction_code_id}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent alignItemWithTrigger={false}>
                            <SelectGroup>
                                {codeItems.map((code) => (
                                    <SelectItem
                                        key={code.value}
                                        value={code.value}
                                    >
                                        {code.label}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <FieldDescription>
                        Controls accounting and reporting classification.
                    </FieldDescription>
                    <FieldError>{form.errors.transaction_code_id}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.default_amount}>
                    <FieldLabel htmlFor="charge-item-amount">
                        Default amount
                    </FieldLabel>
                    <InputGroup>
                        <InputGroupAddon>{currency}</InputGroupAddon>
                        <InputGroupInput
                            id="charge-item-amount"
                            type="number"
                            min="0.01"
                            max="9999999999.99"
                            step="0.01"
                            inputMode="decimal"
                            value={form.data.default_amount}
                            onChange={(event) =>
                                form.setData(
                                    'default_amount',
                                    event.target.value,
                                )
                            }
                            aria-invalid={!!form.errors.default_amount}
                        />
                    </InputGroup>
                    <FieldError>{form.errors.default_amount}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.charging_unit}>
                    <FieldLabel htmlFor="charge-item-unit">Charged</FieldLabel>
                    <EnumSelect
                        id="charge-item-unit"
                        options={chargingUnits}
                        value={form.data.charging_unit}
                        onValueChange={(value) =>
                            form.setData('charging_unit', value)
                        }
                        aria-invalid={!!form.errors.charging_unit}
                    />
                    <FieldError>{form.errors.charging_unit}</FieldError>
                </Field>
                <Field orientation="horizontal">
                    <Switch
                        id="charge-item-override"
                        checked={form.data.allow_override}
                        onCheckedChange={(checked) =>
                            form.setData('allow_override', checked)
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="charge-item-override">
                            Allow amount override
                        </FieldLabel>
                        <FieldDescription>
                            Staff may change the default amount when posting the
                            charge.
                        </FieldDescription>
                    </FieldContent>
                </Field>
                <Field orientation="horizontal">
                    <Switch
                        id="charge-item-active"
                        checked={form.data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="charge-item-active">
                            Active
                        </FieldLabel>
                        <FieldDescription>
                            Active items are available for new charges.
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
                    {chargeItem ? 'Save changes' : 'Create charge item'}
                </Button>
            </SheetFooter>
        </form>
    );
}

function ChargeItemTable({
    chargeItems,
    chargingUnits,
    currency,
    onEdit,
    onMove,
    onToggleActive,
    togglingIds,
}: {
    chargeItems: ChargeItem[];
    chargingUnits: Option[];
    currency: App.Enums.Currency;
    onEdit: (chargeItem: ChargeItem) => void;
    onMove?: (from: number, to: number) => void;
    onToggleActive: (chargeItem: ChargeItem, active: boolean) => void;
    togglingIds: Set<number>;
}) {
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    });
    const unitLabel = (value: string) =>
        chargingUnits.find((option) => option.value === value)?.label ?? value;
    const activeChargeItems = chargeItems.filter(
        (chargeItem) => chargeItem.active,
    );
    const activePositions = new Map(
        activeChargeItems.map((chargeItem, position) => [
            chargeItem.id,
            position,
        ]),
    );

    return (
        <div className="overflow-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Available</TableHead>
                        <TableHead>Charge item</TableHead>
                        <TableHead>Transaction code</TableHead>
                        <TableHead>Charged</TableHead>
                        <TableHead className="text-right">Amount</TableHead>
                        <TableHead>
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {chargeItems.map((chargeItem) => {
                        const activePosition = activePositions.get(
                            chargeItem.id,
                        );

                        return (
                            <TableRow key={chargeItem.id}>
                                <TableCell>
                                    <Switch
                                        size="sm"
                                        checked={chargeItem.active}
                                        disabled={togglingIds.has(
                                            chargeItem.id,
                                        )}
                                        aria-label={`Make ${chargeItem.name} ${chargeItem.active ? 'unavailable' : 'available'}`}
                                        onCheckedChange={(active) =>
                                            onToggleActive(chargeItem, active)
                                        }
                                    />
                                </TableCell>
                                <TableCell>
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <span className="font-medium">
                                            {chargeItem.name}
                                        </span>
                                        {chargeItem.description && (
                                            <span className="max-w-xs truncate text-xs text-muted-foreground">
                                                {chargeItem.description}
                                            </span>
                                        )}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    {chargeItem.transactionCode}
                                </TableCell>
                                <TableCell>
                                    {unitLabel(chargeItem.chargingUnit)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money.format(
                                        Number(chargeItem.defaultAmount),
                                    )}
                                    {chargeItem.allowOverride && (
                                        <div className="text-xs text-muted-foreground">
                                            Override allowed
                                        </div>
                                    )}
                                </TableCell>
                                <TableCell>
                                    <div className="flex justify-end gap-1">
                                        {onMove &&
                                            activePosition !== undefined && (
                                                <>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`Move ${chargeItem.name} up`}
                                                        disabled={
                                                            activePosition === 0
                                                        }
                                                        onClick={() =>
                                                            onMove(
                                                                activePosition,
                                                                activePosition -
                                                                    1,
                                                            )
                                                        }
                                                    >
                                                        <ArrowUp />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`Move ${chargeItem.name} down`}
                                                        disabled={
                                                            activePosition ===
                                                            activeChargeItems.length -
                                                                1
                                                        }
                                                        onClick={() =>
                                                            onMove(
                                                                activePosition,
                                                                activePosition +
                                                                    1,
                                                            )
                                                        }
                                                    >
                                                        <ArrowDown />
                                                    </Button>
                                                </>
                                            )}
                                        {!chargeItem.active && (
                                            <Badge variant="secondary">
                                                Inactive
                                            </Badge>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => onEdit(chargeItem)}
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

export default function ChargeItemsIndex({
    chargeItems,
    transactionCodes,
    chargingUnits,
    currency,
}: {
    chargeItems: ChargeItem[];
    transactionCodes: TransactionCode[];
    chargingUnits: Option[];
    currency: App.Enums.Currency;
}) {
    const [editing, setEditing] = useState<ChargeItem | null | undefined>(
        undefined,
    );
    const [togglingIds, setTogglingIds] = useState<Set<number>>(new Set());
    const [query, setQuery] = useState('');
    const filtered = useMemo(() => {
        const normalized = query.trim().toLocaleLowerCase();

        const matching = normalized
            ? chargeItems.filter((chargeItem) =>
                  [
                      chargeItem.name,
                      chargeItem.description ?? '',
                      chargeItem.transactionCode,
                  ].some((value) =>
                      value.toLocaleLowerCase().includes(normalized),
                  ),
              )
            : [...chargeItems];

        return matching.sort(
            (first, second) => Number(second.active) - Number(first.active),
        );
    }, [chargeItems, query]);
    const active = filtered.filter((chargeItem) => chargeItem.active);

    const move = (from: number, to: number) => {
        const order = chargeItems
            .filter((chargeItem) => chargeItem.active)
            .map((chargeItem) => chargeItem.id);
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

    const toggleActive = (chargeItem: ChargeItem, active: boolean) => {
        const setToggling = (toggling: boolean) => {
            setTogglingIds((current) => {
                const next = new Set(current);

                if (toggling) {
                    next.add(chargeItem.id);
                } else {
                    next.delete(chargeItem.id);
                }

                return next;
            });
        };

        router
            .optimistic<{ chargeItems: ChargeItem[] }>((props) => ({
                chargeItems: props.chargeItems.map((item) =>
                    item.id === chargeItem.id ? { ...item, active } : item,
                ),
            }))
            .put(
                update.url({ chargeItem: chargeItem.id }),
                {
                    ...formValues(chargeItem, transactionCodes),
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
            <Head title="Charge items" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Charge items"
                    description="The products and services staff can post to a folio."
                    actions={
                        <Button onClick={() => setEditing(null)}>
                            <Plus data-icon="inline-start" />
                            New charge item
                        </Button>
                    }
                />

                {chargeItems.length === 0 ? (
                    <EmptyState
                        icon={ReceiptText}
                        title="No charge items yet"
                        description="Add the first product or service staff can post to a folio."
                        action={
                            <Button onClick={() => setEditing(null)}>
                                <Plus data-icon="inline-start" />
                                New charge item
                            </Button>
                        }
                    />
                ) : (
                    <>
                        <Field>
                            <FieldLabel htmlFor="charge-item-search">
                                Search charge items
                            </FieldLabel>
                            <Input
                                id="charge-item-search"
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Search by name, description, or code"
                            />
                        </Field>

                        {filtered.length > 0 ? (
                            <ChargeItemTable
                                chargeItems={filtered}
                                chargingUnits={chargingUnits}
                                currency={currency}
                                onEdit={setEditing}
                                onMove={move}
                                onToggleActive={toggleActive}
                                togglingIds={togglingIds}
                            />
                        ) : (
                            <EmptyState
                                title="No matching charge items"
                                description="Change the search or add a new charge item."
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
                                : 'New charge item'}
                        </SheetTitle>
                        <SheetDescription>
                            Configure how staff select and price this charge.
                        </SheetDescription>
                    </SheetHeader>
                    {editing !== undefined && (
                        <ChargeItemForm
                            key={editing?.id ?? 'new'}
                            chargeItem={editing}
                            transactionCodes={transactionCodes}
                            chargingUnits={chargingUnits}
                            currency={currency}
                            onDone={() => setEditing(undefined)}
                        />
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}

ChargeItemsIndex.layout = {
    breadcrumbs: [{ title: 'Charge items', href: index() }],
};
