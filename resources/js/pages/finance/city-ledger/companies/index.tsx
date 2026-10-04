import { Head, Link } from '@inertiajs/react';
import { Building2, ChevronDown, Plus, Rows3 } from 'lucide-react';
import { useState } from 'react';
import { AddCompanySheet } from '@/components/pms/city-ledger/add-company-sheet';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index, show } from '@/routes/finance/city-ledger/companies';
import { create as bulkCreate } from '@/routes/finance/city-ledger/companies/bulk';

type Company = App.Data.CityLedger.CompanyRowData;

export default function CompaniesIndex({
    companies,
    types,
    statuses,
    currency,
    canManage,
}: {
    companies: Company[];
    types: EnumOption[];
    statuses: EnumOption[];
    currency: App.Enums.Currency | null;
    canManage: boolean;
}) {
    const [search, setSearch] = useState('');
    const [type, setType] = useState('');
    const [status, setStatus] = useState('active');
    const [adding, setAdding] = useState(false);

    const term = search.trim().toLowerCase();
    const visible = companies.filter(
        (company) =>
            (type === '' || company.type === type) &&
            (status === '' || company.status.value === status) &&
            (company.name.toLowerCase().includes(term) ||
                (company.contactEmail ?? '').includes(term)),
    );

    const clearFilters = () => {
        setSearch('');
        setType('');
        setStatus('active');
    };

    const addCompany = canManage && (
        <DropdownMenu>
            <DropdownMenuTrigger render={<Button />}>
                <Plus data-icon="inline-start" />
                Add company
                <ChevronDown data-icon="inline-end" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={() => setAdding(true)}>
                    <Plus />
                    Add one
                </DropdownMenuItem>
                <DropdownMenuItem render={<Link href={bulkCreate()} />}>
                    <Rows3 />
                    Bulk add
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );

    return (
        <>
            <Head title="Companies" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Companies"
                    description="Corporates, travel agents and government bodies that stay now and pay later."
                    actions={addCompany || undefined}
                />

                {companies.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title="No companies yet"
                        description="Add a company to bill its stays to the city ledger."
                        action={addCompany || undefined}
                    />
                ) : (
                    <>
                        <FilterBar
                            search={search}
                            onSearchChange={setSearch}
                            searchPlaceholder="Search companies"
                        >
                            <EnumSelect
                                options={types}
                                value={type}
                                onValueChange={setType}
                                allLabel="All types"
                                aria-label="Type"
                                className="w-40"
                            />
                            <EnumSelect
                                options={statuses}
                                value={status}
                                onValueChange={setStatus}
                                allLabel="All statuses"
                                aria-label="Status"
                                className="w-40"
                            />
                        </FilterBar>

                        <div className="overflow-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead className="text-right">
                                            Credit limit
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Terms
                                        </TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {visible.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={5}>
                                                <EmptyState
                                                    icon={Building2}
                                                    title="No companies match these filters"
                                                    action={
                                                        <Button
                                                            variant="outline"
                                                            onClick={
                                                                clearFilters
                                                            }
                                                        >
                                                            Clear filters
                                                        </Button>
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        visible.map((company) => (
                                            <TableRow key={company.id}>
                                                <TableCell>
                                                    <div className="flex flex-col">
                                                        <span className="flex items-center gap-2">
                                                            <Link
                                                                href={show({
                                                                    company:
                                                                        company.id,
                                                                })}
                                                                className="font-medium hover:underline"
                                                            >
                                                                {company.name}
                                                            </Link>
                                                            {company.overLimit && (
                                                                <StatusBadge
                                                                    status={{
                                                                        value: 'over_limit',
                                                                        label: 'Over limit',
                                                                        tone: 'danger',
                                                                    }}
                                                                />
                                                            )}
                                                        </span>
                                                        {company.contactEmail && (
                                                            <span className="text-xs text-muted-foreground">
                                                                {
                                                                    company.contactEmail
                                                                }
                                                            </span>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    {company.typeLabel}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {company.creditLimit ===
                                                    null ? (
                                                        <span className="text-muted-foreground">
                                                            No limit
                                                        </span>
                                                    ) : (
                                                        <MoneyText
                                                            amount={
                                                                company.creditLimit
                                                            }
                                                            currency={currency}
                                                        />
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {company.paymentTermsDays ===
                                                    null
                                                        ? '—'
                                                        : `${company.paymentTermsDays} d`}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        size="lg"
                                                        status={company.status}
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </>
                )}
            </div>

            {canManage && (
                <AddCompanySheet
                    open={adding}
                    onOpenChange={setAdding}
                    types={types}
                    currency={currency}
                />
            )}
        </>
    );
}

CompaniesIndex.layout = {
    breadcrumbs: [{ title: 'Companies', href: index() }],
};
