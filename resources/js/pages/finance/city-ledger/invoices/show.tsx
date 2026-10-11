import { Form, Head, Link, router } from '@inertiajs/react';
import { FilePlus2, Printer } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ReverseAllocationDialog } from '@/components/pms/city-ledger/reverse-allocation-dialog';
import { IssueCreditNoteDialog } from '@/components/pms/city-ledger/issue-credit-note-dialog';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { show as showCompany } from '@/routes/finance/city-ledger/companies';
import {
    index,
    pdf,
    show,
    voidMethod as voidInvoice,
} from '@/routes/finance/city-ledger/invoices';
import { show as showReservation } from '@/routes/reservations';

type Invoice = App.Data.CityLedger.ArInvoiceData;

function Figure({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1 rounded-lg border p-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm font-medium">{children}</dd>
        </div>
    );
}

function VoidAction({ invoice }: { invoice: Invoice }) {
    const [open, setOpen] = useState(false);
    const { row } = invoice;

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            <AlertDialogTrigger render={<Button variant="outline" />}>
                Void
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Void {row.number}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        {row.type === 'invoice'
                            ? 'The company no longer owes it. The folio stays closed: reopen it, reverse the City ledger line and settle again to issue a new invoice.'
                            : 'The credit is taken back and the invoice owes the money again.'}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form
                    {...voidInvoice.form({ invoice: row.id })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                Void{' '}
                                {row.type === 'invoice'
                                    ? 'invoice'
                                    : 'credit note'}
                            </Button>
                        </AlertDialogFooter>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}

export default function InvoiceShow({
    invoice,
    currency,
    openCreditNote,
}: {
    invoice: Invoice;
    currency: App.Enums.Currency | null;
    openCreditNote: boolean;
}) {
    const [crediting, setCrediting] = useState(openCreditNote);

    // Drop ?credit=1 after the first open, so a reload of this visit does not reopen the Dialog.
    useEffect(() => {
        if (openCreditNote) {
            router.replace({
                url: show.url({ invoice: invoice.row.id }),
                preserveScroll: true,
                preserveState: true,
            });
        }
    }, [openCreditNote, invoice.row.id]);
    const [reversing, setReversing] =
        useState<App.Data.CityLedger.ArAllocationRowData | null>(null);
    const [returnToCredit, setReturnToCredit] = useState(false);
    const { row } = invoice;
    const isInvoice = row.type === 'invoice';
    const money = (amount: string) => (
        <MoneyText amount={amount} currency={currency} />
    );

    return (
        <>
            <Head title={`${row.typeLabel} ${row.number}`} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={`${row.typeLabel} ${row.number}`}
                    description={
                        <Link
                            href={showCompany.url({ company: row.companyId })}
                            className="underline underline-offset-2"
                        >
                            {row.companyName}
                        </Link>
                    }
                    back={{ href: index(), label: 'Invoices' }}
                    badge={<StatusBadge status={row.status} />}
                    actions={
                        <>
                            <a
                                href={pdf.url({ invoice: row.id })}
                                target="_blank"
                                rel="noreferrer"
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                <Printer data-icon="inline-start" />
                                Download PDF
                            </a>
                            {invoice.canCreditNote && (
                                <Button
                                    variant="outline"
                                    onClick={() => setCrediting(true)}
                                >
                                    <FilePlus2 data-icon="inline-start" />
                                    Issue credit note
                                </Button>
                            )}
                            {invoice.canVoid && (
                                <VoidAction invoice={invoice} />
                            )}
                        </>
                    }
                />

                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Figure label="Issued">
                        <DateText date={row.issuedOn} />
                    </Figure>
                    {row.dueOn !== null && (
                        <Figure label="Due">
                            <span
                                className={cn(
                                    row.overdue && 'text-destructive',
                                )}
                            >
                                <DateText date={row.dueOn} />
                                {row.overdue && ' · Overdue'}
                            </span>
                        </Figure>
                    )}
                    <Figure label="Amount">{money(row.amount)}</Figure>
                    {isInvoice && (
                        <>
                            <Figure label="Credit notes">
                                {money(invoice.credited)}
                            </Figure>
                            <Figure label="Balance">
                                {money(row.balance ?? '0.00')}
                            </Figure>
                        </>
                    )}
                    {invoice.reservationId !== null && (
                        <Figure label="Reservation">
                            <Link
                                href={showReservation.url({
                                    reservation: invoice.reservationId,
                                })}
                                className="underline underline-offset-2"
                            >
                                {invoice.reservationReference}
                            </Link>
                            {invoice.folioNumber &&
                                ` · Folio ${invoice.folioNumber}`}
                        </Figure>
                    )}
                    {invoice.creditForId !== null && (
                        <Figure label="Corrects invoice">
                            <Link
                                href={show.url({
                                    invoice: invoice.creditForId,
                                })}
                                className="underline underline-offset-2"
                            >
                                {invoice.creditForNumber}
                            </Link>
                        </Figure>
                    )}
                    {invoice.reason && (
                        <Figure label="Reason">{invoice.reason}</Figure>
                    )}
                </dl>

                {isInvoice && (
                    <section className="flex flex-col gap-3">
                        <h2 className="text-base font-medium">Credit notes</h2>
                        {invoice.creditNotes.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No credit notes.
                            </p>
                        ) : (
                            <div className="overflow-hidden rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Number</TableHead>
                                            <TableHead>Issued</TableHead>
                                            <TableHead>Amount</TableHead>
                                            <TableHead>Status</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {invoice.creditNotes.map((note) => (
                                            <TableRow key={note.id}>
                                                <TableCell>
                                                    <Link
                                                        href={show.url({
                                                            invoice: note.id,
                                                        })}
                                                        className="font-medium underline-offset-2 hover:underline"
                                                    >
                                                        {note.number}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    <DateText
                                                        date={note.issuedOn}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {money(note.amount)}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={note.status}
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </section>
                )}

                {isInvoice && invoice.allocations.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <h2 className="text-base font-medium">Payments</h2>
                        <div className="overflow-hidden rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Receipt</TableHead>
                                        <TableHead>Received</TableHead>
                                        <TableHead>Amount</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="w-10" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {invoice.allocations.map((allocation) => (
                                        <TableRow key={allocation.id}>
                                            <TableCell className="font-medium">
                                                {allocation.receiptNumber}
                                            </TableCell>
                                            <TableCell>
                                                <DateText
                                                    date={allocation.receivedOn}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                {money(allocation.amount)}
                                            </TableCell>
                                            <TableCell>
                                                {allocation.isReversed ? (
                                                    <span
                                                        title={
                                                            allocation.reversalReason ??
                                                            undefined
                                                        }
                                                        className="text-muted-foreground"
                                                    >
                                                        Reversed
                                                    </span>
                                                ) : (
                                                    'Applied'
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {allocation.canReverse && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setReversing(
                                                                allocation,
                                                            )
                                                        }
                                                    >
                                                        Reverse
                                                    </Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </section>
                )}

                {invoice.lines.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <h2 className="text-base font-medium">Folio lines</h2>
                        <div className="overflow-hidden rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Description</TableHead>
                                        <TableHead className="text-right">
                                            Amount
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {invoice.lines.map((line, position) => (
                                        <TableRow key={position}>
                                            <TableCell>
                                                <DateText
                                                    date={line.postingDate}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                {line.description}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {money(line.amount)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </section>
                )}
            </div>

            <ReverseAllocationDialog
                allocation={reversing}
                onClose={() => {
                    setReversing(null);

                    if (returnToCredit) {
                        setReturnToCredit(false);
                        setCrediting(true);
                    }
                }}
                currency={currency}
            />

            {invoice.canCreditNote && (
                <IssueCreditNoteDialog
                    open={crediting}
                    onOpenChange={setCrediting}
                    invoice={invoice}
                    currency={currency}
                    onReverse={(allocation) => {
                        setCrediting(false);
                        setReturnToCredit(true);
                        setReversing(allocation);
                    }}
                />
            )}
        </>
    );
}

InvoiceShow.layout = (props: { invoice: Invoice }) => ({
    breadcrumbs: [
        { title: 'Invoices', href: index() },
        {
            title: props.invoice.row.number,
            href: show({ invoice: props.invoice.row.id }),
        },
    ],
});
