import { Head, setLayoutProps, useForm, useHttp } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronUp,
    Download,
    Plus,
    Trash2,
    Upload,
} from 'lucide-react';
import { Fragment, useRef, useState } from 'react';
import CompanyController from '@/actions/App/Http/Controllers/Onboarding/CompanyController';
import Heading from '@/components/heading';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
import { StepForm } from '@/components/pms/onboarding/step-form';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { FieldError } from '@/components/ui/field';
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
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';

type Company = App.Data.Onboarding.OnboardingCompanyData;
type TypeOption = { value: string; label: string };
type Row = {
    id: number | null;
    key: string;
    name: string;
    type: string;
    contact_email: string;
    contact_phone: string;
    credit_limit: string;
    payment_terms_days: string;
    direct_bill_enabled: boolean;
    brn: string;
    tin: string;
    sst_registration_number: string;
};
type ImportedRow = Partial<Omit<Row, 'id' | 'key'>> & {
    problems: Record<string, string>;
};

const FORM_ID = 'companies-form';
let counter = 0;

const newRow = (values: Partial<Row> = {}): Row => ({
    id: null,
    key: `new-${++counter}`,
    name: '',
    type: 'corporate',
    contact_email: '',
    contact_phone: '',
    credit_limit: '',
    payment_terms_days: '',
    direct_bill_enabled: true,
    brn: '',
    tin: '',
    sst_registration_number: '',
    ...values,
});

export default function Companies({
    companies,
    types,
    currency,
    maxRows,
    locked,
    blockedBy,
}: {
    companies: Company[];
    types: TypeOption[];
    currency: App.Data.Property.CurrencyData | null;
    maxRows: number;
    locked: boolean;
    blockedBy: { label: string; url: string | null } | null;
}) {
    const editable = companies.filter((company) => company.isEditable);
    const suspended = companies.filter((company) => !company.isEditable);
    const form = useForm<{ rows: Row[] }>({
        rows: editable.map((company) => ({
            id: company.id,
            key: `saved-${company.id}`,
            name: company.name,
            type: company.type,
            contact_email: company.contactEmail ?? '',
            contact_phone: company.contactPhone ?? '',
            credit_limit: company.creditLimit ?? '',
            payment_terms_days:
                company.paymentTermsDays === null
                    ? ''
                    : String(company.paymentTermsDays),
            direct_bill_enabled: company.directBillEnabled,
            brn: company.brn ?? '',
            tin: company.tin ?? '',
            sst_registration_number: company.sstRegistrationNumber ?? '',
        })),
    });
    const upload = useHttp<
        { file: File | null },
        { mode: string; rows: ImportedRow[] }
    >({ file: null });
    const fileInput = useRef<HTMLInputElement>(null);
    const guard = useUnsavedChanges(form.isDirty);
    const [open, setOpen] = useState<string[]>([]);
    const [problems, setProblems] = useState<
        Record<string, Record<string, string>>
    >({});
    const rows = form.data.rows;
    const errors = form.errors as Record<string, string | undefined>;

    setLayoutProps<OnboardingLayoutProps>({
        step: 'companies',
        footer: 'auto',
        formId: FORM_ID,
        processing: form.processing,
        width: '7xl',
    });

    const setRow = (index: number, values: Partial<Row>) =>
        form.setData(
            'rows',
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );

    const removeUnsaved = (index: number) => {
        form.setData(
            'rows',
            rows.filter((_, position) => position !== index),
        );
        form.clearErrors();
    };

    const toggleOpen = (key: string) =>
        setOpen((current) =>
            current.includes(key)
                ? current.filter((item) => item !== key)
                : [...current, key],
        );

    const readFile = (file: File) => {
        upload.transform(() => ({ file }));
        upload
            .post(CompanyController.import.url())
            .then(({ rows: imported }) => {
                const fromFile = imported.map(
                    ({ problems: rowProblems, ...fields }) => ({
                        row: newRow(fields),
                        rowProblems,
                    }),
                );

                setProblems((current) => ({
                    ...current,
                    ...Object.fromEntries(
                        fromFile
                            .filter(
                                ({ rowProblems }) =>
                                    Object.keys(rowProblems).length > 0,
                            )
                            .map(({ row, rowProblems }) => [
                                row.key,
                                rowProblems,
                            ]),
                    ),
                }));
                form.setData('rows', [
                    ...rows,
                    ...fromFile.map(({ row }) => row),
                ]);
            })
            .catch(() => undefined);
    };

    const save = (onSuccess?: () => void) => {
        form.transform((data) => ({
            rows: data.rows.map(({ key: _key, ...row }) => row),
        }));
        form.put(CompanyController.update.url(), {
            preserveScroll: true,
            onSuccess,
        });
    };

    const actions = (
        <div className="flex flex-wrap justify-end gap-2">
            <DropdownMenu>
                <DropdownMenuTrigger
                    render={<Button type="button" variant="ghost" />}
                >
                    <Download data-icon="inline-start" />
                    Template
                    <ChevronDown data-icon="inline-end" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        render={
                            <a
                                href={CompanyController.template.url({
                                    format: 'csv',
                                })}
                                download
                            />
                        }
                    >
                        CSV
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        render={
                            <a
                                href={CompanyController.template.url({
                                    format: 'xlsx',
                                })}
                                download
                            />
                        }
                    >
                        Excel (XLSX)
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <Button
                type="button"
                variant="outline"
                disabled={upload.processing || rows.length >= maxRows}
                focusableWhenDisabled
                onClick={() => fileInput.current?.click()}
            >
                <Upload data-icon="inline-start" />
                Import from file
            </Button>
            <Button
                type="button"
                variant="outline"
                disabled={rows.length >= maxRows}
                focusableWhenDisabled
                onClick={() => form.setData('rows', [...rows, newRow()])}
            >
                <Plus data-icon="inline-start" />
                Add company
            </Button>
        </div>
    );

    return (
        <>
            <Head title="Companies" />
            <Heading
                title="Companies"
                description="Do companies or travel agents book with you and pay later? Skip this if not."
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
                <input
                    ref={fileInput}
                    type="file"
                    accept=".csv,.xlsx"
                    className="sr-only"
                    tabIndex={-1}
                    aria-label="Companies file"
                    onChange={(event) => {
                        const file = event.target.files?.[0];

                        event.target.value = '';

                        if (file) {
                            readFile(file);
                        }
                    }}
                />
                {upload.errors.file && (
                    <FieldError>{upload.errors.file}</FieldError>
                )}

                {
                    <div className="overflow-hidden rounded-lg border">
                        <Table>
                            <TableHeader className="max-md:hidden">
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Contact email</TableHead>
                                    <TableHead>Direct bill</TableHead>
                                    <TableHead>Credit limit</TableHead>
                                    <TableHead>Terms days</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 && (
                                    <TableRow className="hover:bg-transparent">
                                        <TableCell
                                            colSpan={7}
                                            className="py-8 text-center text-sm whitespace-normal text-muted-foreground max-md:block"
                                        >
                                            No companies yet. Add one, or import
                                            a file.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {rows.map((row, index) => {
                                    const expanded = open.includes(row.key);
                                    const fieldError = (name: string) =>
                                        errors[`rows.${index}.${name}`] ??
                                        problems[row.key]?.[name];
                                    const label =
                                        row.name || `company ${index + 1}`;

                                    return (
                                        <Fragment key={row.key}>
                                            <TableRow className="max-md:grid max-md:grid-cols-2 max-md:gap-3 max-md:p-3">
                                                <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                    <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                        Name
                                                    </span>
                                                    <Input
                                                        aria-label={`Name of company ${index + 1}`}
                                                        placeholder="e.g. Petronas Carigali"
                                                        maxLength={150}
                                                        value={row.name}
                                                        onChange={(event) =>
                                                            setRow(index, {
                                                                name: event
                                                                    .target
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
                                                        Type
                                                    </span>
                                                    <Select
                                                        items={types}
                                                        value={row.type}
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            setRow(index, {
                                                                type: value as string,
                                                            })
                                                        }
                                                    >
                                                        <SelectTrigger
                                                            aria-label={`Type of company ${index + 1}`}
                                                            className="w-full"
                                                            aria-invalid={
                                                                !!fieldError(
                                                                    'type',
                                                                )
                                                            }
                                                        >
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectGroup>
                                                                {types.map(
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
                                                    <FieldError>
                                                        {fieldError('type')}
                                                    </FieldError>
                                                </TableCell>
                                                <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                    <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                        Contact email
                                                    </span>
                                                    <Input
                                                        aria-label={`Contact email of company ${index + 1}`}
                                                        type="email"
                                                        placeholder="e.g. travel@petronas.my"
                                                        maxLength={255}
                                                        value={
                                                            row.contact_email
                                                        }
                                                        onChange={(event) =>
                                                            setRow(index, {
                                                                contact_email:
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                        aria-invalid={
                                                            !!fieldError(
                                                                'contact_email',
                                                            )
                                                        }
                                                    />
                                                    <FieldError>
                                                        {fieldError(
                                                            'contact_email',
                                                        )}
                                                    </FieldError>
                                                </TableCell>
                                                <TableCell className="align-top max-md:p-0">
                                                    <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                        Direct bill
                                                    </span>
                                                    <div className="pt-2.5">
                                                        <Checkbox
                                                            aria-label={`Direct bill ${label}`}
                                                            checked={
                                                                row.direct_bill_enabled
                                                            }
                                                            onCheckedChange={(
                                                                value,
                                                            ) =>
                                                                setRow(index, {
                                                                    direct_bill_enabled:
                                                                        value,
                                                                })
                                                            }
                                                        />
                                                    </div>
                                                </TableCell>
                                                <TableCell className="align-top max-md:p-0">
                                                    <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                        Credit limit
                                                    </span>
                                                    <InputGroup className="w-40">
                                                        {currency !== null && (
                                                            <InputGroupAddon>
                                                                {currency.code}
                                                            </InputGroupAddon>
                                                        )}
                                                        <InputGroupInput
                                                            aria-label={`Credit limit of company ${index + 1}`}
                                                            type="number"
                                                            min={0}
                                                            step="0.01"
                                                            inputMode="decimal"
                                                            placeholder="e.g. 20000"
                                                            className="tabular-nums"
                                                            value={
                                                                row.credit_limit
                                                            }
                                                            onChange={(event) =>
                                                                setRow(index, {
                                                                    credit_limit:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                })
                                                            }
                                                            aria-invalid={
                                                                !!fieldError(
                                                                    'credit_limit',
                                                                )
                                                            }
                                                        />
                                                    </InputGroup>
                                                    <FieldError>
                                                        {fieldError(
                                                            'credit_limit',
                                                        )}
                                                    </FieldError>
                                                </TableCell>
                                                <TableCell className="align-top max-md:p-0">
                                                    <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                        Terms days
                                                    </span>
                                                    <Input
                                                        aria-label={`Payment terms of company ${index + 1}`}
                                                        type="number"
                                                        min={0}
                                                        max={365}
                                                        step={1}
                                                        inputMode="numeric"
                                                        placeholder="e.g. 30"
                                                        className="w-24"
                                                        value={
                                                            row.payment_terms_days
                                                        }
                                                        onChange={(event) =>
                                                            setRow(index, {
                                                                payment_terms_days:
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                        aria-invalid={
                                                            !!fieldError(
                                                                'payment_terms_days',
                                                            )
                                                        }
                                                    />
                                                    <FieldError>
                                                        {fieldError(
                                                            'payment_terms_days',
                                                        )}
                                                    </FieldError>
                                                </TableCell>
                                                <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                    <div className="flex justify-end gap-1">
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            aria-label={`More options for ${label}`}
                                                            aria-expanded={
                                                                expanded
                                                            }
                                                            onClick={() =>
                                                                toggleOpen(
                                                                    row.key,
                                                                )
                                                            }
                                                        >
                                                            {expanded ? (
                                                                <ChevronUp />
                                                            ) : (
                                                                <ChevronDown />
                                                            )}
                                                        </Button>
                                                        {row.id === null ? (
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon-sm"
                                                                aria-label={`Remove ${label}`}
                                                                onClick={() =>
                                                                    removeUnsaved(
                                                                        index,
                                                                    )
                                                                }
                                                            >
                                                                <Trash2 />
                                                            </Button>
                                                        ) : (
                                                            <span className="self-center text-xs text-muted-foreground">
                                                                Suspend in
                                                                settings
                                                            </span>
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
                                                        <div className="grid gap-3 md:grid-cols-4">
                                                            {(
                                                                [
                                                                    [
                                                                        'brn',
                                                                        'BRN',
                                                                        'e.g. 201901234567',
                                                                    ],
                                                                    [
                                                                        'tin',
                                                                        'TIN',
                                                                        'e.g. C2584563200',
                                                                    ],
                                                                    [
                                                                        'sst_registration_number',
                                                                        'SST no.',
                                                                        'e.g. W10-1808-32000001',
                                                                    ],
                                                                    [
                                                                        'contact_phone',
                                                                        'Phone',
                                                                        'e.g. 012-345 6789',
                                                                    ],
                                                                ] as const
                                                            ).map(
                                                                ([
                                                                    field,
                                                                    title,
                                                                    example,
                                                                ]) => (
                                                                    <div
                                                                        key={
                                                                            field
                                                                        }
                                                                        className="flex flex-col gap-1"
                                                                    >
                                                                        <span className="text-xs text-muted-foreground">
                                                                            {
                                                                                title
                                                                            }
                                                                        </span>
                                                                        <Input
                                                                            aria-label={`${title} of company ${index + 1}`}
                                                                            placeholder={
                                                                                example
                                                                            }
                                                                            maxLength={
                                                                                30
                                                                            }
                                                                            value={
                                                                                row[
                                                                                    field
                                                                                ]
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                setRow(
                                                                                    index,
                                                                                    {
                                                                                        [field]:
                                                                                            event
                                                                                                .target
                                                                                                .value,
                                                                                    },
                                                                                )
                                                                            }
                                                                            aria-invalid={
                                                                                !!fieldError(
                                                                                    field,
                                                                                )
                                                                            }
                                                                        />
                                                                        <FieldError>
                                                                            {fieldError(
                                                                                field,
                                                                            )}
                                                                        </FieldError>
                                                                    </div>
                                                                ),
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
                                    <TableCell colSpan={7}>{actions}</TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                }

                {suspended.length > 0 && (
                    <section
                        className="flex flex-col gap-2"
                        aria-labelledby="suspended-companies"
                    >
                        <h3
                            id="suspended-companies"
                            className="text-base font-medium"
                        >
                            Suspended
                        </h3>
                        <ul className="divide-y rounded-lg border text-sm">
                            {suspended.map((company) => (
                                <li
                                    key={company.id}
                                    className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                                >
                                    <span>{company.name}</span>
                                    <span className="text-muted-foreground">
                                        Suspended. Reactivate in settings.
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <div className="flex flex-col gap-1 text-sm text-muted-foreground">
                    <p>
                        Leave the credit limit empty for no limit. Tick Direct
                        bill to send the company an invoice instead of taking
                        payment at check-out. It turns on City ledger in Payment
                        methods.
                    </p>
                    <p>
                        After you go live, you can give companies their own
                        rates in Settings, under Rate plans.
                    </p>
                </div>
            </StepForm>
            <UnsavedChangesDialog
                guard={guard}
                name="Companies"
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}
