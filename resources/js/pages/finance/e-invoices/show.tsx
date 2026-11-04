import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    Ban,
    CheckCircle2,
    ChevronDown,
    Copy,
    FileCheck,
    QrCode,
    RefreshCw,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
    creditNote as creditNoteRoute,
    index as indexRoute,
    refresh as refreshRoute,
    show as showRoute,
} from '@/routes/finance/e-invoices';

type SubmissionDetail = {
    id: number;
    internal_id: string;
    uuid: string | null;
    submission_uid: string | null;
    long_id: string | null;
    document_type: string;
    document_type_label: string;
    scenario: string;
    scenario_label: string;
    status: string;
    status_label: string;
    status_tone: string;
    is_consolidated: boolean;
    requested_by_guest: boolean;
    cancel_reason: string | null;
    is_cancellable: boolean;
    cancellation_deadline: string | null;
    validation_url: string | null;
    buyer_snapshot: Record<string, any> | null;
    document_payload: Record<string, any> | null;
    raw_response: Record<string, any> | null;
    error_details: Record<string, any> | null;
    error_messages: string[];
    submitted_at: string | null;
    validated_at: string | null;
    cancelled_at: string | null;
    created_at: string | null;
    folio_number: string | null;
    ar_invoice_number: string | null;
    original_submission: {
        id: number;
        internal_id: string;
        uuid: string | null;
    } | null;
    adjustments: Array<{
        id: number;
        internal_id: string;
        uuid: string | null;
        status: string;
        status_label: string;
    }>;
};

type Props = {
    submission: SubmissionDetail;
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

export default function EInvoiceShow({ submission }: Props) {
    const [refreshing, setRefreshing] = useState(false);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [cancelReason, setCancelReason] = useState('');
    const [cancelling, setCancelling] = useState(false);

    const [creditNoteOpen, setCreditNoteOpen] = useState(false);
    const [creditNoteNumber, setCreditNoteNumber] = useState(
        `CN-${submission.internal_id}`,
    );
    const [creditNoteAmount, setCreditNoteAmount] = useState('');
    const [creditNoteReason, setCreditNoteReason] = useState('');
    const [submittingCreditNote, setSubmittingCreditNote] = useState(false);

    const [copied, setCopied] = useState(false);

    const handleRefresh = () => {
        setRefreshing(true);
        router.post(
            refreshRoute({ submission: submission.id }),
            {},
            {
                preserveScroll: true,
                onFinish: () => setRefreshing(false),
            },
        );
    };

    const handleCancelSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setCancelling(true);
        router.post(
            cancelRoute({ submission: submission.id }),
            { reason: cancelReason },
            {
                preserveScroll: true,
                onFinish: () => {
                    setCancelling(false);
                    setCancelOpen(false);
                },
            },
        );
    };

    const handleCreditNoteSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setSubmittingCreditNote(true);
        router.post(
            creditNoteRoute({ submission: submission.id }),
            {
                credit_note_number: creditNoteNumber,
                amount: creditNoteAmount,
                reason: creditNoteReason,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSubmittingCreditNote(false);
                    setCreditNoteOpen(false);
                },
            },
        );
    };

    const handleCopyPayload = () => {
        if (!submission.document_payload) return;
        void navigator.clipboard.writeText(
            JSON.stringify(submission.document_payload, null, 2),
        );
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <div className="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6">
            <Head title={`E-Invoice ${submission.internal_id}`} />

            <PageHeader
                title={`E-Invoice: ${submission.internal_id}`}
                description={`${submission.document_type_label} • ${submission.scenario_label}`}
                back={{ href: indexRoute(), label: 'All Submissions' }}
                actions={
                    <div className="flex items-center gap-2">
                        {submission.validation_url && (
                            <a
                                href={submission.validation_url}
                                target="_blank"
                                rel="noreferrer"
                                className={buttonVariants({
                                    variant: 'outline',
                                    size: 'sm',
                                })}
                            >
                                <QrCode className="mr-1.5 size-4" />
                                LHDN Verification Portal
                                <ArrowUpRight className="ml-1 size-3.5" />
                            </a>
                        )}

                        {(submission.status === 'submitted' ||
                            submission.status === 'pending') && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={handleRefresh}
                                disabled={refreshing}
                            >
                                <RefreshCw
                                    className={`mr-1.5 size-4 ${refreshing ? 'animate-spin' : ''}`}
                                />
                                Refresh LHDN Status
                            </Button>
                        )}

                        {submission.is_cancellable && (
                            <Button
                                variant="destructive"
                                size="sm"
                                onClick={() => setCancelOpen(true)}
                            >
                                <Ban className="mr-1.5 size-4" />
                                Cancel on LHDN
                            </Button>
                        )}

                        {submission.status === 'valid' && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setCreditNoteOpen(true)}
                            >
                                <FileCheck className="mr-1.5 size-4" />
                                Issue Credit Note (02)
                            </Button>
                        )}
                    </div>
                }
            />

            {/* Status alerts */}
            {submission.status === 'valid' && (
                <Alert className="border-emerald-500 bg-emerald-500/10 text-emerald-800 dark:text-emerald-300">
                    <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-400" />
                    <AlertTitle>
                        Validated & Stamped by LHDN MyInvois
                    </AlertTitle>
                    <AlertDescription className="text-xs">
                        This document has been cryptographically validated and
                        accepted into the IRBM Continuous Transaction Control
                        registry under UUID{' '}
                        <span className="font-mono font-medium">
                            {submission.uuid}
                        </span>
                        .
                        {submission.cancellation_deadline &&
                            submission.is_cancellable && (
                                <span className="mt-1 block text-muted-foreground">
                                    72-hour cancellation window is open until{' '}
                                    {formatDateTime(
                                        submission.cancellation_deadline,
                                    )}
                                    .
                                </span>
                            )}
                    </AlertDescription>
                </Alert>
            )}

            {submission.status === 'invalid' && (
                <Alert variant="destructive">
                    <XCircle className="size-4" />
                    <AlertTitle>Validation Rejected by LHDN</AlertTitle>
                    <AlertDescription className="space-y-1 text-xs">
                        <p>
                            LHDN asynchronous validation rejected this document:
                        </p>
                        {submission.error_messages.map((err, i) => (
                            <p key={i} className="font-semibold">
                                • {err}
                            </p>
                        ))}
                    </AlertDescription>
                </Alert>
            )}

            {submission.status === 'cancelled' && (
                <Alert className="border-muted bg-muted/40">
                    <Ban className="size-4 text-muted-foreground" />
                    <AlertTitle>Document Cancelled</AlertTitle>
                    <AlertDescription className="text-xs text-muted-foreground">
                        This submission was cancelled on LHDN MyInvois on{' '}
                        {submission.cancelled_at &&
                            formatDateTime(submission.cancelled_at)}
                        . Reason: "{submission.cancel_reason || 'Not specified'}
                        ".
                    </AlertDescription>
                </Alert>
            )}

            <div className="grid gap-6 md:grid-cols-2">
                {/* Document Summary Card */}
                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">
                                E-Invoice Summary
                            </CardTitle>
                            <StatusBadge
                                status={{
                                    value: submission.status,
                                    label: submission.status_label,
                                    tone: submission.status_tone as App.Enums.Tone,
                                }}
                            />
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-3 text-xs">
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="text-muted-foreground">
                                Internal Code
                            </span>
                            <span className="font-semibold">
                                {submission.internal_id}
                            </span>
                        </div>
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="text-muted-foreground">
                                Document Type
                            </span>
                            <Badge variant="outline">
                                {submission.document_type_label}
                            </Badge>
                        </div>
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="text-muted-foreground">
                                Submission Mode
                            </span>
                            <span>{submission.scenario_label}</span>
                        </div>
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="text-muted-foreground">
                                Source Reference
                            </span>
                            <span className="font-medium">
                                {submission.folio_number
                                    ? `Folio ${submission.folio_number}`
                                    : submission.ar_invoice_number
                                      ? `Invoice ${submission.ar_invoice_number}`
                                      : 'Consolidated Batch'}
                            </span>
                        </div>
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="text-muted-foreground">
                                Submitted At
                            </span>
                            <span>
                                {formatDateTime(submission.submitted_at)}
                            </span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-muted-foreground">
                                Validated At
                            </span>
                            <span>
                                {formatDateTime(submission.validated_at)}
                            </span>
                        </div>
                    </CardContent>
                </Card>

                {/* Buyer Profile Card */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">
                            Buyer Party Profile
                        </CardTitle>
                        <CardDescription>
                            Identity snapshot embedded in UBL
                            AccountingCustomerParty.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3 text-xs">
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="text-muted-foreground">
                                Buyer Name
                            </span>
                            <span className="font-semibold">
                                {submission.buyer_snapshot?.name ||
                                    'General Public'}
                            </span>
                        </div>
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="text-muted-foreground">
                                Buyer TIN
                            </span>
                            <span className="font-mono font-medium">
                                {submission.buyer_snapshot?.tin || '—'}
                            </span>
                        </div>
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="text-muted-foreground">
                                Identification (
                                {submission.buyer_snapshot?.id_type || 'ID'})
                            </span>
                            <span className="font-mono">
                                {submission.buyer_snapshot?.id_value || 'NA'}
                            </span>
                        </div>
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="text-muted-foreground">
                                State Code
                            </span>
                            <span>
                                {submission.buyer_snapshot?.state_code ||
                                    submission.buyer_snapshot?.billing_address
                                        ?.state_code ||
                                    '17 (NA)'}
                            </span>
                        </div>
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="text-muted-foreground">
                                Contact Email
                            </span>
                            <span>
                                {submission.buyer_snapshot?.email ||
                                    submission.buyer_snapshot?.contact_email ||
                                    '—'}
                            </span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-muted-foreground">
                                Contact Phone
                            </span>
                            <span>
                                {submission.buyer_snapshot?.phone ||
                                    submission.buyer_snapshot?.contact_phone ||
                                    '—'}
                            </span>
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Related Submissions & Adjustments */}
            {(submission.original_submission ||
                submission.adjustments.length > 0) && (
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">
                            Linked Submissions & Billing References
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-xs">
                        {submission.original_submission && (
                            <div className="flex items-center justify-between border-b pb-2">
                                <span className="text-muted-foreground">
                                    Original Invoice
                                </span>
                                <Link
                                    href={showRoute({
                                        submission:
                                            submission.original_submission.id,
                                    })}
                                    className="font-medium text-primary hover:underline"
                                >
                                    {submission.original_submission.internal_id}{' '}
                                    ({submission.original_submission.uuid})
                                </Link>
                            </div>
                        )}

                        {submission.adjustments.map((adj) => (
                            <div
                                key={adj.id}
                                className="flex items-center justify-between border-b pb-2"
                            >
                                <span className="text-muted-foreground">
                                    Credit Note / Adjustment
                                </span>
                                <Link
                                    href={showRoute({ submission: adj.id })}
                                    className="font-medium text-primary hover:underline"
                                >
                                    {adj.internal_id} ({adj.status_label})
                                </Link>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            )}

            {/* Technical Details & UBL 2.1 Payload (Collapsible) */}
            <Collapsible className="rounded-lg border bg-card text-card-foreground shadow-sm">
                <CollapsibleTrigger className="group flex w-full items-center justify-between p-4 text-left transition-colors hover:bg-muted/50">
                    <div className="flex flex-col gap-0.5">
                        <span className="text-sm font-semibold">
                            Technical details & UBL 2.1 payload
                        </span>
                        <span className="text-xs text-muted-foreground">
                            LHDN UUID, Submission UID, cryptographic hashes, and
                            raw gateway JSON
                        </span>
                    </div>
                    <ChevronDown className="size-4 shrink-0 transition-transform group-aria-expanded:rotate-180" />
                </CollapsibleTrigger>
                <CollapsibleContent className="space-y-4 border-t p-4">
                    {/* Technical Identifiers */}
                    <div className="grid gap-3 text-xs sm:grid-cols-2">
                        <div className="flex flex-col gap-1 rounded border bg-muted/30 p-2.5">
                            <span className="text-muted-foreground">
                                Document UUID
                            </span>
                            <span className="font-mono font-medium select-all">
                                {submission.uuid || '—'}
                            </span>
                        </div>
                        <div className="flex flex-col gap-1 rounded border bg-muted/30 p-2.5">
                            <span className="text-muted-foreground">
                                Submission UID
                            </span>
                            <span className="font-mono font-medium select-all">
                                {submission.submission_uid || '—'}
                            </span>
                        </div>
                        {submission.long_id && (
                            <div className="flex flex-col gap-1 rounded border bg-muted/30 p-2.5 sm:col-span-2">
                                <span className="text-muted-foreground">
                                    Long ID (Digest Hash)
                                </span>
                                <span className="font-mono text-xs break-all select-all">
                                    {submission.long_id}
                                </span>
                            </div>
                        )}
                    </div>

                    {/* UBL 2.1 Payload */}
                    <div className="space-y-2">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold">
                                Normalized UBL 2.1 JSON Document Payload
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={handleCopyPayload}
                            >
                                <Copy className="mr-1.5 size-3.5" />
                                {copied ? 'Copied!' : 'Copy JSON'}
                            </Button>
                        </div>
                        <pre className="max-h-96 overflow-auto rounded-lg bg-muted/60 p-4 font-mono text-xs leading-relaxed">
                            {submission.document_payload
                                ? JSON.stringify(
                                      submission.document_payload,
                                      null,
                                      2,
                                  )
                                : 'No payload captured.'}
                        </pre>
                    </div>

                    {/* Raw LHDN Response */}
                    {submission.raw_response && (
                        <div className="space-y-2">
                            <span className="text-xs font-semibold">
                                LHDN Raw Gateway Response
                            </span>
                            <pre className="max-h-48 overflow-auto rounded-lg bg-muted/60 p-4 font-mono text-xs leading-relaxed">
                                {JSON.stringify(
                                    submission.raw_response,
                                    null,
                                    2,
                                )}
                            </pre>
                        </div>
                    )}
                </CollapsibleContent>
            </Collapsible>

            {/* Cancel Modal */}
            <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Cancel E-Invoice on LHDN MyInvois
                        </DialogTitle>
                        <DialogDescription>
                            LHDN accepts cancellation strictly within 72 hours
                            of validation. Enter the cancellation reason.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleCancelSubmit} className="space-y-4">
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
                                onClick={() => setCancelOpen(false)}
                                disabled={cancelling}
                            >
                                Back
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={cancelling || !cancelReason.trim()}
                            >
                                {cancelling ? (
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

            {/* Credit Note Modal */}
            <Dialog open={creditNoteOpen} onOpenChange={setCreditNoteOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Issue E-Invoice Credit Note (02)
                        </DialogTitle>
                        <DialogDescription>
                            References original submission{' '}
                            <span className="font-semibold">
                                {submission.internal_id}
                            </span>{' '}
                            via BillingReference.
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        onSubmit={handleCreditNoteSubmit}
                        className="space-y-4"
                    >
                        <div className="space-y-1.5">
                            <label
                                htmlFor="credit_note_number"
                                className="text-sm font-medium"
                            >
                                Credit Note Number *
                            </label>
                            <Input
                                id="credit_note_number"
                                required
                                value={creditNoteNumber}
                                onChange={(e) =>
                                    setCreditNoteNumber(e.target.value)
                                }
                            />
                        </div>

                        <div className="space-y-1.5">
                            <label
                                htmlFor="credit_note_amount"
                                className="text-sm font-medium"
                            >
                                Credit Amount (MYR) *
                            </label>
                            <Input
                                id="credit_note_amount"
                                type="number"
                                step="0.01"
                                required
                                placeholder="0.00"
                                value={creditNoteAmount}
                                onChange={(e) =>
                                    setCreditNoteAmount(e.target.value)
                                }
                            />
                        </div>

                        <div className="space-y-1.5">
                            <label
                                htmlFor="credit_note_reason"
                                className="text-sm font-medium"
                            >
                                Reason for Adjustment *
                            </label>
                            <Input
                                id="credit_note_reason"
                                required
                                placeholder="e.g. Refund / billing correction"
                                value={creditNoteReason}
                                onChange={(e) =>
                                    setCreditNoteReason(e.target.value)
                                }
                            />
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCreditNoteOpen(false)}
                                disabled={submittingCreditNote}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    submittingCreditNote ||
                                    !creditNoteAmount ||
                                    !creditNoteReason.trim()
                                }
                            >
                                {submittingCreditNote ? (
                                    <>
                                        <Spinner className="mr-1.5 size-4" />{' '}
                                        Submitting...
                                    </>
                                ) : (
                                    'Submit Credit Note'
                                )}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
