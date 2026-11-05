import { Head, Link, router } from '@inertiajs/react';
import type { RowData } from '@tanstack/react-table';
import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    Clock,
    Eye,
    FileCheck,
    FileText,
    Layers,
    QrCode,
    RefreshCw,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { DataTable, dataTableColumns } from '@/components/pms/data-table';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    cancel as cancelRoute,
    consolidate as consolidateRoute,
    index as indexRoute,
    refresh as refreshRoute,
    show as showRoute,
} from '@/routes/finance/e-invoices';

type SubmissionRow = RowData & {
    id: number;
    internal_id: string;
    uuid: string | null;
    submission_uid: string | null;
    document_type: string;
    document_type_label: string;
    document_type_short: string;
    scenario: string;
    scenario_label: string;
    status: string;
    status_label: string;
    status_tone: string;
    is_consolidated: boolean;
    requested_by_guest: boolean;
    is_cancellable: boolean;
    is_retryable: boolean;
    cancellation_deadline: string | null;
    validation_url: string | null;
    buyer_name: string;
    buyer_tin: string;
    submitted_at: string | null;
    validated_at: string | null;
    cancelled_at: string | null;
    created_at: string | null;
    errors: string[];
    folio_number: string | null;
    ar_invoice_number: string | null;
};

type Option = { value: string; label: string };

type Props = {
    submissions: Illuminate.LengthAwarePaginator<number, SubmissionRow>;
    filters: {
        status?: string;
        scenario?: string;
        search?: string;
    };
    sort: string;
    statusOptions: Option[];
    scenarioOptions: Option[];
    stats: {
        total: number;
        valid: number;
        invalid: number;
        pending: number;
    };
    setting: {
        enabled: boolean;
        environment: string;
        consolidation_enabled: boolean;
    };
};

function formatDateTime(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const column = dataTableColumns<SubmissionRow>();

export default function EInvoicesIndex({
    submissions,
    filters,
    sort,
    statusOptions,
    scenarioOptions,
    stats,
    setting,
}: Props) {
    const visit = (params: Record<string, any>) => {
        router.get(
            indexRoute(),
            {
                ...filters,
                sort,
                ...params,
            },
            { preserveState: true },
        );
    };
    const [cancellingSubmission, setCancellingSubmission] =
        useState<SubmissionRow | null>(null);
    const [cancelReason, setCancelReason] = useState('');
    const [submittingCancel, setSubmittingCancel] = useState(false);

    const [consolidateOpen, setConsolidateOpen] = useState(false);
    const [consolidateMonth, setConsolidateMonth] = useState('');
    const [consolidating, setConsolidating] = useState(false);

    const handleRefresh = (sub: SubmissionRow) => {
        router.post(
            refreshRoute({ submission: sub.id }),
            {},
            { preserveScroll: true },
        );
    };

    const handleCancelSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!cancellingSubmission || !cancelReason.trim()) return;

        setSubmittingCancel(true);
        router.post(
            cancelRoute({ submission: cancellingSubmission.id }),
            { reason: cancelReason },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSubmittingCancel(false);
                    setCancellingSubmission(null);
                    setCancelReason('');
                },
            },
        );
    };

    const handleConsolidateSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setConsolidating(true);
        router.post(
            consolidateRoute(),
            { month: consolidateMonth || undefined },
            {
                preserveScroll: true,
                onFinish: () => {
                    setConsolidating(false);
                    setConsolidateOpen(false);
                },
            },
        );
    };

    const columns = useMemo(
        () => [
            column.accessor('internal_id', {
                header: 'Invoice Code',
                cell: (info) => (
                    <div className="flex flex-col">
                        <Link
                            href={showRoute({
                                submission: info.row.original.id,
                            })}
                            className="font-medium text-foreground hover:underline"
                        >
                            {info.getValue()}
                        </Link>
                        {info.row.original.uuid && (
                            <span className="max-w-[140px] truncate font-mono text-[10px] text-muted-foreground">
                                {info.row.original.uuid}
                            </span>
                        )}
                    </div>
                ),
            }),
            column.accessor('document_type_short', {
                header: 'Type',
                cell: (info) => {
                    const type = info.row.original.document_type;
                    const variant =
                        type === '01'
                            ? 'default'
                            : type === '02'
                              ? 'secondary'
                              : 'outline';
                    return <Badge variant={variant}>{info.getValue()}</Badge>;
                },
            }),
            column.accessor('scenario_label', {
                header: 'Scenario',
                cell: (info) => (
                    <div className="flex items-center gap-1.5">
                        <span className="text-xs">{info.getValue()}</span>
                        {info.row.original.is_consolidated && (
                            <Badge
                                variant="outline"
                                className="bg-amber-500/10 text-[10px] text-amber-600 dark:text-amber-400"
                            >
                                Consolidated
                            </Badge>
                        )}
                        {info.row.original.requested_by_guest && (
                            <Badge
                                variant="outline"
                                className="text-[10px] text-primary"
                            >
                                Requested
                            </Badge>
                        )}
                    </div>
                ),
            }),
            column.accessor('buyer_name', {
                header: 'Buyer',
                cell: (info) => (
                    <div className="flex flex-col">
                        <span className="text-xs font-medium">
                            {info.getValue()}
                        </span>
                        <span className="text-[11px] text-muted-foreground">
                            TIN: {info.row.original.buyer_tin || '—'}
                        </span>
                    </div>
                ),
            }),
            column.accessor('status_label', {
                header: 'Status',
                cell: (info) => (
                    <div className="flex flex-col items-start gap-1">
                        <StatusBadge
                            status={{
                                value: info.row.original.status,
                                label: info.getValue(),
                                tone: info.row.original
                                    .status_tone as App.Enums.Tone,
                            }}
                        />
                        {info.row.original.errors.length > 0 && (
                            <span className="max-w-[180px] truncate text-[11px] text-destructive">
                                {info.row.original.errors[0]}
                            </span>
                        )}
                    </div>
                ),
            }),
            column.accessor('submitted_at', {
                header: 'Submitted',
                cell: (info) => (
                    <span className="text-xs tabular-nums">
                        {formatDateTime(info.getValue())}
                    </span>
                ),
            }),
            column.display({
                id: 'actions',
                header: '',
                cell: (info) => {
                    const sub = info.row.original;
                    return (
                        <div className="flex items-center justify-end gap-1.5">
                            {sub.validation_url && (
                                <a
                                    href={sub.validation_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    title="View LHDN Verification Portal"
                                    className={buttonVariants({
                                        variant: 'ghost',
                                        size: 'icon-sm',
                                    })}
                                >
                                    <QrCode className="size-3.5" />
                                </a>
                            )}

                            {(sub.status === 'submitted' ||
                                sub.status === 'pending') && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    title="Refresh status from LHDN"
                                    onClick={() => handleRefresh(sub)}
                                >
                                    <RefreshCw className="size-3.5" />
                                </Button>
                            )}

                            {sub.is_cancellable && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    className="text-destructive hover:text-destructive"
                                    title="Cancel on LHDN (within 72 hours)"
                                    onClick={() => setCancellingSubmission(sub)}
                                >
                                    <Ban className="size-3.5" />
                                </Button>
                            )}

                            <Link
                                href={showRoute({ submission: sub.id })}
                                className={buttonVariants({
                                    variant: 'ghost',
                                    size: 'icon-sm',
                                })}
                                title="Inspect details and payload"
                            >
                                <Eye className="size-3.5" />
                            </Link>
                        </div>
                    );
                },
            }),
        ],
        [],
    );

    return (
        <div className="space-y-6 px-4 py-6 sm:px-6">
            <Head title="E-Invoice Submissions (LHDN MyInvois)" />

            <PageHeader
                title="LHDN MyInvois Submissions"
                description="Live dashboard of electronic invoices, credit notes, and monthly consolidations submitted to the Malaysian Inland Revenue Board."
                actions={
                    <div className="flex items-center gap-2">
                        {setting.consolidation_enabled && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setConsolidateOpen(true)}
                            >
                                <Layers className="mr-1.5 size-4" />
                                Consolidate Month
                            </Button>
                        )}
                        <a
                            href="/admin/e-invoice-guide"
                            target="_blank"
                            rel="noreferrer"
                            className={buttonVariants({
                                variant: 'outline',
                                size: 'sm',
                            })}
                        >
                            <FileText className="mr-1.5 size-4" />
                            Official Guide
                        </a>
                    </div>
                }
            />

            {!setting.enabled && (
                <Alert variant="destructive">
                    <AlertTriangle className="size-4" />
                    <AlertTitle>E-Invoicing is Currently Inactive</AlertTitle>
                    <AlertDescription className="flex items-center justify-between">
                        <span>
                            E-invoicing submissions are disabled for this
                            property. Enable the service in Settings to submit
                            live documents.
                        </span>
                        <Link
                            href="/p/settings/e-invoice"
                            className="ml-4 font-medium underline hover:text-foreground"
                        >
                            Configure Settings
                        </Link>
                    </AlertDescription>
                </Alert>
            )}

            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <Card className="p-4">
                    <div className="text-xs font-medium text-muted-foreground">
                        Total Filings
                    </div>
                    <div className="mt-1 text-2xl font-bold">{stats.total}</div>
                </Card>
                <Card className="border-l-4 border-l-emerald-500 p-4">
                    <div className="flex items-center gap-1 text-xs font-medium text-muted-foreground">
                        <CheckCircle2 className="size-3 text-emerald-500" />{' '}
                        Valid
                    </div>
                    <div className="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        {stats.valid}
                    </div>
                </Card>
                <Card className="border-l-4 border-l-destructive p-4">
                    <div className="flex items-center gap-1 text-xs font-medium text-muted-foreground">
                        <XCircle className="size-3 text-destructive" /> Rejected
                    </div>
                    <div className="mt-1 text-2xl font-bold text-destructive">
                        {stats.invalid}
                    </div>
                </Card>
                <Card className="border-l-4 border-l-amber-500 p-4">
                    <div className="flex items-center gap-1 text-xs font-medium text-muted-foreground">
                        <Clock className="size-3 text-amber-500" /> Pending /
                        Sent
                    </div>
                    <div className="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400">
                        {stats.pending}
                    </div>
                </Card>
            </div>

            <FilterBar
                search={filters.search ?? ''}
                onSearchChange={(search) =>
                    router.get(
                        indexRoute(),
                        { ...filters, search: search || undefined },
                        { preserveState: true },
                    )
                }
                searchPlaceholder="Search invoice code or UUID..."
            >
                <EnumSelect
                    allLabel="All Statuses"
                    options={statusOptions}
                    value={filters.status ?? ''}
                    onValueChange={(val) =>
                        router.get(
                            indexRoute(),
                            { ...filters, status: val || undefined },
                            { preserveState: true },
                        )
                    }
                />

                <EnumSelect
                    allLabel="All Scenarios"
                    options={scenarioOptions}
                    value={filters.scenario ?? ''}
                    onValueChange={(val) =>
                        router.get(
                            indexRoute(),
                            { ...filters, scenario: val || undefined },
                            { preserveState: true },
                        )
                    }
                />
            </FilterBar>

            <DataTable<SubmissionRow>
                columns={columns}
                data={submissions.data}
                getRowId={(row) => String(row.id)}
                sort={sort || '-id'}
                onSortChange={(next) => visit({ sort: next })}
                onRowClick={(row) =>
                    router.visit(showRoute({ submission: row.id }))
                }
                pagination={{
                    currentPage: submissions.meta.current_page,
                    lastPage: submissions.meta.last_page,
                    from: submissions.meta.from ?? 0,
                    to: submissions.meta.to ?? 0,
                    total: submissions.meta.total,
                    prevUrl: submissions.meta.prev_page_url,
                    nextUrl: submissions.meta.next_page_url,
                }}
                empty={
                    <EmptyState
                        icon={FileCheck}
                        title="No e-invoice submissions found"
                        description="Closed folios and AR invoices submitted to LHDN will appear here with live validation states and QR codes."
                    />
                }
            />

            {/* Cancellation Reason Modal */}
            <Dialog
                open={cancellingSubmission !== null}
                onOpenChange={(open) => !open && setCancellingSubmission(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Cancel E-Invoice on LHDN MyInvois
                        </DialogTitle>
                        <DialogDescription>
                            LHDN accepts cancellation strictly within 72 hours
                            of validation. Enter the cancellation reason that
                            will be recorded in IRBM audit records.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleCancelSubmit} className="space-y-4">
                        <div className="space-y-1 rounded-md bg-muted/50 p-3 text-xs">
                            <div>
                                <span className="font-semibold">Invoice:</span>{' '}
                                {cancellingSubmission?.internal_id}
                            </div>
                            <div>
                                <span className="font-semibold">UUID:</span>{' '}
                                <span className="font-mono">
                                    {cancellingSubmission?.uuid}
                                </span>
                            </div>
                            <div>
                                <span className="font-semibold">
                                    72h Deadline:
                                </span>{' '}
                                {cancellingSubmission?.cancellation_deadline &&
                                    formatDateTime(
                                        cancellingSubmission.cancellation_deadline,
                                    )}
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <label
                                htmlFor="cancel_reason"
                                className="text-sm font-medium"
                            >
                                Cancellation Reason *
                            </label>
                            <Input
                                id="cancel_reason"
                                required
                                placeholder="e.g. Guest cancellation / incorrect billing name"
                                value={cancelReason}
                                onChange={(e) =>
                                    setCancelReason(e.target.value)
                                }
                            />
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCancellingSubmission(null)}
                                disabled={submittingCancel}
                            >
                                Back
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={
                                    submittingCancel || !cancelReason.trim()
                                }
                            >
                                {submittingCancel ? (
                                    <>
                                        <Spinner className="mr-1.5 size-4" />{' '}
                                        Cancelling...
                                    </>
                                ) : (
                                    'Confirm Cancellation'
                                )}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Manual Consolidation Modal */}
            <Dialog open={consolidateOpen} onOpenChange={setConsolidateOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Trigger Monthly Consolidation</DialogTitle>
                        <DialogDescription>
                            Aggregates all concluded B2C stays in the selected
                            month (excluding transactions over RM 10,000 or
                            already submitted) and files them under General
                            Public TIN EI00000000010.
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        onSubmit={handleConsolidateSubmit}
                        className="space-y-4"
                    >
                        <div className="space-y-1.5">
                            <label
                                htmlFor="consolidate_month"
                                className="text-sm font-medium"
                            >
                                Target Month (YYYY-MM)
                            </label>
                            <Input
                                id="consolidate_month"
                                type="month"
                                value={consolidateMonth}
                                onChange={(e) =>
                                    setConsolidateMonth(e.target.value)
                                }
                                placeholder="Leave empty for previous month"
                            />
                            <p className="text-xs text-muted-foreground">
                                Leave blank to automatically consolidate the
                                previous calendar month.
                            </p>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setConsolidateOpen(false)}
                                disabled={consolidating}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={consolidating}>
                                {consolidating ? (
                                    <>
                                        <Spinner className="mr-1.5 size-4" />{' '}
                                        Consolidating...
                                    </>
                                ) : (
                                    'File Consolidated Invoice'
                                )}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
