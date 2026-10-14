import { Head, Link, useForm, useHttp } from '@inertiajs/react';
import {
    ChevronDown,
    Copy,
    Download,
    Plus,
    Upload,
    UserCheck,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { FormActions } from '@/components/pms/form-actions';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { ExportTemporaryPasswordsMenu } from '@/components/pms/temporary-passwords/export-temporary-passwords-menu';
import { TemporaryPasswordMessageDialog } from '@/components/pms/temporary-passwords/temporary-password-message-dialog';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
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
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { suggestPartnerPrefix } from '@/lib/partner-prefix';
import { dashboard } from '@/routes/admin';
import { index } from '@/routes/admin/partners';
import {
    create as bulkCreate,
    importMethod as importRoute,
    importTemplate,
    store as bulkStore,
} from '@/routes/admin/partners/bulk';
import {
    exportMethod,
    message,
} from '@/routes/admin/partners/temporary-passwords';

type Row = {
    key: string;
    partner_name: string;
    code_prefix: string;
    /** True once the admin types a prefix or the file gives one. A touched prefix is never overwritten. */
    prefix_touched: boolean;
    name: string;
    email: string;
};
type ImportedRow = {
    partner_name: string;
    code_prefix: string;
    name: string;
    email: string;
};
type AddedPartner = {
    id: number;
    partner: string;
    name: string;
    email: string;
};

let nextKey = 0;

function emptyRow(): Row {
    nextKey += 1;

    return {
        key: `row-${nextKey}`,
        partner_name: '',
        code_prefix: '',
        prefix_touched: false,
        name: '',
        email: '',
    };
}

function isBlank(row: Row): boolean {
    return (
        row.partner_name === '' &&
        row.code_prefix === '' &&
        row.name === '' &&
        row.email === ''
    );
}

function AddedResult({
    added,
    exportFormats,
}: {
    added: AddedPartner[];
    exportFormats: EnumOption[];
}) {
    const [copying, setCopying] = useState(false);

    return (
        <div className="flex flex-col gap-4">
            <div className="overflow-hidden rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Partner</TableHead>
                            <TableHead>Name</TableHead>
                            <TableHead>Email</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {added.map((item) => (
                            <TableRow key={item.id}>
                                <TableCell className="font-medium">
                                    {item.partner}
                                </TableCell>
                                <TableCell>{item.name}</TableCell>
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
                    route={exportMethod()}
                    userIds={added.map((item) => item.id)}
                    formats={exportFormats}
                />
                <Link href={index()} className={buttonVariants()}>
                    Done
                </Link>
            </div>
            <TemporaryPasswordMessageDialog
                route={message()}
                who="partner"
                userIds={added.map((item) => item.id)}
                open={copying}
                onOpenChange={setCopying}
            />
        </div>
    );
}

export default function PartnersBulkAdd({
    maxRows,
    exportFormats,
}: {
    maxRows: number;
    exportFormats: EnumOption[];
}) {
    const form = useForm<{ rows: Row[] }>({ rows: [emptyRow()] });
    const upload = useHttp<{ file: File | null }, { rows: ImportedRow[] }>(
        importRoute(),
        { file: null },
    );
    const fileInput = useRef<HTMLInputElement>(null);
    const [added, setAdded] = useState<AddedPartner[] | null>(null);
    const guard = useUnsavedChanges(
        form.data.rows.some((row) => !isBlank(row)) && !form.processing,
    );

    const rows = form.data.rows;
    const filledCount = rows.filter((row) => !isBlank(row)).length;

    const updateRow = (key: string, changes: Partial<Row>) => {
        form.setData(
            'rows',
            rows.map((row) => (row.key === key ? { ...row, ...changes } : row)),
        );
    };

    const changeName = (row: Row, partnerName: string) => {
        updateRow(row.key, {
            partner_name: partnerName,
            code_prefix: row.prefix_touched
                ? row.code_prefix
                : suggestPartnerPrefix(partnerName),
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
            .then(({ rows: imported }) => {
                const fromFile = imported.map((item): Row => {
                    const prefix = item.code_prefix;

                    return {
                        ...emptyRow(),
                        partner_name: item.partner_name,
                        code_prefix:
                            prefix !== ''
                                ? prefix
                                : suggestPartnerPrefix(item.partner_name),
                        prefix_touched: prefix !== '',
                        name: item.name,
                        email: item.email,
                    };
                });

                form.setData('rows', [
                    ...rows.filter((row) => !isBlank(row)),
                    ...fromFile,
                ]);
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
                .map(({ key: _key, prefix_touched: _touched, ...row }) => row),
        }));
        form.post(bulkStore.url(), {
            preserveScroll: true,
            onSuccess: (page) => {
                const list = (
                    page.flash as { bulkAdded?: AddedPartner[] } | undefined
                )?.bulkAdded;

                form.setData('rows', [emptyRow()]);
                setAdded(list ?? []);
                onDone?.();
            },
        });
    };

    const rowError = (
        index: number,
        field: 'partner_name' | 'code_prefix' | 'name' | 'email',
    ) =>
        (form.errors as Record<string, string | undefined>)[
            `rows.${index}.${field}`
        ];

    if (added !== null) {
        return (
            <>
                <Head title="Bulk add partners" />
                <div className="flex flex-1 flex-col gap-6 p-4">
                    <PageHeader
                        title={`${added.length} partners added`}
                        description="Each has a temp password that works for 14 days. Copy the sign-in messages and pass them on."
                        back={{ href: index(), label: 'Partners' }}
                    />
                    <AddedResult added={added} exportFormats={exportFormats} />
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Bulk add partners" />

            <form
                className="flex flex-1 flex-col gap-6 p-4 pb-0"
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                <PageHeader
                    title="Bulk add partners"
                    description={`Up to ${maxRows} partners. Each gets a login with a temp password. Nothing is emailed.`}
                    back={{ href: index(), label: 'Partners' }}
                    actions={
                        <>
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
                                        <Button
                                            type="button"
                                            variant="outline"
                                        />
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
                                                href={importTemplate.url({
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
                                                href={importTemplate.url({
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
                        </>
                    }
                />

                {upload.errors.file && (
                    <FieldError>{upload.errors.file}</FieldError>
                )}
                {(form.errors as Record<string, string | undefined>).rows && (
                    <FieldError>
                        {(form.errors as Record<string, string>).rows}
                    </FieldError>
                )}

                <div className="flex flex-col gap-3">
                    <div className="overflow-hidden rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-10">#</TableHead>
                                    <TableHead>Partner name</TableHead>
                                    <TableHead className="w-28">
                                        Prefix
                                    </TableHead>
                                    <TableHead>User name</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead className="w-10">
                                        <span className="sr-only">Remove</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row, position) => (
                                    <TableRow
                                        key={row.key}
                                        className="align-top"
                                    >
                                        <TableCell className="pt-4 text-muted-foreground tabular-nums">
                                            {position + 1}
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                value={row.partner_name}
                                                placeholder="Andaman Stays"
                                                aria-label={`Partner name, row ${position + 1}`}
                                                aria-invalid={
                                                    !!rowError(
                                                        position,
                                                        'partner_name',
                                                    )
                                                }
                                                onChange={(event) =>
                                                    changeName(
                                                        row,
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <FieldError>
                                                {rowError(
                                                    position,
                                                    'partner_name',
                                                )}
                                            </FieldError>
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                value={row.code_prefix}
                                                placeholder="AS"
                                                autoComplete="off"
                                                maxLength={2}
                                                className="uppercase"
                                                aria-label={`Prefix, row ${position + 1}`}
                                                aria-invalid={
                                                    !!rowError(
                                                        position,
                                                        'code_prefix',
                                                    )
                                                }
                                                onChange={(event) =>
                                                    updateRow(row.key, {
                                                        code_prefix:
                                                            event.target.value.toUpperCase(),
                                                        prefix_touched: true,
                                                    })
                                                }
                                            />
                                            <FieldError>
                                                {rowError(
                                                    position,
                                                    'code_prefix',
                                                )}
                                            </FieldError>
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                value={row.name}
                                                placeholder="Aina Rahman"
                                                aria-label={`User name, row ${position + 1}`}
                                                aria-invalid={
                                                    !!rowError(position, 'name')
                                                }
                                                onChange={(event) =>
                                                    updateRow(row.key, {
                                                        name: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                            <FieldError>
                                                {rowError(position, 'name')}
                                            </FieldError>
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                type="email"
                                                value={row.email}
                                                placeholder="ops@andaman.my"
                                                autoComplete="off"
                                                aria-label={`Email, row ${position + 1}`}
                                                aria-invalid={
                                                    !!rowError(
                                                        position,
                                                        'email',
                                                    )
                                                }
                                                onChange={(event) =>
                                                    updateRow(row.key, {
                                                        email: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                            <FieldError>
                                                {rowError(position, 'email')}
                                            </FieldError>
                                        </TableCell>
                                        <TableCell>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                aria-label={`Remove row ${position + 1}`}
                                                onClick={() =>
                                                    removeRow(row.key)
                                                }
                                            >
                                                <X />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell colSpan={6} className="text-end">
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
                            <UserCheck data-icon="inline-start" />
                        )}
                        Create {filledCount} partners
                    </Button>
                </FormActions>
            </form>

            <UnsavedChangesDialog
                guard={guard}
                name="these partner rows"
                saving={form.processing}
                onSave={() => submit(() => guard.proceed())}
            />
        </>
    );
}

PartnersBulkAdd.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Partners', href: index() },
        { title: 'Bulk add', href: bulkCreate() },
    ],
};
