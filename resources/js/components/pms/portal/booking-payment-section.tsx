import { AgentBillBreakdown } from '@/components/pms/payments/agent-bill-breakdown';
import {
    PaymentProofForm,
    PaymentProofList,
} from '@/components/pms/payments/payment-proof-form';
import { PaymentScheduleList } from '@/components/pms/payments/payment-schedule-list';
import { BookingHowToPay } from '@/components/pms/portal/booking-how-to-pay';
import { store } from '@/routes/portal/bookings/payment-proofs';

/**
 * Paying for a portal booking: how to pay (the accounts and the reference), what the agent and the guest each pay, the
 * payment schedule, the proofs sent, and a form for a new proof. The server decides when the form shows. A proof is not
 * a payment until staff accept it.
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
    return (
        <section className="flex max-w-3xl flex-col gap-4 rounded-lg border p-4">
            <h2 className="text-base font-medium">Payment</h2>
            <BookingHowToPay
                propertyCode={propertyCode}
                reference={reference}
                howToPay={howToPay}
                currency={currency}
            />
            <AgentBillBreakdown
                bill={bill}
                agentLabel="You pay"
                currency={currency}
            />
            {schedule !== null && (
                <PaymentScheduleList schedule={schedule} currency={currency} />
            )}
            <PaymentProofList proofs={proofs} currency={currency} />
            {howToPay.canSendProof && (
                <PaymentProofForm
                    action={store.url({
                        property: propertyCode,
                        reservation: reservationId,
                    })}
                    bankAccounts={howToPay.bankAccounts}
                    defaultAmount={howToPay.leftToPay}
                />
            )}
        </section>
    );
}
