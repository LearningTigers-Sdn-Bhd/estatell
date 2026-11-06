import { AgentBillBreakdown } from '@/components/pms/payments/agent-bill-breakdown';
import {
    PaymentProofForm,
    PaymentProofList,
} from '@/components/pms/payments/payment-proof-form';
import { PaymentScheduleList } from '@/components/pms/payments/payment-schedule-list';
import { store } from '@/routes/portal/bookings/payment-proofs';

/**
 * Paying for a portal booking: what the agent and the guest each pay, the payment schedule, the proofs sent, and a form for
 * a new proof (image or PDF, the amount and the bank reference). A proof is not a payment until staff accept it.
 */
export function BookingPaymentSection({
    propertyCode,
    reservationId,
    schedule,
    bill,
    proofs,
    canUpload,
    currency,
}: {
    propertyCode: string;
    reservationId: number;
    schedule: App.Data.Reservations.PaymentScheduleData | null;
    bill: App.Data.Reservations.AgentBillData;
    proofs: App.Data.Payments.PaymentProofData[];
    canUpload: boolean;
    currency: App.Data.Property.CurrencyData | null;
}) {
    return (
        <section className="flex max-w-3xl flex-col gap-4 rounded-lg border p-4">
            <h2 className="text-base font-medium">Payment</h2>
            <AgentBillBreakdown
                bill={bill}
                agentLabel="You pay"
                currency={currency}
            />
            {schedule !== null && (
                <PaymentScheduleList schedule={schedule} currency={currency} />
            )}
            <PaymentProofList proofs={proofs} currency={currency} />
            {canUpload && (
                <PaymentProofForm
                    action={store.url({
                        property: propertyCode,
                        reservation: reservationId,
                    })}
                />
            )}
        </section>
    );
}
