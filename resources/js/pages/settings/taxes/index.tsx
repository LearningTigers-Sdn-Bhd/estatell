import { Head, router, useForm } from '@inertiajs/react';
import { Landmark, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
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
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
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
import { index, store, update } from '@/routes/settings/taxes';

type Tax = App.Data.Catalogues.TaxData;
type TransactionCode = App.Data.Catalogues.TaxTransactionCodeData;
type Option = { value: string; label: string };

type FormData = {
    name: string;
    code: string;
    rate_type: string;
    rate: string;
    is_inclusive: boolean;
    foreign_guests_only: boolean;
    registration_number: string;
    enabled: boolean;
    applicable_transaction_code_ids: string[];
};

function formValues(tax: Tax | null): FormData {
    return {
        name: tax?.name ?? '',
        code: tax?.code ?? '',
        rate_type: tax?.rateType ?? 'percent',
        rate: tax?.rate ?? '',
        is_inclusive: tax?.isInclusive ?? false,
        foreign_guests_only: tax?.foreignGuestsOnly ?? false,
        registration_number: tax?.registrationNumber ?? '',
        enabled: tax?.enabled ?? true,
        applicable_transaction_code_ids:
            tax?.applicableTransactionCodeIds.map(String) ?? [],
    };
}

function TaxForm({
    tax,
    transactionCodes,
    rateTypes,
    currency,
    onDone,
}: {
    tax: Tax | null;
    transactionCodes: TransactionCode[];
    rateTypes: Option[];
    currency: App.Enums.Currency;
    onDone: () => void;
}) {
    const form = useForm<FormData>(formValues(tax));
    const codeItems: MultiComboboxOption[] = transactionCodes.map((code) => ({
        value: String(code.id),
        label: code.active ? code.label : `${code.label} (inactive)`,
        disabled:
            !code.active &&
            !tax?.applicableTransactionCodeIds.includes(code.id),
    }));

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };

        if (tax) {
            form.put(update.url({ tax: tax.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <FieldGroup className="flex-1 overflow-y-auto px-4">
                <Field data-invalid={!!form.errors.name}>
                    <FieldLabel htmlFor="tax-name">Name</FieldLabel>
                    <Input
                        id="tax-name"
                        maxLength={100}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        aria-invalid={!!form.errors.name}
                    />
                    <FieldError>{form.errors.name}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.code}>
                    <FieldLabel htmlFor="tax-code">Short code</FieldLabel>
                    <Input
                        id="tax-code"
                        maxLength={50}
                        value={form.data.code}
                        onChange={(event) =>
                            form.setData('code', event.target.value)
                        }
                        aria-invalid={!!form.errors.code}
                    />
                    <FieldDescription>
                        Custom posting codes use the TAX_ prefix.
                    </FieldDescription>
                    <FieldError>{form.errors.code}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.rate_type}>
                    <FieldLabel htmlFor="tax-rate-type">Rate type</FieldLabel>
                    <EnumSelect
                        id="tax-rate-type"
                        name="rate_type"
                        options={rateTypes}
                        value={form.data.rate_type}
                        onValueChange={(value) =>
                            form.setData('rate_type', value)
                        }
                        aria-invalid={!!form.errors.rate_type}
                    />
                    <FieldError>{form.errors.rate_type}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.rate}>
                    <FieldLabel htmlFor="tax-rate">Rate</FieldLabel>
                    <InputGroup>
                        {form.data.rate_type === 'flat' && (
                            <InputGroupAddon>{currency}</InputGroupAddon>
                        )}
                        <InputGroupInput
                            id="tax-rate"
                            type="number"
                            min="0.01"
                            max={
                                form.data.rate_type === 'percent'
                                    ? '100'
                                    : '9999999999.99'
                            }
                            step="0.01"
                            inputMode="decimal"
                            value={form.data.rate}
                            onChange={(event) =>
                                form.setData('rate', event.target.value)
                            }
                            aria-invalid={!!form.errors.rate}
                        />
                        {form.data.rate_type === 'percent' && (
                            <InputGroupAddon align="inline-end">
                                %
                            </InputGroupAddon>
                        )}
                    </InputGroup>
                    <FieldError>{form.errors.rate}</FieldError>
                </Field>
                <Field
                    data-invalid={!!form.errors.applicable_transaction_code_ids}
                >
                    <FieldLabel htmlFor="tax-applies-to">
                        Applies to charges
                    </FieldLabel>
                    <MultiCombobox
                        id="tax-applies-to"
                        name="applicable_transaction_code_ids"
                        items={codeItems}
                        value={form.data.applicable_transaction_code_ids}
                        onValueChange={(value) =>
                            form.setData(
                                'applicable_transaction_code_ids',
                                value,
                            )
                        }
                        placeholder="Select charge codes"
                        invalid={!!form.errors.applicable_transaction_code_ids}
                    />
                    <FieldError>
                        {form.errors.applicable_transaction_code_ids}
                    </FieldError>
                </Field>
                <Field data-invalid={!!form.errors.registration_number}>
                    <FieldLabel htmlFor="tax-registration-number">
                        Registration number
                    </FieldLabel>
                    <Input
                        id="tax-registration-number"
                        maxLength={100}
                        value={form.data.registration_number}
                        onChange={(event) =>
                            form.setData(
                                'registration_number',
                                event.target.value,
                            )
                        }
                        aria-invalid={!!form.errors.registration_number}
                    />
                    <FieldDescription>Optional.</FieldDescription>
                    <FieldError>{form.errors.registration_number}</FieldError>
                </Field>
                <Field orientation="horizontal">
                    <Switch
                        id="tax-inclusive"
                        checked={form.data.is_inclusive}
                        onCheckedChange={(checked) =>
                            form.setData('is_inclusive', checked)
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="tax-inclusive">
                            Included in charge amount
                        </FieldLabel>
                        <FieldDescription>
                            Extract this tax from the entered amount.
                        </FieldDescription>
                    </FieldContent>
                </Field>
                <Field orientation="horizontal">
                    <Switch
                        id="tax-foreign-only"
                        checked={form.data.foreign_guests_only}
                        onCheckedChange={(checked) =>
                            form.setData('foreign_guests_only', checked)
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="tax-foreign-only">
                            Foreign guests only
                        </FieldLabel>
                        <FieldDescription>
                            Nationality is required before this tax can be
                            calculated.
                        </FieldDescription>
                    </FieldContent>
                </Field>
                <Field orientation="horizontal">
                    <Switch
                        id="tax-enabled"
                        checked={form.data.enabled}
                        onCheckedChange={(checked) =>
                            form.setData('enabled', checked)
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="tax-enabled">Available</FieldLabel>
                        <FieldDescription>
                            Available taxes apply automatically to matching
                            charges.
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
                    {tax ? 'Save changes' : 'Create tax'}
                </Button>
            </SheetFooter>
        </form>
    );
}

function rateLabel(
    tax: Tax,
    currency: App.Enums.Currency,
    rateTypes: Option[],
): string {
    const value =
        tax.rateType === 'percent'
            ? `${Number(tax.rate).toFixed(2)}%`
            : new Intl.NumberFormat(undefined, {
                  style: 'currency',
                  currency,
              }).format(Number(tax.rate));
    const type =
        rateTypes.find((option) => option.value === tax.rateType)?.label ??
        tax.rateType;

    return `${value} · ${type} · ${tax.isInclusive ? 'Inclusive' : 'Exclusive'}`;
}

export default function TaxesIndex({
    taxes,
    transactionCodes,
    rateTypes,
    currency,
}: {
    taxes: Tax[];
    transactionCodes: TransactionCode[];
    rateTypes: Option[];
    currency: App.Enums.Currency;
}) {
    const [editing, setEditing] = useState<Tax | null | undefined>(undefined);
    const [togglingIds, setTogglingIds] = useState<Set<number>>(new Set());
    const [query, setQuery] = useState('');
    const codeLabels = new Map(
        transactionCodes.map((code) => [code.id, code.label]),
    );
    const filtered = useMemo(() => {
        const normalized = query.trim().toLocaleLowerCase();
        const matching = normalized
            ? taxes.filter((tax) =>
                  [tax.name, tax.code, tax.registrationNumber ?? ''].some(
                      (value) => value.toLocaleLowerCase().includes(normalized),
                  ),
              )
            : [...taxes];

        return matching.sort(
            (first, second) => Number(second.enabled) - Number(first.enabled),
        );
    }, [query, taxes]);

    const toggleEnabled = (tax: Tax, enabled: boolean) => {
        const setToggling = (toggling: boolean) => {
            setTogglingIds((current) => {
                const next = new Set(current);
                if (toggling) {
                    next.add(tax.id);
                } else {
                    next.delete(tax.id);
                }

                return next;
            });
        };

        router
            .optimistic<{ taxes: Tax[] }>((props) => ({
                taxes: props.taxes.map((item) =>
                    item.id === tax.id ? { ...item, enabled } : item,
                ),
            }))
            .put(
                update.url({ tax: tax.id }),
                { ...formValues(tax), enabled },
                {
                    preserveScroll: true,
                    onStart: () => setToggling(true),
                    onFinish: () => setToggling(false),
                },
            );
    };

    return (
        <>
            <Head title="Taxes" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Taxes"
                    description="Taxes applied automatically to matching charges."
                    actions={
                        <Button onClick={() => setEditing(null)}>
                            <Plus data-icon="inline-start" />
                            New tax
                        </Button>
                    }
                />

                {taxes.length === 0 ? (
                    <EmptyState
                        icon={Landmark}
                        title="No taxes yet"
                        description="Add the first tax that can apply to property charges."
                        action={
                            <Button onClick={() => setEditing(null)}>
                                <Plus data-icon="inline-start" />
                                New tax
                            </Button>
                        }
                    />
                ) : (
                    <>
                        <Field>
                            <FieldLabel htmlFor="tax-search">
                                Search taxes
                            </FieldLabel>
                            <Input
                                id="tax-search"
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Search by name, code, or registration number"
                            />
                        </Field>
                        {filtered.length > 0 ? (
                            <div className="overflow-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Available</TableHead>
                                            <TableHead>Tax</TableHead>
                                            <TableHead>Applies to</TableHead>
                                            <TableHead>Rate</TableHead>
                                            <TableHead>
                                                Registration number
                                            </TableHead>
                                            <TableHead>
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {filtered.map((tax) => (
                                            <TableRow key={tax.id}>
                                                <TableCell>
                                                    <Switch
                                                        size="sm"
                                                        checked={tax.enabled}
                                                        disabled={togglingIds.has(
                                                            tax.id,
                                                        )}
                                                        aria-label={`Make ${tax.name} ${tax.enabled ? 'unavailable' : 'available'}`}
                                                        onCheckedChange={(
                                                            enabled,
                                                        ) =>
                                                            toggleEnabled(
                                                                tax,
                                                                enabled,
                                                            )
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex min-w-0 flex-col gap-0.5">
                                                        <span className="font-medium">
                                                            {tax.name}
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {tax.code} ·{' '}
                                                            {
                                                                tax.postingTransactionCode
                                                            }
                                                        </span>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex max-w-xs flex-wrap gap-1">
                                                        {tax.applicableTransactionCodeIds.map(
                                                            (id) => (
                                                                <Badge
                                                                    key={id}
                                                                    variant="secondary"
                                                                >
                                                                    {codeLabels.get(
                                                                        id,
                                                                    ) ?? id}
                                                                </Badge>
                                                            ),
                                                        )}
                                                        {tax.foreignGuestsOnly && (
                                                            <Badge variant="outline">
                                                                Foreign guests
                                                            </Badge>
                                                        )}
                                                        {tax
                                                            .applicableTransactionCodeIds
                                                            .length === 0 && (
                                                            <span className="text-muted-foreground">
                                                                No charge codes
                                                            </span>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap tabular-nums">
                                                    {rateLabel(
                                                        tax,
                                                        currency,
                                                        rateTypes,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {tax.registrationNumber ??
                                                        '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex justify-end gap-1">
                                                        {!tax.enabled && (
                                                            <Badge variant="secondary">
                                                                Inactive
                                                            </Badge>
                                                        )}
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                setEditing(tax)
                                                            }
                                                        >
                                                            Edit
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        ) : (
                            <EmptyState
                                title="No matching taxes"
                                description="Change the search or add a new tax."
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
                            {editing ? `Edit ${editing.name}` : 'New tax'}
                        </SheetTitle>
                        <SheetDescription>
                            Configure how this tax applies and is calculated.
                        </SheetDescription>
                    </SheetHeader>
                    {editing !== undefined && (
                        <TaxForm
                            key={editing?.id ?? 'new'}
                            tax={editing}
                            transactionCodes={transactionCodes}
                            rateTypes={rateTypes}
                            currency={currency}
                            onDone={() => setEditing(undefined)}
                        />
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}

TaxesIndex.layout = {
    breadcrumbs: [{ title: 'Taxes', href: index() }],
};
