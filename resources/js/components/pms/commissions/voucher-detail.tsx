import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
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

function SummaryRow({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex items-baseline justify-between gap-4 text-sm">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right tabular-nums">{children}</span>
        </div>
    );
}

/**
 * The body of a payout voucher page: its lines, and a summary with totals, the bank account and the approval and
 * payment details. Used by the admin voucher page and the property's read-only one.
 */
export function VoucherDetail({
    detail,
}: {
    detail: App.Data.Commissions.PayoutVoucherData;
}) {
    const { voucher } = detail;

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div className="overflow-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Due date</TableHead>
                            <TableHead>Booking</TableHead>
                            <TableHead>Type</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
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
                        <SummaryRow key={total.label} label={total.label}>
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
    );
}
