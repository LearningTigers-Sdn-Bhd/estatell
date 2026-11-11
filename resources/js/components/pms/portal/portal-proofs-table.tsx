import { Eye } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { buttonVariants } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

/** When the slip was sent, as "11 Nov 2026" in the viewer's time zone. */
function sentOn(timestamp: string): string {
    return new Date(timestamp).toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

/**
 * The proofs and slips a company sent: the date, the amount, the bank reference, Paid into, what it is for (an invoice
 * or the account, with `showFor`), its status with the reason under a rejected one, and View slip to open the file.
 */
export function PortalProofsTable({
    proofs,
    currency,
    showFor = false,
    emptyText,
}: {
    proofs: App.Data.Payments.PaymentProofData[];
    currency: App.Data.Property.CurrencyData | null;
    showFor?: boolean;
    emptyText: string;
}) {
    return (
        <div className="overflow-hidden rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Date</TableHead>
                        <TableHead>Amount</TableHead>
                        <TableHead>Reference</TableHead>
                        <TableHead>Paid into</TableHead>
                        {showFor && <TableHead>For</TableHead>}
                        <TableHead>Status</TableHead>
                        <TableHead>
                            <span className="sr-only">Slip</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {proofs.length === 0 && (
                        <TableRow>
                            <TableCell colSpan={showFor ? 7 : 6}>
                                <EmptyState title={emptyText} />
                            </TableCell>
                        </TableRow>
                    )}
                    {proofs.map((proof) => (
                        <TableRow key={proof.id}>
                            <TableCell>
                                <time
                                    dateTime={proof.uploadedAt}
                                    className="tabular-nums"
                                >
                                    {sentOn(proof.uploadedAt)}
                                </time>
                            </TableCell>
                            <TableCell>
                                <MoneyText
                                    amount={proof.amount}
                                    currency={currency}
                                />
                            </TableCell>
                            <TableCell>{proof.reference ?? '—'}</TableCell>
                            <TableCell>{proof.paidInto ?? '—'}</TableCell>
                            {showFor && (
                                <TableCell>
                                    {proof.invoiceNumber ?? 'Account'}
                                </TableCell>
                            )}
                            <TableCell>
                                <span className="flex flex-col items-start gap-1">
                                    <StatusBadge status={proof.badge} />
                                    {proof.rejectReason && (
                                        <span className="text-xs whitespace-normal text-muted-foreground">
                                            {proof.rejectReason}
                                        </span>
                                    )}
                                </span>
                            </TableCell>
                            <TableCell className="text-right">
                                <a
                                    href={proof.fileUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                    className={buttonVariants({
                                        variant: 'outline',
                                        size: 'sm',
                                    })}
                                >
                                    <Eye data-icon="inline-start" />
                                    View slip
                                </a>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
