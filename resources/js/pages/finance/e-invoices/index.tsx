import { Head, Link, router, useForm } from '@inertiajs/react';
import { CircleAlert, FileCheck, Layers } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Alert,
    AlertAction,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { consolidate, index, show } from '@/routes/finance/e-invoices';
import { folio as folioTab } from '@/routes/reservations';

type Row = {
    id: number;
    number: string;
    kind: { value: string; label: string };
    billed_to: string;
    source: string | null;
    amount: string | null;
    date: string | null;
    status: { value: string; label: string; tone: App.Enums.Tone };
};

type Filters = { search: string; type: string; status: string };

type Props = {
    submissions: Illuminate.LengthAwarePaginator<number, Row>;
    filters: Filters;
    types: EnumOption[];
    statuses: EnumOption[];
    rejected: number;
    needOwnEInvoice: {
        folio_id: number;
        reservation_id: number | null;
        reference: string | null;
        guest: string | null;
        amount: string;
        closed_on: string | null;
    }[];
    canRunSummary: boolean;
    currency: App.Data.Property.CurrencyData | null;
};

const column = dataTableColumns<Row>();

function lastMonth(): string {
    const date = new Date();
    date.setDate(1);
    date.setMonth(date.getMonth() - 1);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function RunSummaryForm({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ month: lastMonth() });

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Send monthly summary"
            description="Sends one e-invoice to LHDN for every stay in the month whose guest did not ask for their own e-invoice. Stays over RM 10,000 are left out and need their own e-invoice. This runs by itself on the 1st of each month."
            errors={form.errors}
            onSubmit={() =>
                form.post(consolidate.url(), {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                })
            }
            submitLabel="Send to LHDN"
            processing={form.processing}
        >
            <Field data-invalid={!!form.errors.month}>
                <FieldLabel htmlFor="summary-month">Month</FieldLabel>
                <Input
                    id="summary-month"
                    type="month"
                    value={form.data.month}
                    onChange={(event) =>
                        form.setData('month', event.target.value)
                    }
                    aria-invalid={!!form.errors.month}
                />
                <FieldDescription>
                    Stays already in a summary are not sent twice.
                </FieldDescription>
                <FieldError>{form.errors.month}</FieldError>
            </Field>
        </FormOverlay>
    );
}

function RunSummaryDialog(props: Parameters<typeof RunSummaryForm>[0]) {
    return <RunSummaryForm key={useFreshOnOpen(props.open)} {...props} />;
}

export default function EInvoicesIndex({
    submissions,
    filters,
    types,
    statuses,
    rejected,
    needOwnEInvoice,
    canRunSummary,
    currency,
}: Props) {
    const [runningSummary, setRunningSummary] = useState(false);

    const columns = useMemo(
        () => [
            column.accessor('number', {
                header: 'Number',
                enableSorting: false,
                cell: (info) => (
                    <span className="font-medium group-hover/row:underline">
                        {info.getValue()}
                    </span>
                ),
            }),
            column.accessor('billed_to', {
                header: 'Billed to',
                enableSorting: false,
            }),
            column.accessor('kind', {
                header: 'Type',
                enableSorting: false,
                cell: (info) => info.getValue().label,
            }),
            column.accessor('source', {
                header: 'Reservation or invoice',
                enableSorting: false,
                cell: (info) => info.getValue() ?? '—',
            }),
            column.accessor('date', {
                header: 'Date',
                enableSorting: false,
                cell: (info) => <DateText date={info.getValue()} />,
            }),
            column.accessor('amount', {
                header: 'Amount',
                enableSorting: false,
                cell: (info) =>
                    info.getValue() === null ? (
                        '—'
                    ) : (
                        <MoneyText
                            amount={info.getValue() ?? '0.00'}
                            currency={currency}
                        />
                    ),
            }),
            column.accessor('status', {
                header: 'Status',
                enableSorting: false,
                cell: (info) => <StatusBadge status={info.getValue()} />,
            }),
        ],
        [currency],
    );

    const visit = useCallback(
        (changes: Partial<Filters>) => {
            const next = { ...filters, ...changes };
            const query = Object.fromEntries(
                Object.entries(next).filter(([, value]) => value !== ''),
            );

            router.get(
                index.url(undefined, { query }),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [filters],
    );

    const hasFilters = Boolean(
        filters.search || filters.type || filters.status,
    );

    return (
        <>
            <Head title="E-invoices" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="E-invoices"
                    description="What the property has filed with LHDN MyInvois: e-invoices for guests and companies, credit notes and monthly summaries."
                    actions={
                        canRunSummary && (
                            <Button
                                variant="outline"
                                onClick={() => setRunningSummary(true)}
                            >
                                <Layers data-icon="inline-start" />
                                Send monthly summary
                            </Button>
                        )
                    }
                />

                {rejected > 0 && filters.status !== 'invalid' && (
                    <Alert variant="destructive">
                        <CircleAlert />
                        <AlertTitle>
                            {rejected === 1
                                ? '1 e-invoice was rejected by LHDN'
                                : `${rejected} e-invoices were rejected by LHDN`}
                        </AlertTitle>
                        <AlertDescription>
                            Rejected e-invoices are not filed. Open each one to
                            see what to fix.
                        </AlertDescription>
                        <AlertAction>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => visit({ status: 'invalid' })}
                            >
                                Show rejected
                            </Button>
                        </AlertAction>
                    </Alert>
                )}

                {needOwnEInvoice.length > 0 && (
                    <Alert variant="destructive">
                        <CircleAlert />
                        <AlertTitle>
                            {needOwnEInvoice.length === 1
                                ? '1 stay over RM10,000 needs its own e-invoice'
                                : `${needOwnEInvoice.length} stays over RM10,000 need their own e-invoice`}
                        </AlertTitle>
                        <AlertDescription className="flex flex-col gap-2">
                            <p>
                                LHDN does not allow a sale over RM10,000 in the
                                monthly summary. Issue each one with the guest's
                                own tax number.
                            </p>
                            <ul className="flex flex-col gap-1">
                                {needOwnEInvoice.map((stay) => (
                                    <li key={stay.folio_id}>
                                        {stay.reservation_id !== null ? (
                                            <Link
                                                href={folioTab.url(
                                                    {
                                                        reservation:
                                                            stay.reservation_id,
                                                    },
                                                    {
                                                        query: {
                                                            folio: String(
                                                                stay.folio_id,
                                                            ),
                                                            e_invoice: 'fix',
                                                        },
                                                    },
                                                )}
                                                className="font-medium underline underline-offset-2"
                                            >
                                                {stay.reference ?? 'Stay'}
                                            </Link>
                                        ) : (
                                            <span className="font-medium">
                                                Stay
                                            </span>
                                        )}
                                        {stay.guest && ` · ${stay.guest}`} ·{' '}
                                        <MoneyText
                                            amount={stay.amount}
                                            currency={currency}
                                        />
                                    </li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                <FilterBar
                    search={filters.search}
                    onSearchChange={(search) => visit({ search })}
                    searchPlaceholder="Search number, guest, company or reservation"
                >
                    <EnumSelect
                        options={types}
                        value={filters.type}
                        onValueChange={(type) => visit({ type })}
                        allLabel="All types"
                        aria-label="Type"
                        className="w-44"
                    />
                    <EnumSelect
                        options={statuses}
                        value={filters.status}
                        onValueChange={(status) => visit({ status })}
                        allLabel="All statuses"
                        aria-label="Status"
                        className="w-44"
                    />
                </FilterBar>

                <DataTable
                    columns={columns}
                    data={submissions.data}
                    getRowId={(row) => String(row.id)}
                    sort=""
                    onSortChange={() => undefined}
                    onRowClick={(row) =>
                        router.visit(show.url({ submission: row.id }))
                    }
                    pagination={{
                        currentPage: submissions.meta.current_page,
                        lastPage: submissions.meta.last_page,
                        from: submissions.meta.from,
                        to: submissions.meta.to,
                        total: submissions.meta.total,
                        prevUrl: submissions.meta.prev_page_url,
                        nextUrl: submissions.meta.next_page_url,
                    }}
                    empty={
                        hasFilters ? (
                            <EmptyState
                                icon={FileCheck}
                                title="No e-invoices match these filters"
                                action={
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            visit({
                                                search: '',
                                                type: '',
                                                status: '',
                                            })
                                        }
                                    >
                                        Clear filters
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={FileCheck}
                                title="No e-invoices yet"
                                description="Issue one from a closed folio, or wait for the monthly summary on the 1st."
                            />
                        )
                    }
                />
            </div>

            {canRunSummary && (
                <RunSummaryDialog
                    open={runningSummary}
                    onOpenChange={setRunningSummary}
                />
            )}
        </>
    );
}

EInvoicesIndex.layout = {
    breadcrumbs: [{ title: 'E-invoices', href: index() }],
};
