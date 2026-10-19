import { Form, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowUpRight,
    Building2,
    Ellipsis,
    ListChecks,
    Trash2,
    UserRound,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import type { Choice } from '@/components/pms/choice-cards';
import { EmptyState } from '@/components/pms/empty-state';
import { FormOverlay } from '@/components/pms/form-overlay';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldSet,
    FieldLegend,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
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
import { rates } from '@/routes/finance/city-ledger/companies';
import { destroy, store, update } from '@/routes/settings/rate-plans/companies';
import { update as updateRow } from '@/routes/settings/rate-plans/companies/rows';

type RatePlan = App.Data.Rates.RatePlanData;
type Access = App.Data.Rates.RatePlanCompaniesData;
type Row = App.Data.Rates.RatePlanCompanyRowData;
type Candidate = App.Data.Rates.RatePlanCompanyCandidateData;

const CHOICES: Record<string, Pick<Choice, 'icon' | 'description'>> = {
    everyone: {
        icon: Users,
        description:
            'A normal rate. Anyone at the front desk, never in the portal.',
    },
    all: {
        icon: Building2,
        description: 'Every company. Choose below where they can use it.',
    },
    selected: {
        icon: ListChecks,
        description:
            'Only the companies you add. Each has its own front desk and portal switches.',
    },
};

const WHERE = [
    { value: 'both', label: 'Front desk and portal' },
    { value: 'desk', label: 'Front desk only' },
    { value: 'portal', label: 'Portal only' },
];

function whereOf(access: Access): string {
    if (access.atDesk && access.inPortal) {
        return 'both';
    }

    return access.atDesk ? 'desk' : 'portal';
}

/**
 * Who can use the rate: Everyone, All companies (with where) or Only these companies (a table of rows). Everyone and
 * Only these companies save when picked; All companies saves with its Save button. Mount it with a `key` of the saved
 * choice so the picker follows the server.
 */
export function RatePlanCompaniesForm({
    ratePlan,
    access,
    accessOptions,
    candidates,
    canManageCompanies,
    canViewCompanies,
}: {
    ratePlan: RatePlan;
    access: Access;
    accessOptions: { value: string; label: string }[];
    candidates: Candidate[];
    canManageCompanies: boolean;
    canViewCompanies: boolean;
}) {
    const [choice, setChoice] = useState<string>(access.companyAccess);
    const form = useForm({
        company_access: 'all',
        where: whereOf(access),
    });
    const [adding, setAdding] = useState(false);
    const url = update.url({ ratePlan: ratePlan.id });

    const pick = (value: string) => {
        setChoice(value);

        if (value !== 'all' && value !== access.companyAccess) {
            router.put(
                url,
                { company_access: value },
                { preserveScroll: true },
            );
        }
    };

    const saveAll = () => {
        form.transform((data) => ({
            company_access: 'all',
            company_at_desk: data.where !== 'portal',
            company_in_portal: data.where !== 'desk',
        }));
        form.put(url, { preserveScroll: true });
    };

    return (
        <div className="flex flex-col gap-6">
            <ChoiceCards
                id="company-access"
                legend="Who can use this rate"
                choices={accessOptions.map((option) => ({
                    value: option.value,
                    label: option.label,
                    disabled: option.value === 'selected' && !access.isDerived,
                    ...CHOICES[option.value],
                }))}
                value={choice}
                onValueChange={pick}
                columns={3}
            />
            {!access.isDerived && (
                <p className="text-sm text-muted-foreground">
                    Only a derived plan can be limited to some companies.
                </p>
            )}

            {choice === 'all' && (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        saveAll();
                    }}
                    className="flex flex-col gap-4"
                >
                    <FieldSet>
                        <FieldLegend variant="label">Where</FieldLegend>
                        <RadioGroup
                            name="where"
                            value={form.data.where}
                            onValueChange={(value) =>
                                form.setData('where', String(value))
                            }
                            className="flex flex-wrap gap-4"
                        >
                            {WHERE.map((option) => (
                                <Field
                                    key={option.value}
                                    orientation="horizontal"
                                    className="w-fit"
                                >
                                    <RadioGroupItem
                                        value={option.value}
                                        id={`where-${option.value}`}
                                    />
                                    <FieldLabel
                                        htmlFor={`where-${option.value}`}
                                    >
                                        {option.label}
                                    </FieldLabel>
                                </Field>
                            ))}
                        </RadioGroup>
                        <FieldDescription>
                            At the front desk the rate shows only once a company
                            is picked on the booking. In the portal it shows to
                            every company with a portal login.
                        </FieldDescription>
                        <FieldError>
                            {
                                (form.errors as Record<string, string>)
                                    .company_access
                            }
                        </FieldError>
                    </FieldSet>
                    <footer className="flex justify-end">
                        <Button
                            type="submit"
                            disabled={form.processing}
                            focusableWhenDisabled
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Save
                        </Button>
                    </footer>
                </form>
            )}

            {choice === 'selected' && access.companyAccess === 'selected' && (
                <SelectedCompanies
                    ratePlan={ratePlan}
                    rows={access.companies}
                    canManageCompanies={canManageCompanies}
                    canViewCompanies={canViewCompanies}
                    onAdd={() => setAdding(true)}
                />
            )}

            {canManageCompanies && (
                <AddCompaniesDialog
                    ratePlan={ratePlan}
                    open={adding}
                    onOpenChange={setAdding}
                    candidates={candidates}
                />
            )}
        </div>
    );
}

function SelectedCompanies({
    ratePlan,
    rows,
    canManageCompanies,
    canViewCompanies,
    onAdd,
}: {
    ratePlan: RatePlan;
    rows: Row[];
    canManageCompanies: boolean;
    canViewCompanies: boolean;
    onAdd: () => void;
}) {
    const [removing, setRemoving] = useState<Row | null>(null);

    const setSwitch = (row: Row, field: 'atDesk' | 'inPortal', on: boolean) =>
        router.put(
            updateRow.url({ ratePlan: ratePlan.id, company: row.id }),
            {
                at_desk: field === 'atDesk' ? on : row.atDesk,
                in_portal: field === 'inPortal' ? on : row.inPortal,
            },
            { preserveScroll: true },
        );

    const addButton = canManageCompanies && (
        <Button onClick={onAdd}>
            <UserRound data-icon="inline-start" />
            Add companies
        </Button>
    );

    if (rows.length === 0) {
        return (
            <EmptyState
                icon={Building2}
                title="No company has this rate yet."
                description="Add the companies that can use it. Until then nobody can."
                action={addButton}
            />
        );
    }

    return (
        <section className="flex flex-col gap-3">
            <div className="flex items-center justify-between gap-3">
                <h2 className="text-sm font-medium">Companies</h2>
                {addButton}
            </div>
            <div className="overflow-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Company</TableHead>
                            <TableHead>Login</TableHead>
                            <TableHead>Front desk</TableHead>
                            <TableHead>Portal</TableHead>
                            <TableHead className="w-10">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={row.id}>
                                <TableCell className="font-medium">
                                    {canViewCompanies ? (
                                        <Link
                                            href={rates({ company: row.id })}
                                            className="inline-flex items-center gap-1 underline-offset-4 hover:underline"
                                        >
                                            {row.name}
                                            <ArrowUpRight
                                                className="size-3.5"
                                                aria-hidden
                                            />
                                        </Link>
                                    ) : (
                                        row.name
                                    )}
                                </TableCell>
                                <TableCell>
                                    {row.login ? (
                                        <StatusBadge status={row.login} />
                                    ) : (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell>
                                    <Switch
                                        size="sm"
                                        checked={row.atDesk}
                                        aria-label={`${row.name} at the front desk`}
                                        onCheckedChange={(on) =>
                                            setSwitch(row, 'atDesk', on)
                                        }
                                    />
                                </TableCell>
                                <TableCell>
                                    <Switch
                                        size="sm"
                                        checked={row.inPortal}
                                        aria-label={`${row.name} in the portal`}
                                        onCheckedChange={(on) =>
                                            setSwitch(row, 'inPortal', on)
                                        }
                                    />
                                </TableCell>
                                <TableCell>
                                    {canManageCompanies && (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger
                                                render={
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                    />
                                                }
                                                aria-label={`Actions for ${row.name}`}
                                            >
                                                <Ellipsis />
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onClick={() =>
                                                        setRemoving(row)
                                                    }
                                                >
                                                    <Trash2 />
                                                    Remove
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
            <RemoveCompanyDialog
                ratePlan={ratePlan}
                row={removing}
                onClose={() => setRemoving(null)}
            />
        </section>
    );
}

function RemoveCompanyDialog({
    ratePlan,
    row,
    onClose,
}: {
    ratePlan: RatePlan;
    row: Row | null;
    onClose: () => void;
}) {
    return (
        <AlertDialog
            open={row !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <AlertDialogContent>
                {row && (
                    <>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Remove {row.name} from this rate?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                Staff won't see "{ratePlan.name}" at the front
                                desk for this company
                                {row.inPortal && row.hasActiveLogin
                                    ? ", and the company can't book it in the portal"
                                    : ''}
                                . Bookings already made keep their price.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <Form
                            {...destroy.form({
                                ratePlan: ratePlan.id,
                                company: row.id,
                            })}
                            options={{ preserveScroll: true }}
                            onSuccess={onClose}
                        >
                            {({ processing }) => (
                                <AlertDialogFooter>
                                    <AlertDialogCancel>
                                        Cancel
                                    </AlertDialogCancel>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                        focusableWhenDisabled
                                    >
                                        {processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        Remove
                                    </Button>
                                </AlertDialogFooter>
                            )}
                        </Form>
                    </>
                )}
            </AlertDialogContent>
        </AlertDialog>
    );
}

function AddCompaniesDialog({
    ratePlan,
    open,
    onOpenChange,
    candidates,
}: {
    ratePlan: RatePlan;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    candidates: Candidate[];
}) {
    const form = useForm<{ company_ids: number[] }>({ company_ids: [] });
    const [search, setSearch] = useState('');
    const needle = search.trim().toLowerCase();
    const shown = candidates.filter((company) =>
        company.name.toLowerCase().includes(needle),
    );
    const errors = form.errors as Record<string, string | undefined>;

    const toggle = (id: number, checked: boolean) =>
        form.setData(
            'company_ids',
            checked
                ? [...form.data.company_ids, id]
                : form.data.company_ids.filter((one) => one !== id),
        );

    const close = (next: boolean) => {
        onOpenChange(next);

        if (!next) {
            form.reset();
            form.clearErrors();
            setSearch('');
        }
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={close}
            title="Add companies"
            description={`Active companies that can use "${ratePlan.name}". Both switches start on.`}
            errors={errors}
            onSubmit={() =>
                form.post(store.url({ ratePlan: ratePlan.id }), {
                    preserveScroll: true,
                    onSuccess: () => close(false),
                })
            }
            submitLabel="Add companies"
            processing={form.processing}
            submitDisabled={form.data.company_ids.length === 0}
        >
            <FieldGroup>
                <Field>
                    <FieldLabel htmlFor="add-company-search">Search</FieldLabel>
                    <Input
                        id="add-company-search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="e.g. Petronas"
                    />
                </Field>
                <Field data-invalid={!!errors.company_ids}>
                    {candidates.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Every Active company is already on this rate.
                        </p>
                    ) : shown.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No matching companies.
                        </p>
                    ) : (
                        <ul className="flex max-h-72 flex-col gap-1 overflow-y-auto">
                            {shown.map((company) => (
                                <li key={company.id}>
                                    <FieldLabel
                                        htmlFor={`add-company-${company.id}`}
                                        className="flex items-center gap-3 rounded-md px-2 py-1.5 hover:bg-muted"
                                    >
                                        <Checkbox
                                            id={`add-company-${company.id}`}
                                            checked={form.data.company_ids.includes(
                                                company.id,
                                            )}
                                            onCheckedChange={(checked) =>
                                                toggle(company.id, checked)
                                            }
                                        />
                                        <span className="flex-1 font-medium">
                                            {company.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {company.type}
                                        </span>
                                    </FieldLabel>
                                </li>
                            ))}
                        </ul>
                    )}
                    <FieldError>{errors.company_ids}</FieldError>
                </Field>
            </FieldGroup>
        </FormOverlay>
    );
}
