import { Head, router } from '@inertiajs/react';
import { History, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import AccountingSettingsController from '@/actions/App/Http/Controllers/Accounting/AccountingSettingsController';
import { AccountingShell } from '@/components/pms/accounting/accounting-shell';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { profile } from '@/routes/settings';
import { edit, history } from '@/routes/settings/accounting';

export default function AccountingHistory({
    setup,
    syncs,
    documents,
}: {
    setup: App.Data.Accounting.AccountingSetupData;
    syncs: App.Data.Accounting.AccountingSyncData[];
    documents: App.Data.Accounting.AccountingDocumentData[];
}) {
    const [sending, setSending] = useState(false);

    return (
        <>
            <Head title="Accounting history" />
            <AccountingShell setup={setup} tab="history">
                <div className="flex flex-col gap-8">
                    <section className="flex flex-col gap-3">
                        <div>
                            <h3 className="text-base font-medium">
                                Daily journals
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                One journal for each closed business date. A
                                failed date can be pushed again once you fix the
                                cause.
                            </p>
                        </div>
                        {syncs.length === 0 ? (
                            <EmptyState
                                icon={History}
                                title="Nothing pushed yet"
                                description="The first journal is pushed after the next night audit, once the connection is saved and switched on."
                            />
                        ) : (
                            <Card>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Business date</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Reference</TableHead>
                                            <TableHead>Detail</TableHead>
                                            <TableHead className="w-32">
                                                <span className="sr-only">
                                                    Action
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {syncs.map((sync) => (
                                            <TableRow key={sync.id}>
                                                <TableCell>
                                                    <DateText
                                                        date={sync.date}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={sync.status}
                                                    />
                                                </TableCell>
                                                <TableCell className="font-mono text-xs">
                                                    {sync.externalId ?? '—'}
                                                </TableCell>
                                                <TableCell className="max-w-md whitespace-normal text-muted-foreground">
                                                    {sync.error ??
                                                        (sync.attempts > 1
                                                            ? `Pushed on attempt ${sync.attempts}`
                                                            : '')}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {sync.canRetry && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() =>
                                                                router.post(
                                                                    AccountingSettingsController.retry.url(
                                                                        {
                                                                            accountingSync:
                                                                                sync.id,
                                                                        },
                                                                    ),
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <RefreshCw />
                                                            Push again
                                                        </Button>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </Card>
                        )}
                    </section>

                    <section className="flex flex-col gap-3">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 className="text-base font-medium">
                                    Company billing
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    Invoices, credit notes and payments of
                                    companies, as sent to your accounting
                                    software. A failed one is tried again on the
                                    next sync.
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                disabled={sending}
                                focusableWhenDisabled
                                onClick={() =>
                                    router.post(
                                        AccountingSettingsController.syncBilling.url(),
                                        {},
                                        {
                                            preserveScroll: true,
                                            onStart: () => setSending(true),
                                            onFinish: () => setSending(false),
                                        },
                                    )
                                }
                            >
                                {sending ? <Spinner /> : <RefreshCw />}
                                Sync now
                            </Button>
                        </div>
                        {documents.length === 0 ? (
                            <EmptyState
                                icon={History}
                                title="No company documents sent"
                                description="Turn on “Also send company invoices and payments” on the Connection tab. Invoices issued from then on are sent."
                            />
                        ) : (
                            <Card>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Document</TableHead>
                                            <TableHead>Company</TableHead>
                                            <TableHead>Date</TableHead>
                                            <TableHead className="text-right">
                                                Amount
                                            </TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Detail</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {documents.map((document) => (
                                            <TableRow key={document.id}>
                                                <TableCell>
                                                    <span className="flex flex-col">
                                                        <span>
                                                            {document.kindLabel}
                                                        </span>
                                                        <span className="font-mono text-xs text-muted-foreground">
                                                            {document.number}
                                                        </span>
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    {document.companyName}
                                                </TableCell>
                                                <TableCell>
                                                    <DateText
                                                        date={document.date}
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {document.amount}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={document.status}
                                                    />
                                                </TableCell>
                                                <TableCell className="max-w-md whitespace-normal text-muted-foreground">
                                                    {document.error ??
                                                        document.externalId ??
                                                        ''}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </Card>
                        )}
                    </section>
                </div>
            </AccountingShell>
        </>
    );
}

AccountingHistory.layout = {
    breadcrumbs: [
        { title: 'Settings', href: profile() },
        { title: 'Accounting', href: edit() },
        { title: 'History', href: history() },
    ],
};
