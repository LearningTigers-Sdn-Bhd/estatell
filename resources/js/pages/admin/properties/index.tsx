import { Form, Head, router } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';
import { useCallback, useState } from 'react';
import PasswordInput from '@/components/password-input';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { dashboard } from '@/routes/admin';
import { index, show, store } from '@/routes/admin/properties';

type Row = App.Data.Platform.AdminPropertyRowData;
type Filters = { search: string; status: string; type: string };
type SetupMethod = EnumOption & { description: string };

type Props = {
    properties: Illuminate.LengthAwarePaginator<number, Row>;
    filters: Filters;
    sort: string;
    statuses: EnumOption[];
    types: EnumOption[];
    setupMethods: SetupMethod[];
    passwordRules: string;
};

const column = dataTableColumns<Row>();

const columns = [
    column.accessor('code', {
        header: 'Code',
        cell: (info) => <span className="tabular-nums">{info.getValue()}</span>,
    }),
    column.accessor('name', {
        header: 'Name',
        cell: (info) => <span className="font-medium">{info.getValue()}</span>,
    }),
    column.accessor('status', {
        header: 'Status',
        enableSorting: false,
        cell: (info) => <StatusBadge status={info.getValue()} />,
    }),
    column.accessor('type', {
        header: 'Type',
        enableSorting: false,
        cell: (info) => info.getValue() ?? '—',
    }),
    column.accessor('city', {
        header: 'City',
        enableSorting: false,
        cell: (info) => info.getValue() ?? '—',
    }),
    column.accessor('ownerName', {
        header: 'Owner',
        enableSorting: false,
        cell: (info) =>
            info.getValue() ? (
                <span className="flex min-w-0 flex-col">
                    <span className="truncate">{info.getValue()}</span>
                    <span className="truncate text-xs text-muted-foreground">
                        {info.row.original.ownerEmail}
                    </span>
                </span>
            ) : (
                <span className="text-muted-foreground">No owner</span>
            ),
    }),
    column.accessor('createdAt', {
        id: 'created_at',
        header: 'Created',
        cell: (info) => <DateText date={info.getValue()} />,
    }),
];

function CreatePropertySheet({
    types,
    setupMethods,
    passwordRules,
}: {
    types: EnumOption[];
    setupMethods: SetupMethod[];
    passwordRules: string;
}) {
    const [open, setOpen] = useState(false);
    const [setupMethod, setSetupMethod] = useState(
        setupMethods[0]?.value ?? 'email_link',
    );

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger render={<Button />}>
                <Plus data-icon="inline-start" />
                Create property
            </SheetTrigger>
            <SheetContent className="sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>Create property</SheetTitle>
                    <SheetDescription>
                        Create the property and its owner account together.
                    </SheetDescription>
                </SheetHeader>

                <Form
                    {...store.form()}
                    className="flex min-h-0 flex-1 flex-col"
                    resetOnSuccess
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing, errors }) => (
                        <>
                            <FieldGroup className="overflow-y-auto px-4 pb-4">
                                <Field data-invalid={!!errors.property_name}>
                                    <FieldLabel htmlFor="property_name">
                                        Property name
                                    </FieldLabel>
                                    <Input
                                        id="property_name"
                                        name="property_name"
                                        autoComplete="organization"
                                        autoFocus
                                        aria-invalid={!!errors.property_name}
                                    />
                                    <FieldError>
                                        {errors.property_name}
                                    </FieldError>
                                </Field>

                                <Field data-invalid={!!errors.type}>
                                    <FieldLabel htmlFor="type">
                                        Property type
                                    </FieldLabel>
                                    <Select name="type" items={types}>
                                        <SelectTrigger
                                            id="type"
                                            className="w-full"
                                            aria-invalid={!!errors.type}
                                        >
                                            <SelectValue placeholder="Select a type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                {types.map((option) => (
                                                    <SelectItem
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectGroup>
                                        </SelectContent>
                                    </Select>
                                    <FieldError>{errors.type}</FieldError>
                                </Field>

                                <Field data-invalid={!!errors.owner_name}>
                                    <FieldLabel htmlFor="owner_name">
                                        Owner name
                                    </FieldLabel>
                                    <Input
                                        id="owner_name"
                                        name="owner_name"
                                        autoComplete="name"
                                        aria-invalid={!!errors.owner_name}
                                    />
                                    <FieldError>{errors.owner_name}</FieldError>
                                </Field>

                                <Field data-invalid={!!errors.owner_email}>
                                    <FieldLabel htmlFor="owner_email">
                                        Owner email
                                    </FieldLabel>
                                    <Input
                                        id="owner_email"
                                        name="owner_email"
                                        type="email"
                                        autoComplete="email"
                                        aria-invalid={!!errors.owner_email}
                                    />
                                    <FieldError>
                                        {errors.owner_email}
                                    </FieldError>
                                </Field>

                                <FieldSet>
                                    <FieldLegend>Account setup</FieldLegend>
                                    <RadioGroup
                                        name="setup_method"
                                        value={setupMethod}
                                        onValueChange={setSetupMethod}
                                    >
                                        {setupMethods.map((option) => (
                                            <FieldLabel
                                                key={option.value}
                                                htmlFor={`setup_method_${option.value}`}
                                            >
                                                <Field orientation="horizontal">
                                                    <RadioGroupItem
                                                        id={`setup_method_${option.value}`}
                                                        value={option.value}
                                                    />
                                                    <FieldContent>
                                                        <span className="text-sm font-medium">
                                                            {option.label}
                                                        </span>
                                                        <FieldDescription>
                                                            {option.description}
                                                        </FieldDescription>
                                                    </FieldContent>
                                                </Field>
                                            </FieldLabel>
                                        ))}
                                    </RadioGroup>
                                    <FieldError>
                                        {errors.setup_method}
                                    </FieldError>
                                </FieldSet>

                                {setupMethod === 'initial_password' && (
                                    <>
                                        <Field data-invalid={!!errors.password}>
                                            <FieldLabel htmlFor="password">
                                                Initial password
                                            </FieldLabel>
                                            <PasswordInput
                                                id="password"
                                                name="password"
                                                autoComplete="new-password"
                                                passwordrules={passwordRules}
                                                aria-invalid={!!errors.password}
                                            />
                                            <FieldDescription>
                                                Share this password with the
                                                owner separately. It will not be
                                                emailed.
                                            </FieldDescription>
                                            <FieldError>
                                                {errors.password}
                                            </FieldError>
                                        </Field>

                                        <Field>
                                            <FieldLabel htmlFor="password_confirmation">
                                                Confirm initial password
                                            </FieldLabel>
                                            <PasswordInput
                                                id="password_confirmation"
                                                name="password_confirmation"
                                                autoComplete="new-password"
                                                passwordrules={passwordRules}
                                            />
                                        </Field>

                                        <Field
                                            orientation="horizontal"
                                            data-invalid={
                                                !!errors.verify_owner_email
                                            }
                                        >
                                            <FieldContent>
                                                <FieldLabel htmlFor="verify_owner_email">
                                                    Mark owner email as verified
                                                </FieldLabel>
                                                <FieldDescription>
                                                    Use only after confirming
                                                    that the owner controls this
                                                    address. No verification
                                                    email will be sent.
                                                </FieldDescription>
                                                <FieldError>
                                                    {errors.verify_owner_email}
                                                </FieldError>
                                            </FieldContent>
                                            <Switch
                                                id="verify_owner_email"
                                                name="verify_owner_email"
                                            />
                                        </Field>
                                    </>
                                )}
                            </FieldGroup>

                            <SheetFooter>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Create property
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}

export default function PropertiesIndex({
    properties,
    filters,
    sort,
    statuses,
    types,
    setupMethods,
    passwordRules,
}: Props) {
    const visit = useCallback(
        (changes: Partial<Filters & { sort: string }>) => {
            const next = { ...filters, sort, ...changes };
            const filter = Object.fromEntries(
                (['search', 'status', 'type'] as const)
                    .filter((key) => next[key] !== '')
                    .map((key) => [key, next[key]]),
            );

            router.get(
                index.url({
                    query: {
                        ...(Object.keys(filter).length ? { filter } : {}),
                        ...(next.sort && next.sort !== '-created_at'
                            ? { sort: next.sort }
                            : {}),
                    },
                }),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [filters, sort],
    );

    const hasFilters = Boolean(
        filters.search || filters.status || filters.type,
    );

    return (
        <>
            <Head title="Properties" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Properties"
                    actions={
                        <CreatePropertySheet
                            types={types}
                            setupMethods={setupMethods}
                            passwordRules={passwordRules}
                        />
                    }
                />

                <FilterBar
                    search={filters.search}
                    onSearchChange={(search) => visit({ search })}
                    searchPlaceholder="Search code, name, city"
                >
                    <EnumSelect
                        options={statuses}
                        value={filters.status}
                        onValueChange={(status) => visit({ status })}
                        allLabel="All statuses"
                        aria-label="Status"
                        className="w-44"
                    />
                    <EnumSelect
                        options={types}
                        value={filters.type}
                        onValueChange={(type) => visit({ type })}
                        allLabel="All types"
                        aria-label="Type"
                        className="w-48"
                    />
                </FilterBar>

                <DataTable
                    columns={columns}
                    data={properties.data}
                    getRowId={(row) => String(row.code)}
                    sort={sort}
                    onSortChange={(next) => visit({ sort: next })}
                    onRowClick={(row) => router.visit(show(row.code))}
                    pagination={{
                        currentPage: properties.meta.current_page,
                        lastPage: properties.meta.last_page,
                        from: properties.meta.from,
                        to: properties.meta.to,
                        total: properties.meta.total,
                        prevUrl: properties.meta.prev_page_url,
                        nextUrl: properties.meta.next_page_url,
                    }}
                    empty={
                        hasFilters ? (
                            <EmptyState
                                icon={Building2}
                                title="No properties match these filters"
                                action={
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            visit({
                                                search: '',
                                                status: '',
                                                type: '',
                                            })
                                        }
                                    >
                                        Clear filters
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={Building2}
                                title="No properties yet"
                            />
                        )
                    }
                />
            </div>
        </>
    );
}

PropertiesIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Properties', href: index() },
    ],
};
