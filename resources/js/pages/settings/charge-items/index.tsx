import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, ReceiptText } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { PageHeader } from '@/components/pms/page-header';
import { suggestCode } from '@/lib/charge-code';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
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
    edit,
    index,
    reorder,
    store,
    update,
} from '@/routes/settings/charge-items';

type ChargeItem = App.Data.Catalogues.ChargeItemData;
type Tax = App.Data.Catalogues.ChargeItemTaxData;
type Option = { value: string; label: string };

/** The full values of an item, for saving a change that does not come from the edit page. */
function formValues(chargeItem: ChargeItem) {
    return {
        name: chargeItem.name,
        code: chargeItem.code,
        category: chargeItem.category,
        description: chargeItem.description ?? '',
        default_amount: chargeItem.defaultAmount ?? '',
        charging_unit: chargeItem.chargingUnit,
        allow_override: chargeItem.allowOverride,
        active: chargeItem.active,
        tax_ids: chargeItem.taxIds,
    };
}

function NewChargeItemDialog({
    open,
    onOpenChange,
    categories,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    categories: Option[];
}) {
    const form = useForm({ name: '', code: '', category: 'other' });
    const [codeEdited, setCodeEdited] = useState(false);

    const changeOpen = (next: boolean) => {
        if (!next) {
            form.reset();
            form.clearErrors();
            setCodeEdited(false);
        }

        onOpenChange(next);
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={changeOpen}
            title="New charge item"
            description="Name it here. You set the price and taxes on the next page."
            errors={form.errors}
            processing={form.processing}
            submitLabel="Create charge item"
            onSubmit={() => form.post(store.url())}
        >
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="new-charge-item-name">Name</FieldLabel>
                <Input
                    id="new-charge-item-name"
                    placeholder="e.g. Airport transfer"
                    maxLength={100}
                    value={form.data.name}
                    onChange={(event) => {
                        const name = event.target.value;

                        form.setData((data) => ({
                            ...data,
                            name,
                            code: codeEdited ? data.code : suggestCode(name),
                        }));
                    }}
                    aria-invalid={!!form.errors.name}
                    autoFocus
                />
                <FieldError>{form.errors.name}</FieldError>
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field data-invalid={!!form.errors.code}>
                    <FieldLabel htmlFor="new-charge-item-code">Code</FieldLabel>
                    <Input
                        id="new-charge-item-code"
                        placeholder="e.g. AIRPORT"
                        maxLength={20}
                        value={form.data.code}
                        onChange={(event) => {
                            setCodeEdited(true);
                            form.setData(
                                'code',
                                event.target.value.toUpperCase(),
                            );
                        }}
                        aria-invalid={!!form.errors.code}
                    />
                    <FieldDescription>
                        Used in reports and accounting exports.
                    </FieldDescription>
                    <FieldError>{form.errors.code}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.category}>
                    <FieldLabel htmlFor="new-charge-item-category">
                        Category
                    </FieldLabel>
                    <EnumSelect
                        id="new-charge-item-category"
                        name="category"
                        options={categories}
                        value={form.data.category}
                        onValueChange={(value) =>
                            form.setData('category', value)
                        }
                        aria-invalid={!!form.errors.category}
                    />
                    <FieldError>{form.errors.category}</FieldError>
                </Field>
            </div>
        </FormOverlay>
    );
}

function ChargeItemTable({
    chargeItems,
    taxes,
    chargingUnits,
    currency,
    onMove,
    onToggleActive,
    togglingIds,
}: {
    chargeItems: ChargeItem[];
    taxes: Tax[];
    chargingUnits: Option[];
    currency: App.Enums.Currency;
    onMove?: (from: number, to: number) => void;
    onToggleActive: (chargeItem: ChargeItem, active: boolean) => void;
    togglingIds: Set<number>;
}) {
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    });
    const taxNames = new Map(taxes.map((tax) => [tax.id, tax.name]));
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
                        <TableHead>Code</TableHead>
                        <TableHead>Charged</TableHead>
                        <TableHead className="text-right">Amount</TableHead>
                        <TableHead>Taxes</TableHead>
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
                                        <span className="flex items-center gap-2 font-medium">
                                            {chargeItem.name}
                                            {chargeItem.autoAdd && (
                                                <Badge variant="outline">
                                                    Auto
                                                </Badge>
                                            )}
                                            {chargeItem.needsChildPriceReview && (
                                                <Badge variant="outline">
                                                    Check child prices
                                                </Badge>
                                            )}
                                        </span>
                                        {chargeItem.description && (
                                            <span className="max-w-xs truncate text-xs text-muted-foreground">
                                                {chargeItem.description}
                                            </span>
                                        )}
                                    </div>
                                </TableCell>
                                <TableCell className="font-mono text-xs">
                                    {chargeItem.code}
                                </TableCell>
                                <TableCell>
                                    {unitLabel(chargeItem.chargingUnit)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {chargeItem.defaultAmount === null ? (
                                        <span className="text-muted-foreground">
                                            Enter when charging
                                        </span>
                                    ) : (
                                        <>
                                            {money.format(
                                                Number(
                                                    chargeItem.defaultAmount,
                                                ),
                                            )}
                                            {chargeItem.allowOverride && (
                                                <div className="text-xs text-muted-foreground">
                                                    Override allowed
                                                </div>
                                            )}
                                        </>
                                    )}
                                </TableCell>
                                <TableCell>
                                    {chargeItem.taxIds.length > 0 ? (
                                        chargeItem.taxIds
                                            .map((id) => taxNames.get(id))
                                            .filter(Boolean)
                                            .join(', ')
                                    ) : (
                                        <span className="text-muted-foreground">
                                            None
                                        </span>
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
                                        <Link
                                            href={edit({
                                                chargeItem: chargeItem.id,
                                            })}
                                            className={buttonVariants({
                                                variant: 'ghost',
                                                size: 'sm',
                                            })}
                                        >
                                            Edit
                                        </Link>
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
    taxes,
    categories,
    chargingUnits,
    currency,
}: {
    chargeItems: ChargeItem[];
    taxes: Tax[];
    categories: Option[];
    chargingUnits: Option[];
    currency: App.Enums.Currency;
}) {
    const [creating, setCreating] = useState(false);
    const [togglingIds, setTogglingIds] = useState<Set<number>>(new Set());
    const [query, setQuery] = useState('');
    const filtered = useMemo(() => {
        const normalized = query.trim().toLocaleLowerCase();

        const matching = normalized
            ? chargeItems.filter((chargeItem) =>
                  [
                      chargeItem.name,
                      chargeItem.description ?? '',
                      chargeItem.code,
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
                    ...formValues(chargeItem),
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
                        <Button onClick={() => setCreating(true)}>
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
                            <Button onClick={() => setCreating(true)}>
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
                                taxes={taxes}
                                chargingUnits={chargingUnits}
                                currency={currency}
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

            <NewChargeItemDialog
                open={creating}
                onOpenChange={setCreating}
                categories={categories}
            />
        </>
    );
}

ChargeItemsIndex.layout = {
    breadcrumbs: [{ title: 'Charge items', href: index() }],
};
