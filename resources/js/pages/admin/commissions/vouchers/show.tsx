import { Head } from '@inertiajs/react';
import { Check, FileDown, Plus } from 'lucide-react';
import { useState } from 'react';
import {
    AdjustmentDialog,
    ApproveVoucherDialog,
    MarkPaidDialog,
} from '@/components/pms/commissions/voucher-dialogs';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes/admin';
import { index, pdf, show } from '@/routes/admin/commissions/vouchers';

type Props = { voucher: App.Data.Commissions.PayoutVoucherData };

function SummaryRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-baseline justify-between gap-4 text-sm">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right tabular-nums">{children}</span>
        </div>
    );
}

/**
 * One payout voucher: its lines, totals and the bank account to pay, with the actions its status allows.
 * See docs/30-commission-fees/05-platform-pages.md#voucher-page.
 */
export default function PayoutVoucherShow({ voucher: detail }: Props) {
    const { voucher } = detail;
    const [dialog, setDialog] = useState<'approve' | 'paid' | 'adjust' | null>(
        null,
    );
    const close = (open: boolean) => !open && setDialog(null);
    const noBankAccount =
        detail.bankAccount === null && !voucher.owedToEstatell;

    return (
        <>
            <Head title={voucher.number} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={voucher.number}
                    description={voucher.propertyName}
                    back={{ href: index(), label: 'payout vouchers' }}
                    badge={<StatusBadge status={voucher.status} />}
                    actions={
                        <>
                            {detail.canAdjust && (
                                <Button
                                    variant="outline"
                                    onClick={() => setDialog('adjust')}
                                >
                                    <Plus aria-hidden />
                                    Add adjustment
                                </Button>
                            )}
                            {detail.canApprove && (
                                <Button onClick={() => setDialog('approve')}>
                                    <Check aria-hidden />
                                    Approve
                                </Button>
                            )}
                            {detail.canMarkPaid && (
                                <Button onClick={() => setDialog('paid')}>
                                    <Check aria-hidden />
                                    Mark as paid
                                </Button>
                            )}
                            <a
                                href={pdf.url(voucher.id)}
                                target="_blank"
                                rel="noreferrer"
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                <FileDown aria-hidden />
                                PDF
                            </a>
                        </>
                    }
                />

                {noBankAccount && voucher.status.value === 'draft' && (
                    <Alert>
                        <AlertDescription>
                            {voucher.propertyName} has no active bank account.
                            Ask the property to add one under Settings before
                            you approve this voucher.
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <div className="overflow-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Due date</TableHead>
                                    <TableHead>Booking</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {detail.lines.map((line) => (
                                    <TableRow key={line.id}>
                                        <TableCell>
                                            <DateText date={line.dueDate} />
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {line.bookingReference ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            <span className="flex flex-col">
                                                <span>{line.label}</span>
                                                {line.reason && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {line.reason}
                                                    </span>
                                                )}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <MoneyText
                                                amount={line.amount}
                                                currency={voucher.currency}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle>Summary</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2">
                            <SummaryRow label="Cycle">
                                <DateText date={voucher.periodStart} /> –{' '}
                                <DateText date={voucher.periodEnd} />
                            </SummaryRow>
                            <SummaryRow label="Due on">
                                <DateText date={voucher.dueOn} />
                            </SummaryRow>
                            <Separator className="my-1" />
                            {detail.totals.map((total) => (
                                <SummaryRow
                                    key={total.label}
                                    label={total.label}
                                >
                                    <MoneyText
                                        amount={total.amount}
                                        currency={voucher.currency}
                                    />
                                </SummaryRow>
                            ))}
                            <SummaryRow
                                label={
                                    voucher.owedToEstatell
                                        ? 'Owed to Estatell'
                                        : 'Payable to property'
                                }
                            >
                                <span className="font-medium">
                                    <MoneyText
                                        amount={voucher.amount}
                                        currency={voucher.currency}
                                    />
                                </span>
                            </SummaryRow>
                            {detail.bankAccount && (
                                <>
                                    <Separator className="my-1" />
                                    <SummaryRow label="Pay to">
                                        {detail.bankAccount.bankName}
                                    </SummaryRow>
                                    <SummaryRow label="Account name">
                                        {detail.bankAccount.accountName}
                                    </SummaryRow>
                                    <SummaryRow label="Account number">
                                        {detail.bankAccount.accountNumber}
                                    </SummaryRow>
                                    {detail.bankAccount.swiftCode && (
                                        <SummaryRow label="SWIFT">
                                            {detail.bankAccount.swiftCode}
                                        </SummaryRow>
                                    )}
                                </>
                            )}
                            {detail.approvedBy && (
                                <>
                                    <Separator className="my-1" />
                                    <SummaryRow label="Approved by">
                                        {detail.approvedBy}
                                    </SummaryRow>
                                    <SummaryRow label="Approved at">
                                        {detail.approvedAt}
                                    </SummaryRow>
                                </>
                            )}
                            {detail.paidOn && (
                                <>
                                    <SummaryRow label="Paid on">
                                        <DateText date={detail.paidOn} />
                                    </SummaryRow>
                                    <SummaryRow label="Reference">
                                        {detail.paidReference}
                                    </SummaryRow>
                                </>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>

            <ApproveVoucherDialog
                open={dialog === 'approve'}
                onOpenChange={close}
                voucher={voucher}
            />
            <MarkPaidDialog
                open={dialog === 'paid'}
                onOpenChange={close}
                voucher={voucher}
            />
            <AdjustmentDialog
                open={dialog === 'adjust'}
                onOpenChange={close}
                voucher={voucher}
            />
        </>
    );
}

PayoutVoucherShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Payout vouchers', href: index() },
        {
            title: props.voucher.voucher.number,
            href: show(props.voucher.voucher.id),
        },
    ],
});
