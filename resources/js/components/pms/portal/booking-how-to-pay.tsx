import { Link } from '@inertiajs/react';
import { BankAccountList } from '@/components/pms/portal/bank-account-list';
import { MoneyText } from '@/components/pms/money-text';
import { statements } from '@/routes/portal';

/**
 * How a company pays the booking by bank transfer: what is left to pay, the accounts, the reference to use and the
 * slip. A direct-billing company sees a note to pay its invoice instead, and a paid booking says so. The server decides
 * every figure. See docs/15-city-ledger/06-agent-payment-schedule.md#payment-proof.
 */
export function BookingHowToPay({
    propertyCode,
    reference,
    howToPay,
    currency,
}: {
    propertyCode: string;
    reference: string;
    howToPay: App.Data.Portal.PortalHowToPayData;
    currency: App.Data.Property.CurrencyData | null;
}) {
    if (howToPay.billedLater) {
        return (
            <div className="flex flex-col gap-1 rounded-lg border p-3 text-sm">
                <span className="font-medium">Billed after your stay</span>
                <span className="text-muted-foreground">
                    The property sends an invoice for this booking. Pay it from{' '}
                    <Link
                        href={statements({ property: propertyCode })}
                        className="text-foreground underline-offset-4 hover:underline"
                    >
                        Statements
                    </Link>
                    .
                </span>
            </div>
        );
    }

    if (Number(howToPay.leftToPay) <= 0) {
        return (
            <div className="flex items-baseline justify-between gap-3 rounded-lg border p-3 text-sm">
                <span className="font-medium">Paid</span>
                <MoneyText amount={howToPay.paid} currency={currency} />
            </div>
        );
    }

    const contact = [howToPay.contactPhone, howToPay.contactEmail]
        .filter(Boolean)
        .join(' · ');

    return (
        <div className="flex flex-col gap-3 rounded-lg border p-3 text-sm">
            <div className="flex items-baseline justify-between gap-3">
                <h3 className="font-medium">How to pay</h3>
                <span className="text-muted-foreground">
                    Left to pay{' '}
                    <span className="font-medium text-foreground">
                        <MoneyText
                            amount={howToPay.leftToPay}
                            currency={currency}
                        />
                    </span>
                </span>
            </div>
            {howToPay.bankAccounts.length > 0 ? (
                <ol className="flex list-decimal flex-col gap-2 pl-5">
                    <li className="flex flex-col gap-2">
                        <span>
                            Transfer to{' '}
                            {howToPay.bankAccounts.length === 1
                                ? 'this account'
                                : 'one of these accounts'}
                        </span>
                        <BankAccountList bankAccounts={howToPay.bankAccounts} />
                    </li>
                    <li>
                        Use <span className="font-medium">{reference}</span> as
                        the payment reference.
                    </li>
                    <li>
                        Send the slip below. The property checks it and records
                        your payment.
                    </li>
                </ol>
            ) : (
                <p className="text-muted-foreground">
                    Contact the property for its bank details
                    {contact ? `: ${contact}` : ''}. Then send the slip below.
                </p>
            )}
        </div>
    );
}
