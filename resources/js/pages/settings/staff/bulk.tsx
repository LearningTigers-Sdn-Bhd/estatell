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
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormActions } from '@/components/pms/form-actions';
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
import { useIsImpersonating } from '@/hooks/use-is-impersonating';
import {
    create as bulkCreate,
    store as bulkStore,
} from '@/routes/settings/staff/bulk';
import { exportMethod, message } from '@/routes/settings/staff/temp-passwords';
import {
    importMethod as importRoute,
    importTemplate,
    index,
} from '@/routes/settings/staff';

type RoleOption = App.Data.Staff.RoleOptionData;
type Row = { key: string; name: string; email: string; role_id: string };
type ImportedRow = {
    name: string;
    email: string;
    roleId: number | null;
    problem: string | null;
};
type AddedStaff = { id: number; name: string; email: string };

let nextKey = 0;

function emptyRow(): Row {
    nextKey += 1;

    return { key: `row-${nextKey}`, name: '', email: '', role_id: '' };
}

function isBlank(row: Row): boolean {
    return row.name === '' && row.email === '' && row.role_id === '';
}

function AddedResult({
    added,
    exportFormats,
}: {
    added: AddedStaff[];
    exportFormats: EnumOption[];
}) {
    const [copying, setCopying] = useState(false);

    return (
        <div className="flex flex-col gap-4">
            <div className="overflow-hidden rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Email</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {added.map((person) => (
                            <TableRow key={person.id}>
                                <TableCell className="font-medium">
                                    {person.name}
                                </TableCell>
                                <TableCell>{person.email}</TableCell>
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
                    userIds={added.map((person) => person.id)}
                    formats={exportFormats}
                />
                <Link href={index()} className={buttonVariants()}>
                    Done
                </Link>
            </div>
            <TemporaryPasswordMessageDialog
                route={message()}
                userIds={added.map((person) => person.id)}
                open={copying}
                onOpenChange={setCopying}
            />
        </div>
    );
}

export default function StaffBulkAdd({
    roles,
    maxRows,
    exportFormats,
}: {
    roles: RoleOption[];
    maxRows: number;
    exportFormats: EnumOption[];
}) {
    const impersonating = useIsImpersonating();
    const form = useForm<{ rows: Row[] }>({ rows: [emptyRow()] });
    const upload = useHttp<{ file: File | null }, { rows: ImportedRow[] }>(
        importRoute(),
        { file: null },
    );
    const fileInput = useRef<HTMLInputElement>(null);
    const [problems, setProblems] = useState<Record<string, string>>({});
    const [added, setAdded] = useState<AddedStaff[] | null>(null);
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

    const removeRow = (key: string) => {
        const remaining = rows.filter((row) => row.key !== key);
        form.setData('rows', remaining.length > 0 ? remaining : [emptyRow()]);
    };

    const readFile = (file: File) => {
        upload.transform(() => ({ file }));
        upload
            .submit()
            .then(({ rows: imported }) => {
                const fromFile = imported.map((item) => ({
                    ...emptyRow(),
                    name: item.name,
                    email: item.email,
                    role_id: item.roleId === null ? '' : String(item.roleId),
                    problem: item.problem,
                }));

                setProblems((current) => ({
                    ...current,
                    ...Object.fromEntries(
                        fromFile
                            .filter((row) => row.problem !== null)
                            .map((row) => [row.key, row.problem as string]),
                    ),
                }));
                form.setData('rows', [
                    ...rows.filter((row) => !isBlank(row)),
                    ...fromFile.map(({ problem: _problem, ...row }) => row),
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
            rows: data.rows.filter((row) => !isBlank(row)),
        }));
        form.post(bulkStore.url(), {
            preserveScroll: true,
            onSuccess: (page) => {
                const list = (
                    page.flash as { bulkAdded?: AddedStaff[] } | undefined
                )?.bulkAdded;

                form.setData('rows', [emptyRow()]);
                setProblems({});
                setAdded(list ?? []);
                onDone?.();
            },
        });
    };

    const rowError = (index: number, field: 'name' | 'email' | 'role_id') =>
        (form.errors as Record<string, string | undefined>)[
            `rows.${index}.${field}`
        ];

    if (impersonating) {
        return (
            <>
                <Head title="Bulk add staff" />
                <div className="flex flex-1 flex-col gap-6 p-4">
                    <PageHeader
                        title="Bulk add staff"
                        back={{ href: index(), label: 'Staff' }}
                    />
                    <p className="text-sm text-muted-foreground">
                        Not available during support access.
                    </p>
                </div>
            </>
        );
    }

    if (added !== null) {
        return (
            <>
                <Head title="Bulk add staff" />
                <div className="flex flex-1 flex-col gap-6 p-4">
                    <PageHeader
                        title={`${added.length} staff added`}
                        description="Each has a temp password that works for 14 days. Copy the sign-in messages and pass them on."
                        back={{ href: index(), label: 'Staff' }}
                    />
                    <AddedResult added={added} exportFormats={exportFormats} />
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Bulk add staff" />

            <form
                className="flex flex-1 flex-col gap-6 p-4 pb-0"
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                <PageHeader
                    title="Bulk add staff"
                    description={`Up to ${maxRows} people. Each gets a temp password. Nothing is emailed.`}
                    back={{ href: index(), label: 'Staff' }}
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
                                    <TableHead>Name</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead className="w-48">Role</TableHead>
                                    <TableHead className="w-10">
                                        <span className="sr-only">Remove</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row, position) => {
                                    const roleProblem =
                                        rowError(position, 'role_id') ??
                                        problems[row.key];

                                    return (
                                        <TableRow
                                            key={row.key}
                                            className="align-top"
                                        >
                                            <TableCell className="pt-4 text-muted-foreground tabular-nums">
                                                {position + 1}
                                            </TableCell>
                                            <TableCell>
                                                <Input
                                                    value={row.name}
                                                    placeholder="Ali Hassan"
                                                    aria-label={`Name, row ${position + 1}`}
                                                    aria-invalid={
                                                        !!rowError(
                                                            position,
                                                            'name',
                                                        )
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
                                                    placeholder="ali@hotel.com"
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
                                                    {rowError(
                                                        position,
                                                        'email',
                                                    )}
                                                </FieldError>
                                            </TableCell>
                                            <TableCell>
                                                <EnumSelect
                                                    options={roles}
                                                    value={row.role_id}
                                                    placeholder="Choose a role"
                                                    aria-label={`Role, row ${position + 1}`}
                                                    aria-invalid={!!roleProblem}
                                                    className="w-full"
                                                    onValueChange={(value) => {
                                                        updateRow(row.key, {
                                                            role_id: value,
                                                        });
                                                        setProblems(
                                                            ({
                                                                [row.key]:
                                                                    _cleared,
                                                                ...rest
                                                            }) => rest,
                                                        );
                                                    }}
                                                />
                                                <FieldError>
                                                    {roleProblem}
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
                                    );
                                })}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell colSpan={5} className="text-end">
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
                        Create {filledCount} staff
                    </Button>
                </FormActions>
            </form>

            <UnsavedChangesDialog
                guard={guard}
                name="these staff rows"
                saving={form.processing}
                onSave={() => submit(() => guard.proceed())}
            />
        </>
    );
}

StaffBulkAdd.layout = {
    breadcrumbs: [
        { title: 'Staff', href: index() },
        { title: 'Bulk add', href: bulkCreate() },
    ],
};
