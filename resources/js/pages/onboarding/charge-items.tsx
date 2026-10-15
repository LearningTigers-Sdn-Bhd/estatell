import { Head, router, setLayoutProps, useHttp } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Plus, Trash2 } from 'lucide-react';
import { Fragment, useRef, useState } from 'react';
import ChargeItemController from '@/actions/App/Http/Controllers/Onboarding/ChargeItemController';
import Heading from '@/components/heading';
import { taxLabel } from '@/components/pms/charge-item-taxes-field';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
import { StepForm } from '@/components/pms/onboarding/step-form';
import { MoneyInput } from '@/components/pms/money-input';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
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

type Item = App.Data.Onboarding.OnboardingChargeItemData;
type Tax = App.Data.Catalogues.ChargeItemTaxData;
type Option = { value: string; label: string };
type Row = {
    key: string;
    id: number | null;
    name: string;
    category: string;
    default_amount: string;
    charging_unit: string;
    allow_override: boolean;
    active: boolean;
    tax_ids: number[];
    code: string;
    is_starter: boolean;
};
type Payload = { rows: Row[] };

const FORM_ID = 'charge-items-form';
const CODE_LENGTH = 20;
const CHILD_UNITS = ['per_guest', 'per_guest_night'];

/**
 * The code a new row shows: the name in capitals with underscores (Extra bed → EXTRA_BED), and a number when the code
 * is taken. The server derives the saved code the same way.
 */
function codeFor(name: string, taken: Set<string>): string {
    const base =
        name
            .toUpperCase()
            .replace(/[^A-Z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '') || 'CHARGE';

    for (let number = 1; ; number++) {
        const suffix = number === 1 ? '' : `_${number}`;
        const candidate = `${base.slice(0, CODE_LENGTH - suffix.length).replace(/_+$/, '')}${suffix}`;

        if (!taken.has(candidate)) {
            return candidate;
        }
    }
}

/** The code each row shows, in row order. A new row takes a number when an earlier code has its name. */
function codesOf(rows: Row[], takenCodes: string[]): string[] {
    const taken = new Set(takenCodes);

    return rows.map((row) => {
        if (row.id !== null) {
            return row.code;
        }

        const code = row.name.trim() === '' ? '' : codeFor(row.name, taken);
        taken.add(code);

        return code;
    });
}

const rowOf = (item: Item): Row => ({
    key: `id-${item.id}`,
    id: item.id,
    name: item.name,
    category: item.category,
    default_amount: item.defaultAmount ?? '',
    charging_unit: item.chargingUnit,
    allow_override: item.allowOverride,
    active: item.active,
    tax_ids: item.taxIds,
    code: item.code,
    is_starter: item.isStarter,
});

export default function ChargeItems({
    chargeItems,
    taxes,
    presetTaxIds,
    takenCodes,
    categories,
    chargingUnits,
    currency,
    maxRows,
    highlightTaxes,
    locked,
    blockedBy,
}: {
    chargeItems: Item[];
    taxes: Tax[];
    presetTaxIds: number[];
    takenCodes: string[];
    categories: Option[];
    chargingUnits: Option[];
    currency: App.Data.Property.CurrencyData | null;
    maxRows: number;
    highlightTaxes: boolean;
    locked: boolean;
    blockedBy: { label: string; url: string | null } | null;
}) {
    const counter = useRef(0);
    const newRow = (): Row => ({
        key: `new-${++counter.current}`,
        id: null,
        name: '',
        category: 'other',
        default_amount: '',
        charging_unit: 'per_item',
        allow_override: true,
        active: true,
        tax_ids: presetTaxIds,
        code: '',
        is_starter: false,
    });
    const http = useHttp<Payload>({
        rows: chargeItems.length > 0 ? chargeItems.map(rowOf) : [newRow()],
    });
    const guard = useUnsavedChanges(http.isDirty);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [open, setOpen] = useState<string[]>([]);
    const { rows } = http.data;
    const codes = codesOf(rows, takenCodes);
    const taxItems = taxes.map((tax) => ({
        value: String(tax.id),
        label: taxLabel(tax, currency ?? CURRENCY_FALLBACK),
    }));
    const onlyRow = rows.length === 1 && rows[0].id === null;

    setLayoutProps<OnboardingLayoutProps>({
        step: 'charge_items',
        footer: 'auto',
        formId: FORM_ID,
        processing: saving,
        width: '7xl',
    });

    const setRows = (next: Row[]) => http.setData('rows', next);

    const setRow = (index: number, values: Partial<Row>) =>
        setRows(
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );

    const toggleOpen = (key: string) =>
        setOpen((current) =>
            current.includes(key)
                ? current.filter((value) => value !== key)
                : [...current, key],
        );

    const save = (onSaved?: () => void) =>
        router.put(ChargeItemController.update.url(), http.data, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => {
                setErrors({});
                http.setDefaults();
                onSaved?.();
            },
            onError: (next) => {
                setErrors(next);
                guard.stay();
            },
        });

    return (
        <>
            <Head title="Charge items" />
            <Heading
                title="Charge items"
                description="Extras staff can charge, such as breakfast or laundry. Turn on what you sell."
            />
            <StepForm
                id={FORM_ID}
                locked={locked}
                blockedBy={blockedBy}
                error={errors.onboarding ?? errors.rows ?? null}
                onSubmit={(event) => {
                    event.preventDefault();
                    save();
                }}
            >
                <div className="overflow-hidden rounded-lg border">
                    <Table>
                        <TableHeader className="max-md:hidden">
                            <TableRow>
                                <TableHead>On</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead>Price</TableHead>
                                <TableHead>Charged</TableHead>
                                <TableHead
                                    className={
                                        highlightTaxes ? 'bg-accent' : undefined
                                    }
                                >
                                    Taxes
                                </TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row, index) => {
                                const expanded = open.includes(row.key);
                                const fieldError = (name: string) =>
                                    errors[`rows.${index}.${name}`];
                                const priceEmpty = row.default_amount === '';
                                const categoryLabel =
                                    categories.find(
                                        (option) =>
                                            option.value === row.category,
                                    )?.label ?? row.category;
                                const taxCell = (
                                    <MultiCombobox
                                        id={`taxes-${row.key}`}
                                        name="tax_ids"
                                        items={taxItems}
                                        value={row.tax_ids.map(String)}
                                        onValueChange={(value) =>
                                            setRow(index, {
                                                tax_ids: value.map(Number),
                                            })
                                        }
                                        placeholder="No taxes"
                                        emptyLabel="No taxes are on yet. Turn on a tax in Taxes first."
                                        invalid={!!fieldError('tax_ids')}
                                        maxChips={2}
                                    />
                                );

                                return (
                                    <Fragment key={row.key}>
                                        <TableRow className="max-md:grid max-md:grid-cols-2 max-md:gap-3 max-md:p-3">
                                            <TableCell className="align-top max-md:p-0">
                                                <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                    On
                                                </span>
                                                <div className="pt-2">
                                                    <Switch
                                                        aria-label={`Sell ${row.name || `item ${index + 1}`}`}
                                                        checked={row.active}
                                                        onCheckedChange={(
                                                            value,
                                                        ) =>
                                                            setRow(index, {
                                                                active: value,
                                                            })
                                                        }
                                                    />
                                                </div>
                                            </TableCell>
                                            <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                    Name
                                                </span>
                                                <Input
                                                    aria-label={`Name of item ${index + 1}`}
                                                    placeholder="e.g. Extra bed"
                                                    maxLength={100}
                                                    value={row.name}
                                                    onChange={(event) =>
                                                        setRow(index, {
                                                            name: event.target
                                                                .value,
                                                        })
                                                    }
                                                    aria-invalid={
                                                        !!fieldError('name')
                                                    }
                                                />
                                                <p className="mt-1 text-xs text-destructive">
                                                    {fieldError('name')}
                                                </p>
                                            </TableCell>
                                            <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                    Category
                                                </span>
                                                {row.is_starter ? (
                                                    <p className="pt-2 text-sm">
                                                        {categoryLabel}
                                                    </p>
                                                ) : (
                                                    <Select
                                                        items={categories}
                                                        value={row.category}
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            setRow(index, {
                                                                category:
                                                                    value as string,
                                                            })
                                                        }
                                                    >
                                                        <SelectTrigger
                                                            aria-label={`Category of item ${index + 1}`}
                                                            className="w-full"
                                                        >
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectGroup>
                                                                {categories.map(
                                                                    (
                                                                        option,
                                                                    ) => (
                                                                        <SelectItem
                                                                            key={
                                                                                option.value
                                                                            }
                                                                            value={
                                                                                option.value
                                                                            }
                                                                        >
                                                                            {
                                                                                option.label
                                                                            }
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectGroup>
                                                        </SelectContent>
                                                    </Select>
                                                )}
                                            </TableCell>
                                            <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                    Price
                                                </span>
                                                <MoneyInput
                                                    id={`price-${row.key}`}
                                                    currency={currency}
                                                    value={row.default_amount}
                                                    placeholder="e.g. 25.00"
                                                    invalid={
                                                        !!fieldError(
                                                            'default_amount',
                                                        )
                                                    }
                                                    onChange={(value) =>
                                                        setRow(index, {
                                                            default_amount:
                                                                value,
                                                        })
                                                    }
                                                />
                                                <p className="mt-1 text-xs text-destructive">
                                                    {fieldError(
                                                        'default_amount',
                                                    )}
                                                </p>
                                            </TableCell>
                                            <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                    Charged
                                                </span>
                                                <Select
                                                    items={chargingUnits}
                                                    value={row.charging_unit}
                                                    onValueChange={(value) =>
                                                        setRow(index, {
                                                            charging_unit:
                                                                value as string,
                                                        })
                                                    }
                                                >
                                                    <SelectTrigger
                                                        aria-label={`Charged of item ${index + 1}`}
                                                        className="w-full"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectGroup>
                                                            {chargingUnits.map(
                                                                (option) => (
                                                                    <SelectItem
                                                                        key={
                                                                            option.value
                                                                        }
                                                                        value={
                                                                            option.value
                                                                        }
                                                                    >
                                                                        {
                                                                            option.label
                                                                        }
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectGroup>
                                                    </SelectContent>
                                                </Select>
                                            </TableCell>
                                            <TableCell
                                                className={
                                                    highlightTaxes
                                                        ? 'bg-accent/40 align-top max-md:col-span-2 max-md:p-0'
                                                        : 'align-top max-md:col-span-2 max-md:p-0'
                                                }
                                            >
                                                <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                    Taxes
                                                </span>
                                                {taxItems.length === 0 ? (
                                                    <p className="text-sm text-muted-foreground">
                                                        No taxes are on yet.
                                                    </p>
                                                ) : (
                                                    taxCell
                                                )}
                                            </TableCell>
                                            <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`More options for ${row.name || `item ${index + 1}`}`}
                                                        aria-expanded={expanded}
                                                        onClick={() =>
                                                            toggleOpen(row.key)
                                                        }
                                                    >
                                                        {expanded ? (
                                                            <ChevronUp />
                                                        ) : (
                                                            <ChevronDown />
                                                        )}
                                                    </Button>
                                                    {row.id === null && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            aria-label={`Remove item ${index + 1}`}
                                                            disabled={onlyRow}
                                                            focusableWhenDisabled
                                                            onClick={() =>
                                                                setRows(
                                                                    rows.filter(
                                                                        (
                                                                            _,
                                                                            position,
                                                                        ) =>
                                                                            position !==
                                                                            index,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                        {expanded && (
                                            <TableRow className="bg-muted/30 hover:bg-muted/30">
                                                <TableCell
                                                    colSpan={7}
                                                    className="max-md:block"
                                                >
                                                    <div className="flex flex-col gap-3 text-sm">
                                                        <Field orientation="horizontal">
                                                            <Checkbox
                                                                id={`override-${row.key}`}
                                                                checked={
                                                                    priceEmpty ||
                                                                    row.allow_override
                                                                }
                                                                disabled={
                                                                    priceEmpty
                                                                }
                                                                onCheckedChange={(
                                                                    value,
                                                                ) =>
                                                                    setRow(
                                                                        index,
                                                                        {
                                                                            allow_override:
                                                                                value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                            <FieldLabel
                                                                htmlFor={`override-${row.key}`}
                                                            >
                                                                Staff can change
                                                                the price
                                                            </FieldLabel>
                                                        </Field>
                                                        {CHILD_UNITS.includes(
                                                            row.charging_unit,
                                                        ) && (
                                                            <p className="text-muted-foreground">
                                                                <span className="font-medium text-foreground">
                                                                    Children.{' '}
                                                                </span>
                                                                Pay the same as
                                                                adults. After
                                                                you go live, you
                                                                can set child
                                                                prices in
                                                                Settings, under
                                                                Charge items.
                                                            </p>
                                                        )}
                                                        <p className="text-muted-foreground">
                                                            <span className="font-medium text-foreground">
                                                                Code.{' '}
                                                            </span>
                                                            {row.is_starter
                                                                ? `${row.code} (fixed)`
                                                                : codes[
                                                                      index
                                                                  ] ||
                                                                  'Made from the name'}
                                                        </p>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </Fragment>
                                );
                            })}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={7} className="max-md:block">
                                    <div className="flex justify-end">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={rows.length >= maxRows}
                                            focusableWhenDisabled
                                            onClick={() =>
                                                setRows([...rows, newRow()])
                                            }
                                        >
                                            <Plus data-icon="inline-start" />
                                            Add item
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
                <p className="text-sm text-muted-foreground">
                    Empty price = staff enter the amount each time.
                </p>
            </StepForm>
            <UnsavedChangesDialog
                guard={guard}
                name="Charge items"
                saving={saving}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}

const CURRENCY_FALLBACK = {
    code: 'MYR',
    decimals: 2,
} as App.Data.Property.CurrencyData;
