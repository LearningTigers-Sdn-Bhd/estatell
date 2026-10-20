import { Form } from '@inertiajs/react';
import {
    Ban,
    MoreHorizontal,
    Plus,
    Printer,
    Split,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import { AllocateArPaymentDialog } from '@/components/pms/city-ledger/allocate-ar-payment-dialog';
import { RecordArPaymentDialog } from '@/components/pms/city-ledger/record-ar-payment-dialog';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCanCorrectMoney } from '@/hooks/use-is-impersonating';
import {
    receipt,
    voidMethod,
} from '@/routes/finance/city-ledger/companies/payments';

type Payment = App.Data.CityLedger.ArPaymentRowData;

/**
 * The Payments tab of a company: what it paid against the city ledger and what is left to allocate. Recording,
 * allocating and voiding need their own permissions, so the buttons come only when the server says so.
 */
export function ArPaymentsTab({
    companyId,
    payments,
    openInvoices,
    methods,
    currency,
    canRecordPayment,
}: {
    companyId: number;
    payments: Payment[];
    openInvoices: App.Data.CityLedger.OpenArInvoiceData[];
    methods: App.Data.CityLedger.ArPaymentMethodOptionData[];
    currency: App.Data.Property.CurrencyData | null;
    canRecordPayment: boolean;
}) {
    const [recording, setRecording] = useState(false);
    const [allocating, setAllocating] = useState<Payment | null>(null);
    const [voiding, setVoiding] = useState<Payment | null>(null);
    const canCorrectMoney = useCanCorrectMoney();

    return (
        <section className="flex flex-col gap-4">
            <header className="flex flex-wrap items-center justify-between gap-3">
                <div className="space-y-1">
                    <h3 className="text-base font-medium">Payments</h3>
                    <p className="text-sm text-muted-foreground">
                        Money the company paid against its city ledger.
                        Unallocated money stays as credit.
                    </p>
                </div>
                {canRecordPayment && (
                    <Button onClick={() => setRecording(true)}>
                        <Plus data-icon="inline-start" />
                        Record payment
                    </Button>
                )}
            </header>

            {payments.length === 0 ? (
                <EmptyState
                    icon={Wallet}
                    title="No payments yet"
                    description="Record a payment when the company pays an invoice."
                />
            ) : (
                <div className="overflow-hidden rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Receipt</TableHead>
                                <TableHead>Received</TableHead>
                                <TableHead>Method</TableHead>
                                <TableHead>Reference</TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                                <TableHead className="text-right">
                                    Unallocated
                                </TableHead>
                                <TableHead className="w-10" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {payments.map((payment) => (
                                <TableRow key={payment.id}>
                                    <TableCell className="font-medium">
                                        {payment.receiptNumber}
                                        {payment.isVoided && (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                            >
                                                Voided
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <DateText date={payment.receivedOn} />
                                    </TableCell>
                                    <TableCell>{payment.methodName}</TableCell>
                                    <TableCell>
                                        {payment.reference ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <MoneyText
                                            amount={payment.amount}
                                            currency={currency}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <MoneyText
                                            amount={payment.unallocated}
                                            currency={currency}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger
                                                render={
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Actions for ${payment.receiptNumber}`}
                                                    />
                                                }
                                            >
                                                <MoreHorizontal />
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                {payment.canAllocate && (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            setAllocating(
                                                                payment,
                                                            )
                                                        }
                                                    >
                                                        <Split />
                                                        Allocate
                                                    </DropdownMenuItem>
                                                )}
                                                {payment.hasReceipt && (
                                                    <DropdownMenuItem
                                                        render={
                                                            <a
                                                                href={receipt.url(
                                                                    {
                                                                        company:
                                                                            companyId,
                                                                        payment:
                                                                            payment.id,
                                                                    },
                                                                )}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                            />
                                                        }
                                                    >
                                                        <Printer />
                                                        Receipt
                                                    </DropdownMenuItem>
                                                )}
                                                {payment.canVoid &&
                                                    canCorrectMoney && (
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            onClick={() =>
                                                                setVoiding(
                                                                    payment,
                                                                )
                                                            }
                                                        >
                                                            <Ban />
                                                            Void
                                                        </DropdownMenuItem>
                                                    )}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            {canRecordPayment && (
                <RecordArPaymentDialog
                    open={recording}
                    onOpenChange={setRecording}
                    companyId={companyId}
                    methods={methods}
                    currency={currency}
                />
            )}
            <AllocateArPaymentDialog
                payment={allocating}
                onClose={() => setAllocating(null)}
                companyId={companyId}
                invoices={openInvoices}
                currency={currency}
            />
            <AlertDialog
                open={voiding !== null}
                onOpenChange={(open) => !open && setVoiding(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Void payment {voiding?.receiptNumber}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Use this for a payment recorded by mistake. The
                            receipt number stays used and the receipt shows
                            VOID.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {voiding && (
                        <Form
                            {...voidMethod.form({
                                company: companyId,
                                payment: voiding.id,
                            })}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setVoiding(null)}
                        >
                            {({ processing }) => (
                                <AlertDialogFooter>
                                    <AlertDialogCancel>
                                        Cancel
                                    </AlertDialogCancel>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                        focusableWhenDisabled
                                    >
                                        {processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        Void payment
                                    </Button>
                                </AlertDialogFooter>
                            )}
                        </Form>
                    )}
                </AlertDialogContent>
            </AlertDialog>
        </section>
    );
}
