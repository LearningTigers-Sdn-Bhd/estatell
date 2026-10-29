import { Head, setLayoutProps, useForm, useHttp } from '@inertiajs/react';
import {
    ChevronDown,
    Download,
    Info,
    Plus,
    Trash2,
    Upload,
} from 'lucide-react';
import { useRef, useState } from 'react';
import TeamController from '@/actions/App/Http/Controllers/Onboarding/TeamController';
import Heading from '@/components/heading';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
import { StepForm } from '@/components/pms/onboarding/step-form';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
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

type Role = App.Data.Onboarding.OnboardingRoleData;
type Staff = App.Data.Onboarding.OnboardingStaffData;
type Row = { key: string; name: string; email: string; role_id: string };
type ImportedRow = {
    name: string;
    email: string;
    roleId: number | null;
    problem: string | null;
};

const FORM_ID = 'team-form';
let counter = 0;

export default function Team({
    owner,
    staff,
    roles,
    defaultRoleId,
    maxRows,
    locked,
    blockedBy,
}: {
    owner: { name: string; email: string };
    staff: Staff[];
    roles: Role[];
    defaultRoleId: string;
    maxRows: number;
    locked: boolean;
    blockedBy: { label: string; url: string | null } | null;
}) {
    const newRow = (values: Partial<Row> = {}): Row => ({
        key: `new-${++counter}`,
        name: '',
        email: '',
        role_id: defaultRoleId,
        ...values,
    });

    const form = useForm<{ rows: Row[] }>({ rows: [newRow()] });
    const upload = useHttp<{ file: File | null }, { rows: ImportedRow[] }>({
        file: null,
    });
    const fileInput = useRef<HTMLInputElement>(null);
    const guard = useUnsavedChanges(form.isDirty);
    const [problems, setProblems] = useState<Record<string, string>>({});
    const rows = form.data.rows;
    const errors = form.errors as Record<string, string | undefined>;

    setLayoutProps<OnboardingLayoutProps>({
        step: 'team',
        footer: 'auto',
        formId: FORM_ID,
        processing: form.processing,
    });

    const setRow = (index: number, values: Partial<Row>) =>
        form.setData(
            'rows',
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );

    const removeRow = (index: number) => {
        form.setData(
            'rows',
            rows.filter((_, position) => position !== index),
        );
        form.clearErrors();
    };

    const readFile = (file: File) => {
        upload.transform(() => ({ file }));
        upload
            .post(TeamController.import.url())
            .then(({ rows: imported }) => {
                const fromFile = imported.map(
                    ({ problem: _problem, roleId, ...rest }) =>
                        newRow({
                            ...rest,
                            role_id: roleId === null ? '' : String(roleId),
                        }),
                );

                setProblems((current) => ({
                    ...current,
                    ...Object.fromEntries(
                        imported.flatMap(({ problem }, position) =>
                            problem === null
                                ? []
                                : [[fromFile[position].key, problem]],
                        ),
                    ),
                }));
                form.setData('rows', [
                    ...rows.filter(
                        (row) => row.name !== '' || row.email !== '',
                    ),
                    ...fromFile,
                ]);
            })
            .catch(() => undefined);
    };

    const save = (onSuccess?: () => void) => {
        form.transform((data) => ({
            rows: data.rows.map(({ key: _key, ...row }) => row),
        }));
        form.put(TeamController.update.url(), {
            preserveScroll: true,
            onSuccess,
        });
    };

    const roleName = (id: string) =>
        roles.find((role) => role.value === id)?.label ?? '';

    return (
        <>
            <Head title="Team" />
            <Heading
                title="Team"
                description="Who else works here? Add them now and they can sign in once you go live. Skip this if you work alone."
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
                    aria-label="Team file"
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

                <div className="overflow-hidden rounded-lg border">
                    <Table>
                        <TableHeader className="max-md:hidden">
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Email</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Sign-in</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow className="max-md:grid max-md:gap-1 max-md:p-3">
                                <TableCell className="max-md:p-0 max-md:font-medium">
                                    {owner.name}{' '}
                                    <span className="text-muted-foreground">
                                        (you)
                                    </span>
                                </TableCell>
                                <TableCell className="max-md:p-0">
                                    {owner.email}
                                </TableCell>
                                <TableCell className="max-md:p-0">
                                    Owner
                                </TableCell>
                                <TableCell className="text-muted-foreground max-md:p-0">
                                    Active
                                </TableCell>
                                <TableCell className="max-md:hidden" />
                            </TableRow>
                            {staff.map((person) => (
                                <TableRow
                                    key={person.id}
                                    className="max-md:grid max-md:gap-1 max-md:p-3"
                                >
                                    <TableCell className="max-md:p-0 max-md:font-medium">
                                        {person.name}
                                    </TableCell>
                                    <TableCell className="max-md:p-0">
                                        {person.email}
                                    </TableCell>
                                    <TableCell className="max-md:p-0">
                                        {person.roleName}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground max-md:p-0">
                                        Ready after go-live
                                    </TableCell>
                                    <TableCell className="max-md:hidden" />
                                </TableRow>
                            ))}
                            {rows.map((row, index) => {
                                const fieldError = (name: string) =>
                                    errors[`rows.${index}.${name}`] ??
                                    (name === 'role_id'
                                        ? problems[row.key]
                                        : undefined);

                                return (
                                    <TableRow
                                        key={row.key}
                                        className="max-md:grid max-md:gap-3 max-md:p-3"
                                    >
                                        <TableCell className="align-top max-md:p-0">
                                            <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                Name
                                            </span>
                                            <Input
                                                aria-label={`Name of person ${index + 1}`}
                                                placeholder="e.g. Aina Rahman"
                                                maxLength={255}
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
                                        <TableCell className="align-top max-md:p-0">
                                            <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                Email
                                            </span>
                                            <Input
                                                aria-label={`Email of person ${index + 1}`}
                                                type="email"
                                                placeholder="e.g. aina@seaview.my"
                                                maxLength={255}
                                                value={row.email}
                                                onChange={(event) =>
                                                    setRow(index, {
                                                        email: event.target
                                                            .value,
                                                    })
                                                }
                                                aria-invalid={
                                                    !!fieldError('email')
                                                }
                                            />
                                            <FieldError>
                                                {fieldError('email')}
                                            </FieldError>
                                        </TableCell>
                                        <TableCell className="align-top max-md:p-0">
                                            <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                Role
                                            </span>
                                            <Select
                                                items={roles}
                                                value={row.role_id}
                                                onValueChange={(value) =>
                                                    setRow(index, {
                                                        role_id:
                                                            value as string,
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    aria-label={`Role of person ${index + 1}`}
                                                    className="w-full"
                                                    aria-invalid={
                                                        !!fieldError('role_id')
                                                    }
                                                >
                                                    <SelectValue>
                                                        {roleName(row.role_id)}
                                                    </SelectValue>
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectGroup>
                                                        {roles.map((role) => (
                                                            <SelectItem
                                                                key={role.value}
                                                                value={
                                                                    role.value
                                                                }
                                                            >
                                                                {role.label}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectGroup>
                                                </SelectContent>
                                            </Select>
                                            <FieldError>
                                                {fieldError('role_id')}
                                            </FieldError>
                                        </TableCell>
                                        <TableCell className="align-top text-muted-foreground max-md:p-0">
                                            <span className="block pt-2 max-md:pt-0">
                                                Created when you save
                                            </span>
                                        </TableCell>
                                        <TableCell className="align-top max-md:p-0">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Remove person ${index + 1}`}
                                                onClick={() => removeRow(index)}
                                            >
                                                <Trash2 />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={5}>
                                    <div className="flex flex-wrap justify-end gap-2">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger
                                                render={
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
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
                                                            href={TeamController.template.url(
                                                                {
                                                                    format: 'csv',
                                                                },
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
                                                            href={TeamController.template.url(
                                                                {
                                                                    format: 'xlsx',
                                                                },
                                                            )}
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
                                            disabled={
                                                upload.processing ||
                                                rows.length >= maxRows
                                            }
                                            focusableWhenDisabled
                                            onClick={() =>
                                                fileInput.current?.click()
                                            }
                                        >
                                            <Upload data-icon="inline-start" />
                                            Import from file
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={rows.length >= maxRows}
                                            focusableWhenDisabled
                                            onClick={() =>
                                                form.setData('rows', [
                                                    ...rows,
                                                    newRow(),
                                                ])
                                            }
                                        >
                                            <Plus data-icon="inline-start" />
                                            Add person
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>

                <section
                    className="flex flex-col gap-3 rounded-lg border p-4"
                    aria-labelledby="team-roles"
                >
                    <h2
                        id="team-roles"
                        className="font-heading text-base font-semibold"
                    >
                        Roles
                    </h2>
                    <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
                        {roles.map((role) => (
                            <div key={role.value} className="contents">
                                <dt className="font-medium">{role.label}</dt>
                                <dd className="text-muted-foreground">
                                    {role.description}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    <p className="flex items-start gap-2 text-sm text-muted-foreground">
                        <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
                        You can change what each role can do after you go live,
                        in Settings.
                    </p>
                </section>
            </StepForm>
            <UnsavedChangesDialog
                guard={guard}
                name="Team"
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}

Team.layout = { width: '5xl' };
