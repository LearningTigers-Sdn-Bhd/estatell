import { Head, router, setLayoutProps, useHttp } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Plus, Trash2 } from 'lucide-react';
import { Fragment, useRef, useState } from 'react';
import DiscountController from '@/actions/App/Http/Controllers/Onboarding/DiscountController';
import Heading from '@/components/heading';
import { MoneyInput } from '@/components/pms/money-input';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
import { StepForm } from '@/components/pms/onboarding/step-form';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldLabel } from '@/components/ui/field';
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

type Discount = App.Data.Onboarding.OnboardingDiscountData;
type Charge = App.Data.Catalogues.DiscountChargeData;
type Option = { value: string; label: string };
type Row = {
    key: string;
    id: number | null;
    name: string;
    type: string;
    value: string;
    allow_override: boolean;
    scope: string;
    active: boolean;
    room_charges: boolean;
    transaction_code_ids: number[];
    code: string;
    is_starter: boolean;
};
type Payload = { rows: Row[] };

const FORM_ID = 'discounts-form';
const CODE_LENGTH = 20;
const ROOM_CHARGES = 'room';

/**
 * The code a new row shows: the name in capitals with underscores (Walk-in → WALK_IN), and a number when the code is
 * taken. The server derives the saved code the same way.
 */
function codeFor(name: string, taken: Set<string>): string {
    const base =
        name
            .toUpperCase()
            .replace(/[^A-Z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '') || 'DISCOUNT';

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

const rowOf = (discount: Discount): Row => ({
    key: `id-${discount.id}`,
    id: discount.id,
    name: discount.name,
    type: discount.type,
    value: discount.value ?? '',
    allow_override: discount.allowOverride,
    scope: discount.scope,
    active: discount.active,
    room_charges: discount.roomCharges,
    transaction_code_ids: discount.transactionCodeIds,
    code: discount.code,
    is_starter: discount.isStarter,
});

export default function Discounts({
    discounts,
    charges,
    takenCodes,
    types,
    scopes,
    currency,
    maxRows,
    locked,
    blockedBy,
}: {
    discounts: Discount[];
    charges: Charge[];
    takenCodes: string[];
    types: Option[];
    scopes: Option[];
    currency: App.Data.Property.CurrencyData | null;
    maxRows: number;
    locked: boolean;
    blockedBy: { label: string; url: string | null } | null;
}) {
    const counter = useRef(0);
    const newRow = (): Row => ({
        key: `new-${++counter.current}`,
        id: null,
        name: '',
        type: 'percent',
        value: '',
        allow_override: false,
        scope: 'all_charges',
        active: true,
        room_charges: false,
        transaction_code_ids: [],
        code: '',
        is_starter: false,
    });
    const http = useHttp<Payload>({
        rows: discounts.length > 0 ? discounts.map(rowOf) : [newRow()],
    });
    const guard = useUnsavedChanges(http.isDirty);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [open, setOpen] = useState<string[]>([]);
    const { rows } = http.data;
    const codes = codesOf(rows, takenCodes);
    const pickerItems = [
        { value: ROOM_CHARGES, label: 'Room charges' },
        ...charges.map((charge) => ({
            value: String(charge.id),
            label: charge.active ? charge.name : `${charge.name} (off)`,
        })),
    ];
    const onlyRow = rows.length === 1 && rows[0].id === null;

    setLayoutProps<OnboardingLayoutProps>({
        step: 'discounts',
        footer: 'auto',
        formId: FORM_ID,
        processing: saving,
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
        router.put(DiscountController.update.url(), http.data, {
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
            <Head title="Discounts" />
            <Heading
                title="Discounts"
                description="Discounts staff can apply on a booking or a folio, such as a walk-in or returning guest rate."
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
                                <TableHead>Type</TableHead>
                                <TableHead>Value</TableHead>
                                <TableHead>Applies to</TableHead>
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
                                const pickerValue = [
                                    ...(row.room_charges ? [ROOM_CHARGES] : []),
                                    ...row.transaction_code_ids.map(String),
                                ];

                                return (
                                    <Fragment key={row.key}>
                                        <TableRow className="max-md:grid max-md:grid-cols-2 max-md:gap-3 max-md:p-3">
                                            <TableCell className="align-top max-md:p-0">
                                                <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                    On
                                                </span>
                                                <div className="pt-2">
                                                    <Switch
                                                        aria-label={`Use ${row.name || `discount ${index + 1}`}`}
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
                                                    aria-label={`Name of discount ${index + 1}`}
                                                    placeholder="e.g. Walk-in"
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
                                                    Type
                                                </span>
                                                <Select
                                                    items={types}
                                                    value={row.type}
                                                    onValueChange={(value) => {
                                                        const type =
                                                            value as string;

                                                        setRow(index, {
                                                            type,
                                                            value: '',
                                                            allow_override:
                                                                type === 'open',
                                                        });
                                                    }}
                                                >
                                                    <SelectTrigger
                                                        aria-label={`Type of discount ${index + 1}`}
                                                        className="w-full"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectGroup>
                                                            {types.map(
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
                                            <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                    Value
                                                </span>
                                                {row.type === 'open' && (
                                                    <p className="pt-2 text-sm text-muted-foreground">
                                                        Staff enter it
                                                    </p>
                                                )}
                                                {row.type === 'percent' && (
                                                    <InputGroup>
                                                        <InputGroupInput
                                                            type="number"
                                                            inputMode="decimal"
                                                            min="1"
                                                            max="100"
                                                            step="0.01"
                                                            aria-label={`Percentage of discount ${index + 1}`}
                                                            placeholder="e.g. 10"
                                                            value={row.value}
                                                            aria-invalid={
                                                                !!fieldError(
                                                                    'value',
                                                                )
                                                            }
                                                            onChange={(event) =>
                                                                setRow(index, {
                                                                    value: event
                                                                        .target
                                                                        .value,
                                                                })
                                                            }
                                                        />
                                                        <InputGroupAddon align="inline-end">
                                                            %
                                                        </InputGroupAddon>
                                                    </InputGroup>
                                                )}
                                                {row.type === 'fixed' && (
                                                    <MoneyInput
                                                        id={`value-${row.key}`}
                                                        currency={currency}
                                                        value={row.value}
                                                        placeholder="e.g. 20.00"
                                                        invalid={
                                                            !!fieldError(
                                                                'value',
                                                            )
                                                        }
                                                        onChange={(value) =>
                                                            setRow(index, {
                                                                value,
                                                            })
                                                        }
                                                    />
                                                )}
                                                <p className="mt-1 text-xs text-destructive">
                                                    {fieldError('value')}
                                                </p>
                                            </TableCell>
                                            <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                    Applies to
                                                </span>
                                                <div className="flex flex-col gap-2">
                                                    <Select
                                                        items={scopes}
                                                        value={row.scope}
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            setRow(index, {
                                                                scope: value as string,
                                                            })
                                                        }
                                                    >
                                                        <SelectTrigger
                                                            aria-label={`Applies to of discount ${index + 1}`}
                                                            className="w-full"
                                                        >
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectGroup>
                                                                {scopes.map(
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
                                                    {row.scope ===
                                                        'selected' && (
                                                        <MultiCombobox
                                                            id={`charges-${row.key}`}
                                                            name="transaction_code_ids"
                                                            items={pickerItems}
                                                            value={pickerValue}
                                                            onValueChange={(
                                                                value,
                                                            ) =>
                                                                setRow(index, {
                                                                    room_charges:
                                                                        value.includes(
                                                                            ROOM_CHARGES,
                                                                        ),
                                                                    transaction_code_ids:
                                                                        value
                                                                            .filter(
                                                                                (
                                                                                    item,
                                                                                ) =>
                                                                                    item !==
                                                                                    ROOM_CHARGES,
                                                                            )
                                                                            .map(
                                                                                Number,
                                                                            ),
                                                                })
                                                            }
                                                            placeholder="Choose charges"
                                                            emptyLabel="No charges match."
                                                            invalid={
                                                                !!fieldError(
                                                                    'transaction_code_ids',
                                                                )
                                                            }
                                                            maxChips={2}
                                                        />
                                                    )}
                                                </div>
                                                <p className="mt-1 text-xs text-destructive">
                                                    {fieldError(
                                                        'transaction_code_ids',
                                                    )}
                                                </p>
                                            </TableCell>
                                            <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`More options for ${row.name || `discount ${index + 1}`}`}
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
                                                            aria-label={`Remove discount ${index + 1}`}
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
                                                    colSpan={6}
                                                    className="max-md:block"
                                                >
                                                    <div className="flex flex-col gap-3 text-sm">
                                                        <Field orientation="horizontal">
                                                            <Checkbox
                                                                id={`override-${row.key}`}
                                                                checked={
                                                                    row.type ===
                                                                        'open' ||
                                                                    (row.type ===
                                                                        'fixed' &&
                                                                        row.allow_override)
                                                                }
                                                                disabled={
                                                                    row.type !==
                                                                    'fixed'
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
                                                                the amount
                                                            </FieldLabel>
                                                        </Field>
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
                                <TableCell colSpan={6} className="max-md:block">
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
                                            Add discount
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
                <p className="text-sm text-muted-foreground">
                    After you go live, you can set long-stay and rate discounts
                    in Rates.
                </p>
            </StepForm>
            <UnsavedChangesDialog
                guard={guard}
                name="Discounts"
                saving={saving}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}

Discounts.layout = { width: '7xl' };
