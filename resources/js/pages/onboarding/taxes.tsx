import { Head, Link, router, setLayoutProps, useHttp } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Plus, Trash2 } from 'lucide-react';
import { Fragment, useEffect, useRef, useState } from 'react';
import TaxController from '@/actions/App/Http/Controllers/Onboarding/TaxController';
import Heading from '@/components/heading';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
import { StepForm } from '@/components/pms/onboarding/step-form';
import { PostingPreview } from '@/components/pms/room-revenue/posting-preview';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Popover,
    PopoverContent,
    PopoverDescription,
    PopoverTrigger,
} from '@/components/ui/popover';
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';

type Tax = App.Data.Onboarding.OnboardingTaxData;
type Preview = App.Data.Catalogues.RoomRevenuePreviewData;
type Row = {
    key: string;
    id: number | null;
    name: string;
    rate_type: string;
    rate: string;
    is_inclusive: boolean;
    foreign_guests_only: boolean;
    collect_at_check_in: boolean;
    registration_number: string;
    enabled: boolean;
    applies_to_rooms: boolean;
    is_preset: boolean;
};
type Payload = { no_tax: boolean; rows: Row[] };

const FORM_ID = 'taxes-form';

const inPriceItems = [
    { value: 'false', label: 'Added on top' },
    { value: 'true', label: 'Included' },
];

const rowOf = (tax: Tax): Row => ({
    key: `id-${tax.id}`,
    id: tax.id,
    name: tax.name,
    rate_type: tax.rateType,
    rate: tax.rate,
    is_inclusive: tax.isInclusive,
    foreign_guests_only: tax.foreignGuestsOnly,
    collect_at_check_in: tax.collectAtCheckIn,
    registration_number: tax.registrationNumber ?? '',
    enabled: tax.enabled,
    applies_to_rooms: tax.appliesToRooms,
    is_preset: tax.isPreset,
});

export default function Taxes({
    taxes,
    noTax,
    preview: savedPreview,
    currency,
    countryName,
    isMalaysia,
    maxRows,
    chargeItemsUrl,
    locked,
    blockedBy,
}: {
    taxes: Tax[];
    noTax: boolean;
    preview: Preview | null;
    currency: App.Data.Property.CurrencyData | null;
    countryName: string | null;
    isMalaysia: boolean;
    maxRows: number;
    chargeItemsUrl: string | null;
    locked: boolean;
    blockedBy: { label: string; url: string | null } | null;
}) {
    const counter = useRef(0);
    const newRow = (): Row => ({
        key: `new-${++counter.current}`,
        id: null,
        name: '',
        rate_type: 'percent',
        rate: '',
        is_inclusive: false,
        foreign_guests_only: false,
        collect_at_check_in: false,
        registration_number: '',
        enabled: true,
        applies_to_rooms: true,
        is_preset: false,
    });
    const http = useHttp<Payload, Preview>({
        no_tax: noTax,
        rows: taxes.length > 0 ? taxes.map(rowOf) : [newRow()],
    });
    const guard = useUnsavedChanges(http.isDirty);
    const [preview, setPreview] = useState(savedPreview);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [open, setOpen] = useState<string[]>([]);
    const { rows, no_tax: none } = http.data;
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency?.code ?? 'MYR',
    });
    const rateItems = [
        { value: 'percent', label: '%' },
        { value: 'flat', label: currency?.code ?? 'MYR' },
    ];
    const local = countryName ?? 'local';
    const anyOnRooms = rows.some((row) => row.enabled && row.applies_to_rooms);

    const previewKey = JSON.stringify([
        none,
        rows.map((row) => [
            row.id,
            row.name,
            row.rate_type,
            row.rate,
            row.is_inclusive,
            row.foreign_guests_only,
            row.enabled,
            row.applies_to_rooms,
        ]),
    ]);
    const previewedKey = useRef(previewKey);

    useEffect(() => {
        if (previewedKey.current === previewKey || locked) {
            return;
        }

        const timer = window.setTimeout(() => {
            previewedKey.current = previewKey;
            http.post(TaxController.preview.url(), {
                onSuccess: (response) => setPreview(response),
            }).catch(() => undefined);
        }, 250);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [previewKey]);

    setLayoutProps<OnboardingLayoutProps>({
        step: 'taxes',
        footer: 'auto',
        formId: FORM_ID,
        processing: saving,
    });

    const setRow = (index: number, values: Partial<Row>) =>
        http.setData(
            'rows',
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );

    const removeUnsaved = (index: number) => {
        http.setData(
            'rows',
            rows.filter((_, position) => position !== index),
        );
        setErrors({});
    };

    const toggleOpen = (key: string) =>
        setOpen((current) =>
            current.includes(key)
                ? current.filter((value) => value !== key)
                : [...current, key],
        );

    const save = (onSaved?: () => void) =>
        router.put(TaxController.update.url(), http.data, {
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

    const onlyRow = rows.length === 1 && rows[0].id === null;

    return (
        <>
            <Head title="Taxes" />
            <Heading
                title="Taxes"
                description={
                    isMalaysia
                        ? 'Your Malaysian taxes are ready. Turn on what you charge.'
                        : 'Add the taxes you charge guests.'
                }
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
                <div
                    className={
                        none
                            ? 'pointer-events-none overflow-hidden rounded-lg border opacity-50'
                            : 'overflow-hidden rounded-lg border'
                    }
                    aria-disabled={none}
                >
                    <Table>
                        <TableHeader className="max-md:hidden">
                            <TableRow>
                                <TableHead>On</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Rate</TableHead>
                                <TableHead>In price</TableHead>
                                <TableHead className="text-center">
                                    <Tooltip>
                                        <TooltipTrigger
                                            render={<span tabIndex={0} />}
                                        >
                                            Applies to rooms
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            Also used for no-show, cancellation,
                                            early departure and late check-out
                                            charges.
                                        </TooltipContent>
                                    </Tooltip>
                                </TableHead>
                                <TableHead>Reg. no.</TableHead>
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

                                return (
                                    <Fragment key={row.key}>
                                        <TableRow className="max-md:grid max-md:grid-cols-2 max-md:gap-3 max-md:p-3">
                                            <TableCell className="align-top max-md:p-0">
                                                <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                    On
                                                </span>
                                                <div className="pt-2">
                                                    <Switch
                                                        aria-label={`Charge ${row.name || `tax ${index + 1}`}`}
                                                        checked={row.enabled}
                                                        onCheckedChange={(
                                                            value,
                                                        ) =>
                                                            setRow(index, {
                                                                enabled: value,
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
                                                    aria-label={`Name of tax ${index + 1}`}
                                                    placeholder="e.g. Service charge"
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
                                                <FieldError>
                                                    {fieldError('name')}
                                                </FieldError>
                                            </TableCell>
                                            <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                    Rate
                                                </span>
                                                <InputGroup className="w-36">
                                                    <InputGroupInput
                                                        aria-label={`Rate of tax ${index + 1}`}
                                                        type="number"
                                                        min={0}
                                                        step="0.01"
                                                        inputMode="decimal"
                                                        placeholder="e.g. 8"
                                                        className="tabular-nums"
                                                        value={row.rate}
                                                        onChange={(event) =>
                                                            setRow(index, {
                                                                rate: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                        aria-invalid={
                                                            !!fieldError('rate')
                                                        }
                                                    />
                                                    <InputGroupAddon align="inline-end">
                                                        <Select
                                                            items={rateItems}
                                                            value={
                                                                row.rate_type
                                                            }
                                                            onValueChange={(
                                                                value,
                                                            ) =>
                                                                setRow(index, {
                                                                    rate_type:
                                                                        value as string,
                                                                })
                                                            }
                                                        >
                                                            <SelectTrigger
                                                                aria-label={`Rate type of tax ${index + 1}`}
                                                                className="h-6 w-auto border-0 bg-transparent pr-1 pl-1.5 shadow-none focus-visible:ring-0 dark:bg-transparent dark:hover:bg-transparent"
                                                            >
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectGroup>
                                                                    {rateItems.map(
                                                                        (
                                                                            item,
                                                                        ) => (
                                                                            <SelectItem
                                                                                key={
                                                                                    item.value
                                                                                }
                                                                                value={
                                                                                    item.value
                                                                                }
                                                                            >
                                                                                {
                                                                                    item.label
                                                                                }
                                                                            </SelectItem>
                                                                        ),
                                                                    )}
                                                                </SelectGroup>
                                                            </SelectContent>
                                                        </Select>
                                                    </InputGroupAddon>
                                                </InputGroup>
                                                <FieldError>
                                                    {fieldError('rate')}
                                                </FieldError>
                                            </TableCell>
                                            <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                    In price
                                                </span>
                                                <Select
                                                    items={inPriceItems}
                                                    value={String(
                                                        row.is_inclusive,
                                                    )}
                                                    onValueChange={(value) =>
                                                        setRow(index, {
                                                            is_inclusive:
                                                                value ===
                                                                'true',
                                                        })
                                                    }
                                                >
                                                    <SelectTrigger
                                                        aria-label={`Price treatment of tax ${index + 1}`}
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectGroup>
                                                            {inPriceItems.map(
                                                                (item) => (
                                                                    <SelectItem
                                                                        key={
                                                                            item.value
                                                                        }
                                                                        value={
                                                                            item.value
                                                                        }
                                                                    >
                                                                        {
                                                                            item.label
                                                                        }
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectGroup>
                                                    </SelectContent>
                                                </Select>
                                            </TableCell>
                                            <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                <Field
                                                    orientation="horizontal"
                                                    className="md:justify-center md:pt-2"
                                                >
                                                    <Checkbox
                                                        id={`applies-${row.key}`}
                                                        checked={
                                                            row.applies_to_rooms
                                                        }
                                                        onCheckedChange={(
                                                            value,
                                                        ) =>
                                                            setRow(index, {
                                                                applies_to_rooms:
                                                                    value,
                                                            })
                                                        }
                                                    />
                                                    <FieldLabel
                                                        htmlFor={`applies-${row.key}`}
                                                        className="md:sr-only"
                                                    >
                                                        Applies to rooms
                                                    </FieldLabel>
                                                </Field>
                                            </TableCell>
                                            <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                    Reg. no.
                                                </span>
                                                <Input
                                                    aria-label={`Registration number of tax ${index + 1}`}
                                                    placeholder="e.g. W10-1808-32000001"
                                                    maxLength={100}
                                                    value={
                                                        row.registration_number
                                                    }
                                                    onChange={(event) =>
                                                        setRow(index, {
                                                            registration_number:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                />
                                                <FieldError>
                                                    {fieldError(
                                                        'registration_number',
                                                    )}
                                                </FieldError>
                                            </TableCell>
                                            <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`More options for ${row.name || `tax ${index + 1}`}`}
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
                                                    {!row.is_preset &&
                                                        (row.id === null ? (
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon-sm"
                                                                aria-label={`Remove tax ${index + 1}`}
                                                                disabled={
                                                                    onlyRow
                                                                }
                                                                focusableWhenDisabled
                                                                onClick={() =>
                                                                    removeUnsaved(
                                                                        index,
                                                                    )
                                                                }
                                                            >
                                                                <Trash2 />
                                                            </Button>
                                                        ) : (
                                                            <SavedRemove
                                                                row={row}
                                                                id={row.id}
                                                            />
                                                        ))}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                        {(expanded ||
                                            !row.applies_to_rooms) && (
                                            <TableRow className="bg-muted/30 hover:bg-muted/30">
                                                <TableCell
                                                    colSpan={7}
                                                    className="max-md:block"
                                                >
                                                    <div className="flex flex-col gap-2 text-sm">
                                                        {expanded && (
                                                            <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
                                                                {row.rate_type ===
                                                                    'flat' &&
                                                                    row.applies_to_rooms && (
                                                                        <span className="text-muted-foreground">
                                                                            per
                                                                            night
                                                                        </span>
                                                                    )}
                                                                <Field
                                                                    orientation="horizontal"
                                                                    className="w-auto"
                                                                >
                                                                    <Checkbox
                                                                        id={`foreign-${row.key}`}
                                                                        checked={
                                                                            row.foreign_guests_only
                                                                        }
                                                                        onCheckedChange={(
                                                                            value,
                                                                        ) =>
                                                                            setRow(
                                                                                index,
                                                                                {
                                                                                    foreign_guests_only:
                                                                                        value,
                                                                                },
                                                                            )
                                                                        }
                                                                    />
                                                                    <FieldLabel
                                                                        htmlFor={`foreign-${row.key}`}
                                                                    >
                                                                        Foreign
                                                                        guests
                                                                        only
                                                                        {countryName !==
                                                                            null &&
                                                                            ` (not ${countryName})`}
                                                                    </FieldLabel>
                                                                </Field>
                                                                <Field
                                                                    orientation="horizontal"
                                                                    className="w-auto"
                                                                >
                                                                    <Checkbox
                                                                        id={`collect-${row.key}`}
                                                                        checked={
                                                                            row.collect_at_check_in
                                                                        }
                                                                        onCheckedChange={(
                                                                            value,
                                                                        ) =>
                                                                            setRow(
                                                                                index,
                                                                                {
                                                                                    collect_at_check_in:
                                                                                        value,
                                                                                },
                                                                            )
                                                                        }
                                                                    />
                                                                    <FieldLabel
                                                                        htmlFor={`collect-${row.key}`}
                                                                    >
                                                                        Collect
                                                                        at
                                                                        check-in
                                                                    </FieldLabel>
                                                                </Field>
                                                            </div>
                                                        )}
                                                        {!row.applies_to_rooms && (
                                                            <p className="text-muted-foreground">
                                                                Not applied to
                                                                rooms.{' '}
                                                                {chargeItemsUrl ===
                                                                null ? (
                                                                    'You can link it to extras in Charge items.'
                                                                ) : (
                                                                    <Link
                                                                        href={
                                                                            chargeItemsUrl
                                                                        }
                                                                        className={buttonVariants(
                                                                            {
                                                                                variant:
                                                                                    'link',
                                                                                size: 'sm',
                                                                                className:
                                                                                    'h-auto p-0',
                                                                            },
                                                                        )}
                                                                    >
                                                                        Link it
                                                                        to
                                                                        extras
                                                                        in
                                                                        Charge
                                                                        items →
                                                                    </Link>
                                                                )}
                                                            </p>
                                                        )}
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
                                                http.setData('rows', [
                                                    ...rows,
                                                    newRow(),
                                                ])
                                            }
                                        >
                                            <Plus data-icon="inline-start" />
                                            Add tax
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>

                {!none && !anyOnRooms && (
                    <Alert>
                        <AlertDescription>
                            Room nights have no tax. Tick Applies to rooms on a
                            tax if they should.
                        </AlertDescription>
                    </Alert>
                )}

                {!none && preview !== null && (
                    <PostingPreview
                        title="What a guest pays"
                        sample="One room night"
                        revenueLabel="Room"
                        preview={preview}
                        offTaxes={[]}
                        money={money}
                        loading={http.processing}
                        localLabel={`${local === 'local' ? 'Local' : local} guest`}
                    />
                )}

                <Field orientation="horizontal">
                    <Checkbox
                        id="no-tax"
                        checked={none}
                        onCheckedChange={(value) =>
                            http.setData('no_tax', value)
                        }
                    />
                    <FieldLabel htmlFor="no-tax">
                        We don&apos;t charge tax
                    </FieldLabel>
                </Field>

                <p className="text-sm text-muted-foreground">
                    No-show and cancellation charges are free for now. After you
                    go live, you can set them in Settings, under Room revenue.
                </p>
            </StepForm>
            <UnsavedChangesDialog
                guard={guard}
                name="Taxes"
                saving={saving}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}

function SavedRemove({ row, id }: { row: Row; id: number }) {
    return (
        <Popover>
            <PopoverTrigger
                render={
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label={`Remove ${row.name || 'tax'}`}
                    />
                }
            >
                <Trash2 />
            </PopoverTrigger>
            <PopoverContent align="end" className="w-64">
                <PopoverDescription>
                    Remove {row.name || 'this tax'}?
                </PopoverDescription>
                <Button
                    type="button"
                    variant="destructive"
                    size="sm"
                    onClick={() =>
                        router.delete(TaxController.destroy.url({ tax: id }), {
                            preserveScroll: true,
                        })
                    }
                >
                    Remove
                </Button>
            </PopoverContent>
        </Popover>
    );
}

Taxes.layout = { width: '7xl' };
