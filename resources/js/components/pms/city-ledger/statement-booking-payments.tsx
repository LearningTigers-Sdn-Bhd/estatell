import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

/**
 * The money a company paid directly on its bookings in the statement's period. These payments are not city ledger
 * entries, so they do not change the statement's balance. Both the staff and the portal statement show them.
 */
export function StatementBookingPayments({
    payments,
    currency,
}: {
    payments: App.Data.CityLedger.StatementBookingPaymentData[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    if (payments.length === 0) {
        return null;
    }

    return (
        <section className="flex flex-col gap-2">
            <h3 className="text-sm font-medium">Payments on bookings</h3>
            <p className="text-sm text-muted-foreground">
                Paid per booking, so they are not part of the balance above.
            </p>
            <div className="overflow-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead>Booking</TableHead>
                            <TableHead>Method</TableHead>
                            <TableHead>Reference</TableHead>
                            <TableHead>Receipt</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {payments.map((payment, index) => (
                            <TableRow key={index}>
                                <TableCell>
                                    <DateText date={payment.date} />
                                </TableCell>
                                <TableCell>{payment.booking}</TableCell>
                                <TableCell>{payment.method}</TableCell>
                                <TableCell>
                                    {payment.reference ?? '—'}
                                </TableCell>
                                <TableCell>{payment.receipt ?? '—'}</TableCell>
                                <TableCell className="text-right">
                                    <MoneyText
                                        amount={payment.amount}
                                        currency={currency}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </section>
    );
}
