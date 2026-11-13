import { Form, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    ArrowRight,
    CircleCheck,
    Clock,
    CreditCard,
    LogOut,
    ReceiptText,
    Wallet,
} from 'lucide-react';
import { DocumentList } from '@/components/concierge/checked-out-step';
import { PaymentStep } from '@/components/concierge/payment-step';
import { GuestFrame } from '@/components/concierge/stay-menu';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { Spinner } from '@/components/ui/spinner';
import { checkOut } from '@/routes/concierge';
import { ask } from '@/routes/concierge/check-out';

function money(amount: string, currency: string): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    }).format(Number(amount));
}

/** The guest's bill. They can pay what is due here, then check out when it is settled. */
export default function ConciergeBill({
    token,
    back,
    bill,
    documents,
}: {
    token: string;
    back: { href: string; label: string };
    bill: App.Data.Concierge.ConciergeBillData;
    documents: App.Data.Concierge.ConciergeDocumentData[];
}) {
    const [busy, setBusy] = useState(false);

    return (
        <GuestFrame
            back={back}
            title="Bill and check-out"
            description="Review your charges, open your documents, and arrange check-out."
        >
            <section
                className="flex flex-col gap-6 rounded-2xl bg-muted/50 p-6 sm:flex-row sm:items-center sm:justify-between sm:p-8"
                aria-labelledby="bill-balance-title"
            >
                <div className="flex items-center gap-4">
                    <span className="flex size-12 shrink-0 items-center justify-center rounded-full bg-background text-primary">
                        <Wallet
                            className="size-6"
                            strokeWidth={1.5}
                            aria-hidden
                        />
                    </span>
                    <div className="flex min-w-0 flex-col gap-2">
                        <h2
                            id="bill-balance-title"
                            className="text-sm text-muted-foreground"
                        >
                            {Number(bill.balance) < 0
                                ? 'Credit with the property'
                                : 'Amount due'}
                        </h2>
                        <p className="text-3xl font-semibold tracking-tight break-words tabular-nums sm:text-4xl">
                            {money(
                                Number(bill.balance) < 0
                                    ? bill.credit
                                    : bill.due,
                                bill.currency,
                            )}
                        </p>
                    </div>
                </div>
                {Number(bill.credit) > 0 && (
                    <p className="max-w-md text-sm leading-relaxed text-muted-foreground">
                        The property holds {money(bill.credit, bill.currency)}{' '}
                        for you, for example a deposit. Please visit the front
                        desk to collect it when you check out.
                    </p>
                )}
                {bill.canPay && (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <CreditCard className="size-4 shrink-0" aria-hidden />
                        Online payment available below
                    </p>
                )}
            </section>
            <div className="grid items-start gap-8 lg:grid-cols-5 lg:gap-10">
                <div className="flex min-w-0 flex-col gap-8 lg:col-span-3">
                    <section
                        className="overflow-hidden rounded-2xl border border-border/40 bg-card"
                        aria-labelledby="bill-lines-title"
                    >
                        <div className="flex items-center gap-3 border-b border-border/40 p-5 sm:p-6">
                            <ReceiptText
                                className="size-5 text-primary"
                                aria-hidden
                            />
                            <h2
                                id="bill-lines-title"
                                className="text-lg font-semibold"
                            >
                                Your bill
                            </h2>
                        </div>
                        {bill.lines.length === 0 ? (
                            <p className="p-6 text-sm text-muted-foreground">
                                There are no charges or payments to show yet.
                            </p>
                        ) : (
                            <ul className="flex flex-col divide-y divide-border/30 px-5 sm:px-6">
                                {bill.lines.map((line, index) => (
                                    <li
                                        key={index}
                                        className="flex items-start justify-between gap-4 py-5"
                                    >
                                        <span className="flex min-w-0 flex-col gap-1">
                                            <span className="text-sm font-medium text-pretty">
                                                {line.description}
                                            </span>
                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                {line.date}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-sm font-medium tabular-nums">
                                            {money(line.amount, bill.currency)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                    <DocumentList documents={documents} />
                </div>
                <aside className="flex min-w-0 flex-col gap-6 lg:col-span-2">
                    <section
                        className="flex flex-col gap-5 rounded-2xl border border-border/40 bg-card p-6"
                        aria-labelledby="checkout-title"
                    >
                        <div className="flex items-center gap-3">
                            <LogOut
                                className="size-5 text-primary"
                                aria-hidden
                            />
                            <h2
                                id="checkout-title"
                                className="text-lg font-semibold"
                            >
                                Check-out
                            </h2>
                        </div>
                        {bill.canCheckOut && (
                            <>
                                <p className="text-sm leading-relaxed text-muted-foreground">
                                    Ready to leave? Check out here to complete
                                    your stay.
                                </p>
                                <Button
                                    size="lg"
                                    className="min-h-12 w-full"
                                    disabled={busy}
                                    focusableWhenDisabled
                                    onClick={() =>
                                        router.post(
                                            checkOut.url({ token }),
                                            {},
                                            {
                                                onStart: () => setBusy(true),
                                                onFinish: () => setBusy(false),
                                            },
                                        )
                                    }
                                >
                                    {busy && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Check out now
                                    <ArrowRight
                                        data-icon="inline-end"
                                        aria-hidden
                                    />
                                </Button>
                            </>
                        )}
                        {bill.checkOutAsked && (
                            <div
                                role="status"
                                className="flex items-start gap-3 rounded-xl bg-muted/50 p-4"
                            >
                                <CircleCheck
                                    className="mt-0.5 size-5 shrink-0 text-primary"
                                    aria-hidden
                                />
                                <div className="flex flex-col gap-1">
                                    <h3 className="text-sm font-medium">
                                        Check-out requested
                                    </h3>
                                    <p className="text-sm leading-relaxed text-muted-foreground">
                                        The front desk has your request and will
                                        prepare your bill.
                                    </p>
                                </div>
                            </div>
                        )}
                        {!bill.canCheckOut && bill.checkOutOpensOn && (
                            <p className="flex items-start gap-2 text-sm leading-relaxed text-muted-foreground">
                                <Clock
                                    className="mt-0.5 size-4 shrink-0"
                                    aria-hidden
                                />
                                <span>
                                    Online check-out opens on{' '}
                                    {bill.checkOutOpensOn}. For an earlier
                                    departure, ask the front desk.
                                </span>
                            </p>
                        )}
                        {!bill.canCheckOut && !bill.checkOutAsked && (
                            <>
                                <p className="text-sm leading-relaxed text-muted-foreground">
                                    Ask the front desk to arrange check-out if
                                    you’re leaving early or need help settling
                                    your bill.
                                </p>
                                <Form
                                    action={ask.url({ token })}
                                    method="post"
                                    resetOnSuccess
                                >
                                    {({ processing, errors }) => (
                                        <div className="flex flex-col gap-4">
                                            <Field data-invalid={!!errors.note}>
                                                <FieldLabel htmlFor="note">
                                                    Note for the team (optional)
                                                </FieldLabel>
                                                <Textarea
                                                    id="note"
                                                    name="note"
                                                    maxLength={900}
                                                    placeholder="e.g. We plan to leave at 10 am"
                                                    className="min-h-28 text-base"
                                                    aria-invalid={!!errors.note}
                                                />
                                                <FieldError>
                                                    {errors.note}
                                                </FieldError>
                                            </Field>
                                            <Button
                                                type="submit"
                                                size="lg"
                                                variant="outline"
                                                className="min-h-12 w-full"
                                                disabled={processing}
                                                focusableWhenDisabled
                                            >
                                                {processing && (
                                                    <Spinner data-icon="inline-start" />
                                                )}
                                                Request check-out
                                            </Button>
                                        </div>
                                    )}
                                </Form>
                            </>
                        )}
                    </section>
                </aside>
            </div>
            {bill.canPay && (
                <div className="border-t border-border/40 pt-8">
                    <PaymentStep
                        token={token}
                        amount={bill.due}
                        description="Pay the outstanding amount on your bill online."
                    />
                </div>
            )}
        </GuestFrame>
    );
}
