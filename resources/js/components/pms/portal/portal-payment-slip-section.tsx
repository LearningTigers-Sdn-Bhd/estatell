import {
    PaymentProofForm,
    PaymentProofList,
} from '@/components/pms/payments/payment-proof-form';
import { BankAccountList } from '@/components/pms/portal/bank-account-list';
import { store } from '@/routes/portal/statements/payment-slips';

/**
 * A company on credit terms sends a slip for its account, with the property's available bank accounts above the form. The property checks it, and only then does it count as a
 * payment. Each slip shows its status, with the reason if it was rejected.
 */
export function PortalPaymentSlipSection({
    propertyCode,
    slips,
    canSend,
    bankAccounts,
    currency,
}: {
    propertyCode: string;
    slips: App.Data.Payments.PaymentProofData[];
    canSend: boolean;
    bankAccounts: App.Data.Catalogues.BankAccountData[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    if (!canSend && slips.length === 0) {
        return null;
    }

    return (
        <section className="flex max-w-3xl flex-col gap-3 rounded-lg border p-4">
            <div className="flex flex-col gap-1">
                <h2 className="text-base font-medium">Send a payment slip</h2>
                <p className="text-sm text-muted-foreground">
                    Paid your invoices by bank transfer? Send the slip here. The
                    property checks it, then it shows under Payments and your
                    balance falls.
                </p>
            </div>
            {canSend && bankAccounts.length > 0 && (
                <BankAccountList bankAccounts={bankAccounts} />
            )}
            <PaymentProofList proofs={slips} currency={currency} />
            {canSend && (
                <PaymentProofForm
                    action={store.url({ property: propertyCode })}
                    idPrefix="slip"
                    submitLabel="Send slip"
                    bankAccounts={bankAccounts}
                />
            )}
        </section>
    );
}
