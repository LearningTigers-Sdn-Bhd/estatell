import { Banknote, CircleCheck, ReceiptText, Wallet } from 'lucide-react';
import { useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { PaymentScheduleList } from '@/components/pms/payments/payment-schedule-list';
import { PortalBillTable } from '@/components/pms/portal/portal-bill-table';
import { PortalPayDialog } from '@/components/pms/portal/portal-pay-dialog';
import { PortalProofsTable } from '@/components/pms/portal/portal-proofs-table';
import { StatTile } from '@/components/pms/stat-tile';
import { Button } from '@/components/ui/button';
import { store } from '@/routes/portal/bookings/payment-proofs';

/**
 * The Payments tab of a portal booking: the Payment title with the Pay button, tiles for You pay, Paid and Left to pay, the bill, the
 * payment schedule and the proofs and slips sent. The server decides every figure, when the button shows, and whether a
 * proof goes on the booking or on its invoice. A proof is not a payment until staff accept it.
 */
export function BookingPaymentSection({
    propertyCode,
    reservationId,
    reference,
    schedule,
    bill,
    howToPay,
    proofs,
    currency,
}: {
    propertyCode: string;
    reservationId: number;
    reference: string;
    schedule: App.Data.Reservations.PaymentScheduleData | null;
    bill: App.Data.Reservations.AgentBillData;
    howToPay: App.Data.Portal.PortalHowToPayData;
    proofs: App.Data.Payments.PaymentProofData[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [paying, setPaying] = useState(false);
    const isPaid = Number(howToPay.leftToPay) <= 0;
    const money = (amount: string) => (
        <MoneyText amount={amount} currency={currency} />
    );
    const leftNote = isPaid ? (
        'Nothing left to pay'
    ) : howToPay.invoiceNumber ? (
        <>
            Invoice {howToPay.invoiceNumber}
            {howToPay.invoiceDueOn && (
                <>
                    {' '}
                    · due <DateText date={howToPay.invoiceDueOn} />
                </>
            )}
        </>
    ) : howToPay.billedLater ? (
        'Invoiced after checkout. You can pay now.'
    ) : (
        'Pay by bank transfer'
    );
    const contact = [howToPay.contactPhone, howToPay.contactEmail]
        .filter(Boolean)
        .join(' · ');

    return (
        <section className="flex flex-col gap-8">
            <div className="flex flex-col gap-4">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-base font-medium">Payment</h2>
                    {howToPay.canSendProof && (
                        <Button onClick={() => setPaying(true)}>
                            <Banknote data-icon="inline-start" />
                            Pay this booking
                        </Button>
                    )}
                </header>
                <div className="grid gap-4 sm:grid-cols-3">
                    <StatTile
                        title="You pay"
                        icon={ReceiptText}
                        figure={money(bill.agentTotal)}
                        note="Your part of the bill"
                    />
                    <StatTile
                        title="Paid"
                        icon={CircleCheck}
                        figure={money(howToPay.paid)}
                        note="Recorded by the property"
                    />
                    <StatTile
                        title="Left to pay"
                        icon={Wallet}
                        figure={money(howToPay.leftToPay)}
                        note={leftNote}
                    />
                </div>
            </div>

            <PortalBillTable bill={bill} currency={currency} />

            {schedule !== null && (
                <section className="flex flex-col gap-2">
                    <h3 className="text-sm font-medium">Payment schedule</h3>
                    <PaymentScheduleList
                        schedule={schedule}
                        currency={currency}
                    />
                </section>
            )}

            <section className="flex flex-col gap-2">
                <h3 className="text-sm font-medium">Payments and slips</h3>
                <PortalProofsTable
                    proofs={proofs}
                    currency={currency}
                    emptyText="No slips sent yet"
                />
            </section>

            <PortalPayDialog
                open={paying}
                onOpenChange={setPaying}
                title="Pay this booking"
                action={store.url({
                    property: propertyCode,
                    reservation: reservationId,
                })}
                figureLabel="Left to pay"
                figure={howToPay.leftToPay}
                reference={howToPay.invoiceNumber ?? reference}
                defaultAmount={howToPay.leftToPay}
                bankAccounts={howToPay.bankAccounts}
                contact={contact}
                currency={currency}
            />
        </section>
    );
}
