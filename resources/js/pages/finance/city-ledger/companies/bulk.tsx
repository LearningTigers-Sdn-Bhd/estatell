import { Head, Link, useForm, useHttp } from '@inertiajs/react';
import {
    Building2,
    ChevronDown,
    Copy,
    Text,
    Download,
    Plus,
    Rows3,
    Upload,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { CountrySelect } from '@/components/pms/country-select';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormActions } from '@/components/pms/form-actions';
import { PageHeader } from '@/components/pms/page-header';
import { ExportTemporaryPasswordsMenu } from '@/components/pms/temporary-passwords/export-temporary-passwords-menu';
import { TemporaryPasswordMessageDialog } from '@/components/pms/temporary-passwords/temporary-password-message-dialog';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import {
    importMethod as importRoute,
    importTemplate,
    index,
} from '@/routes/finance/city-ledger/companies';
import {
    create as bulkCreate,
    store as bulkStore,
} from '@/routes/finance/city-ledger/companies/bulk';
import {
    exportMethod as exportLogins,
    message as messageLogins,
} from '@/routes/finance/city-ledger/companies/portal-logins';

type Mode = 'simple' | 'detailed';

const MODE_ICONS: Record<Mode, LucideIcon> = {
    simple: Rows3,
    detailed: Text,
};

type Fields = {
    name: string;
    type: string;
    market: string;
    contact_email: string;
    contact_phone: string;
    credit_limit: string;
    payment_terms_days: string;
    direct_bill_enabled: boolean;
    portal_email: string;
    portal_cancel_cutoff_days: string;
    brn: string;
    tin: string;
    sst_registration_number: string;
    billing_address_line1: string;
    billing_address_line2: string;
    billing_city: string;
    billing_postal_code: string;
    billing_state_code: string;
    billing_country_code: string;
};

type Row = Fields & { key: string };
type ImportedRow = Fields & { problems: Partial<Record<keyof Fields, string>> };
type TextField = Exclude<
    keyof Fields,
    'type' | 'market' | 'direct_bill_enabled' | 'billing_country_code'
>;

type Column =
    | { field: TextField; label: string; placeholder: string; width: string }
    | {
          field:
              | 'type'
              | 'market'
              | 'direct_bill_enabled'
              | 'billing_country_code';
          label: string;
          width: string;
      };

const SIMPLE_COLUMNS: Column[] = [
    {
        field: 'name',
        label: 'Name',
        placeholder: 'Borneo Travel',
        width: 'min-w-48',
    },
    { field: 'type', label: 'Type', width: 'min-w-40' },
    { field: 'market', label: 'Market', width: 'min-w-40' },
    {
        field: 'contact_email',
        label: 'Email',
        placeholder: 'accounts@company.com',
        width: 'min-w-52',
    },
    {
        field: 'contact_phone',
        label: 'Phone',
        placeholder: '012-345 6789',
        width: 'min-w-36',
    },
    {
        field: 'credit_limit',
        label: 'Credit limit',
        placeholder: 'No limit',
        width: 'min-w-28',
    },
    {
        field: 'payment_terms_days',
        label: 'Terms (days)',
        placeholder: '30',
        width: 'min-w-24',
    },
    {
        field: 'direct_bill_enabled',
        label: 'Direct billing',
        width: 'min-w-24',
    },
    {
        field: 'portal_email',
        label: 'Portal login email',
        placeholder: 'e.g. booking@borneotravel.my',
        width: 'min-w-52',
    },
    {
        field: 'portal_cancel_cutoff_days',
        label: 'Cancel days',
        placeholder: 'e.g. 7',
        width: 'min-w-24',
    },
];

const DETAILED_COLUMNS: Column[] = [
    {
        field: 'brn',
        label: 'BRN',
        placeholder: '202001012345',
        width: 'min-w-36',
    },
    {
        field: 'tin',
        label: 'TIN',
        placeholder: 'C12345678901',
        width: 'min-w-36',
    },
    {
        field: 'sst_registration_number',
        label: 'SST no.',
        placeholder: 'W10-1808-32000123',
        width: 'min-w-40',
    },
    {
        field: 'billing_address_line1',
        label: 'Address line 1',
        placeholder: 'Lot 5, Jalan Pantai',
        width: 'min-w-52',
    },
    {
        field: 'billing_address_line2',
        label: 'Address line 2',
        placeholder: 'Level 3',
        width: 'min-w-40',
    },
    {
        field: 'billing_city',
        label: 'City',
        placeholder: 'Kota Kinabalu',
        width: 'min-w-36',
    },
    {
        field: 'billing_postal_code',
        label: 'Postcode',
        placeholder: '88000',
        width: 'min-w-24',
    },
    {
        field: 'billing_state_code',
        label: 'State',
        placeholder: 'Sabah',
        width: 'min-w-32',
    },
    { field: 'billing_country_code', label: 'Country', width: 'min-w-48' },
];

const DETAILED_FIELDS = DETAILED_COLUMNS.map((column) => column.field);
const MODE_KEY = 'companies.bulk.mode';

let nextKey = 0;

function emptyRow(): Row {
    nextKey += 1;

    return {
        key: `row-${nextKey}`,
        name: '',
        type: 'corporate',
        market: '',
        contact_email: '',
        contact_phone: '',
        credit_limit: '',
        payment_terms_days: '',
        direct_bill_enabled: true,
        portal_email: '',
        portal_cancel_cutoff_days: '',
        brn: '',
        tin: '',
        sst_registration_number: '',
        billing_address_line1: '',
        billing_address_line2: '',
        billing_city: '',
        billing_postal_code: '',
        billing_state_code: '',
        billing_country_code: '',
    };
}

function isBlank(row: Row): boolean {
    const blank = emptyRow();

    return (Object.keys(blank) as (keyof Row)[])
        .filter((field) => field !== 'key')
        .every((field) => row[field] === blank[field]);
}

function hasDetailedValues(row: Row): boolean {
    return DETAILED_FIELDS.some((field) => row[field] !== '');
}

function readMode(): Mode {
    try {
        return window.localStorage.getItem(MODE_KEY) === 'detailed'
            ? 'detailed'
            : 'simple';
    } catch {
        return 'simple';
    }
}

function writeMode(mode: Mode) {
    try {
        window.localStorage.setItem(MODE_KEY, mode);
    } catch {
        // Remembering the mode is a convenience only.
    }
}

type AddedLogin = { id: number; name: string; email: string };

function AddedResult({
    added,
    exportFormats,
}: {
    added: AddedLogin[];
    exportFormats: EnumOption[];
}) {
    const [copying, setCopying] = useState(false);
    const userIds = added.map((item) => item.id);

    return (
        <div className="flex flex-col gap-4">
            <div className="overflow-hidden rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Company</TableHead>
                            <TableHead>Portal login email</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {added.map((item) => (
                            <TableRow key={item.id}>
                                <TableCell className="font-medium">
                                    {item.name}
                                </TableCell>
                                <TableCell>{item.email}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
            <div className="flex flex-wrap justify-end gap-2">
                <Button variant="outline" onClick={() => setCopying(true)}>
                    <Copy data-icon="inline-start" />
                    Copy all
                </Button>
                <ExportTemporaryPasswordsMenu
                    route={exportLogins()}
                    userIds={userIds}
                    formats={exportFormats}
                />
                <Link href={index()} className={buttonVariants()}>
                    Done
                </Link>
            </div>
            <TemporaryPasswordMessageDialog
                route={messageLogins()}
                who="company"
                userIds={userIds}
                open={copying}
                onOpenChange={setCopying}
            />
        </div>
    );
}

export default function CompaniesBulkAdd({
    types,
    markets,
    countries,
    modes,
    maxRows,
    exportFormats,
}: {
    types: EnumOption[];
    markets: EnumOption[];
    countries: EnumOption[];
    modes: EnumOption[];
    maxRows: number;
    exportFormats: EnumOption[];
}) {
    const [added, setAdded] = useState<AddedLogin[] | null>(null);
    const form = useForm<{ rows: Row[] }>({ rows: [emptyRow()] });
    const upload = useHttp<
        { file: File | null },
        { mode: Mode; rows: ImportedRow[] }
    >(importRoute(), { file: null });
    const fileInput = useRef<HTMLInputElement>(null);
    const [mode, setMode] = useState<Mode>('simple');
    const [problems, setProblems] = useState<
        Record<string, Partial<Record<keyof Fields, string>>>
    >({});
    const guard = useUnsavedChanges(
        form.data.rows.some((row) => !isBlank(row)) && !form.processing,
    );

    useEffect(() => setMode(readMode()), []);

    const rows = form.data.rows;
    const filledCount = rows.filter((row) => !isBlank(row)).length;
    const hiddenCount =
        mode === 'simple' ? rows.filter(hasDetailedValues).length : 0;
    const columns =
        mode === 'detailed'
            ? [...SIMPLE_COLUMNS, ...DETAILED_COLUMNS]
            : SIMPLE_COLUMNS;

    const changeMode = (next: Mode) => {
        setMode(next);
        writeMode(next);
    };

    const updateRow = <F extends keyof Fields>(
        key: string,
        field: F,
        value: Fields[F],
    ) => {
        form.setData(
            'rows',
            rows.map((row) =>
                row.key === key ? { ...row, [field]: value } : row,
            ),
        );
        setProblems((current) => {
            if (!current[key]?.[field]) {
                return current;
            }

            const { [field]: _cleared, ...rest } = current[key];

            return { ...current, [key]: rest };
        });
    };

    const removeRow = (key: string) => {
        const remaining = rows.filter((row) => row.key !== key);
        form.setData('rows', remaining.length > 0 ? remaining : [emptyRow()]);
    };

    const readFile = (file: File) => {
        upload.transform(() => ({ file }));
        upload
            .submit()
            .then(({ mode: fileMode, rows: imported }) => {
                const fromFile = imported.map(
                    ({ problems: rowProblems, ...fields }) => ({
                        row: { ...emptyRow(), ...fields },
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
                    ...rows.filter((row) => !isBlank(row)),
                    ...fromFile.map(({ row }) => row),
                ]);

                if (fileMode === 'detailed') {
                    changeMode('detailed');
                }
            })
            .catch(() => undefined)
            .finally(() => {
                if (fileInput.current) {
                    fileInput.current.value = '';
                }
            });
    };

    const submit = (onDone?: () => void) => {
        form.transform((data) => ({
            rows: data.rows
                .filter((row) => !isBlank(row))
                .map(({ key: _key, ...fields }) => fields),
        }));
        form.post(bulkStore.url(), {
            preserveScroll: true,
            onSuccess: (page) => {
                const list = (
                    page.flash as { bulkAdded?: AddedLogin[] } | undefined
                )?.bulkAdded;

                if (list && list.length > 0) {
                    form.setData('rows', [emptyRow()]);
                    setAdded(list);
                }

                onDone?.();
            },
        });
    };

    const cellError = (position: number, row: Row, field: keyof Fields) =>
        (form.errors as Record<string, string | undefined>)[
            `rows.${position}.${field}`
        ] ?? problems[row.key]?.[field];

    const cell = (row: Row, position: number, column: Column) => {
        const error = cellError(position, row, column.field);
        const label = `${column.label}, row ${position + 1}`;

        if (column.field === 'type') {
            return (
                <EnumSelect
                    options={types}
                    value={row.type}
                    placeholder="Choose a type"
                    aria-label={label}
                    aria-invalid={!!error}
                    className="w-full"
                    onValueChange={(value) => updateRow(row.key, 'type', value)}
                />
            );
        }

        if (column.field === 'market') {
            return (
                <EnumSelect
                    options={markets}
                    value={row.market}
                    allLabel="Not set"
                    aria-label={label}
                    aria-invalid={!!error}
                    className="w-full"
                    onValueChange={(value) =>
                        updateRow(row.key, 'market', value)
                    }
                />
            );
        }

        if (column.field === 'direct_bill_enabled') {
            return (
                <div className="flex h-8 items-center justify-center">
                    <Checkbox
                        checked={row.direct_bill_enabled}
                        aria-label={label}
                        onCheckedChange={(checked) =>
                            updateRow(
                                row.key,
                                'direct_bill_enabled',
                                checked === true,
                            )
                        }
                    />
                </div>
            );
        }

        if (column.field === 'billing_country_code') {
            return (
                <CountrySelect
                    id={`country-${row.key}`}
                    options={countries}
                    value={row.billing_country_code}
                    invalid={!!error}
                    onValueChange={(value) =>
                        updateRow(row.key, 'billing_country_code', value)
                    }
                />
            );
        }

        const field = column.field;

        return (
            <Input
                value={row[field]}
                placeholder={
                    'placeholder' in column ? column.placeholder : undefined
                }
                inputMode={
                    field === 'credit_limit'
                        ? 'decimal'
                        : field === 'payment_terms_days'
                          ? 'numeric'
                          : undefined
                }
                type={field === 'contact_email' ? 'email' : 'text'}
                autoComplete="off"
                aria-label={label}
                aria-invalid={!!error}
                onChange={(event) =>
                    updateRow(row.key, field, event.target.value)
                }
            />
        );
    };

    if (added !== null) {
        return (
            <>
                <Head title="Bulk add companies" />
                <div className="flex flex-1 flex-col gap-6 p-4">
                    <PageHeader
                        title="Companies added"
                        description={`${added.length} ${added.length === 1 ? 'company has' : 'companies have'} a new portal login with a temp password that works for 14 days. Copy the sign-in messages and pass them on.`}
                        back={{ href: index(), label: 'Companies' }}
                    />
                    <AddedResult added={added} exportFormats={exportFormats} />
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Bulk add companies" />

            <form
                className="flex min-w-0 flex-1 flex-col gap-6 p-4 pb-0"
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                <PageHeader
                    title="Bulk add companies"
                    description={`Up to ${maxRows} companies. Simple has the add sheet fields; Detailed adds tax numbers and the billing address.`}
                    back={{ href: index(), label: 'Companies' }}
                />

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <ToggleGroup
                        value={[mode]}
                        onValueChange={(value) => {
                            if (value[0]) {
                                changeMode(value[0] as Mode);
                            }
                        }}
                        variant="outline"
                        spacing={0}
                        aria-label="Columns"
                    >
                        {modes.map((option) => {
                            const Icon = MODE_ICONS[option.value as Mode];

                            return (
                                <ToggleGroupItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    <Icon />
                                    {option.label}
                                </ToggleGroupItem>
                            );
                        })}
                    </ToggleGroup>
                    <div className="flex flex-wrap items-center gap-2">
                        <input
                            ref={fileInput}
                            type="file"
                            accept=".csv,.xlsx"
                            className="hidden"
                            onChange={(event) => {
                                const file = event.target.files?.[0];

                                if (file) {
                                    readFile(file);
                                }
                            }}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            disabled={upload.processing}
                            focusableWhenDisabled
                            onClick={() => fileInput.current?.click()}
                        >
                            {upload.processing ? (
                                <Spinner data-icon="inline-start" />
                            ) : (
                                <Upload data-icon="inline-start" />
                            )}
                            Upload CSV / XLSX
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger
                                render={
                                    <Button type="button" variant="outline" />
                                }
                            >
                                <Download data-icon="inline-start" />
                                Template
                                <ChevronDown data-icon="inline-end" />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    render={
                                        <a
                                            href={importTemplate.url(
                                                { format: 'csv' },
                                                { query: { mode } },
                                            )}
                                            download
                                        />
                                    }
                                >
                                    CSV
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    render={
                                        <a
                                            href={importTemplate.url(
                                                { format: 'xlsx' },
                                                { query: { mode } },
                                            )}
                                            download
                                        />
                                    }
                                >
                                    Excel (XLSX)
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>

                {upload.errors.file && (
                    <FieldError>{upload.errors.file}</FieldError>
                )}
                {(form.errors as Record<string, string | undefined>).rows && (
                    <FieldError>
                        {(form.errors as Record<string, string>).rows}
                    </FieldError>
                )}
                {hiddenCount > 0 && (
                    <p className="text-sm text-muted-foreground">
                        Detailed values are kept on {hiddenCount}{' '}
                        {hiddenCount === 1 ? 'row' : 'rows'} and will be saved.
                        Switch to Detailed to see them.
                    </p>
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-10">#</TableHead>
                                {columns.map((column) => (
                                    <TableHead
                                        key={column.field}
                                        className={column.width}
                                    >
                                        {column.label}
                                    </TableHead>
                                ))}
                                <TableHead className="w-10">
                                    <span className="sr-only">Remove</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row, position) => (
                                <TableRow key={row.key} className="align-top">
                                    <TableCell className="pt-4 text-muted-foreground tabular-nums">
                                        {position + 1}
                                    </TableCell>
                                    {columns.map((column) => (
                                        <TableCell
                                            key={column.field}
                                            className={column.width}
                                        >
                                            {cell(row, position, column)}
                                            <FieldError>
                                                {cellError(
                                                    position,
                                                    row,
                                                    column.field,
                                                )}
                                            </FieldError>
                                        </TableCell>
                                    ))}
                                    <TableCell>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`Remove row ${position + 1}`}
                                            onClick={() => removeRow(row.key)}
                                        >
                                            <X />
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell
                                    colSpan={columns.length + 2}
                                    className="text-start"
                                >
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={rows.length >= maxRows}
                                        focusableWhenDisabled
                                        onClick={() =>
                                            form.setData('rows', [
                                                ...rows,
                                                emptyRow(),
                                            ])
                                        }
                                    >
                                        <Plus data-icon="inline-start" />
                                        Add row
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>

                <div className="flex-1" />

                <FormActions>
                    <Link
                        href={index()}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        Cancel
                    </Link>
                    <Button
                        type="submit"
                        disabled={form.processing || filledCount === 0}
                        focusableWhenDisabled
                    >
                        {form.processing ? (
                            <Spinner data-icon="inline-start" />
                        ) : (
                            <Building2 data-icon="inline-start" />
                        )}
                        Create {filledCount}{' '}
                        {filledCount === 1 ? 'company' : 'companies'}
                    </Button>
                </FormActions>
            </form>

            <UnsavedChangesDialog
                guard={guard}
                name="these company rows"
                saving={form.processing}
                onSave={() => submit(() => guard.proceed())}
            />
        </>
    );
}

CompaniesBulkAdd.layout = {
    breadcrumbs: [
        { title: 'Companies', href: index() },
        { title: 'Bulk add', href: bulkCreate() },
    ],
};
