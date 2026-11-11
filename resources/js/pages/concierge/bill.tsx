import { Form, router } from '@inertiajs/react';
import { useState } from 'react';
import { DocumentList } from '@/components/concierge/checked-out-step';
import { PaymentStep } from '@/components/concierge/payment-step';
import { GuestFrame } from '@/components/concierge/stay-menu';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
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
        <GuestFrame back={back} title="Bill and check-out">
            <ul className="flex flex-col divide-y rounded-lg border">
                {bill.lines.length === 0 && (
                    <li className="p-4 text-sm text-muted-foreground">
                        There is nothing on your bill yet.
                    </li>
                )}
                {bill.lines.map((line, index) => (
                    <li
                        key={index}
                        className="flex items-baseline justify-between gap-3 p-3 text-sm"
                    >
                        <span className="flex flex-col">
                            <span className="text-pretty">
                                {line.description}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {line.date}
                            </span>
                        </span>
                        <span className="font-medium tabular-nums">
                            {money(line.amount, bill.currency)}
                        </span>
                    </li>
                ))}
                <li className="flex items-baseline justify-between gap-3 bg-muted p-3 font-semibold">
                    <span>
                        {Number(bill.balance) < 0 ? 'Credit' : 'To pay'}
                    </span>
                    <span className="tabular-nums">
                        {money(
                            Number(bill.balance) < 0 ? bill.credit : bill.due,
                            bill.currency,
                        )}
                    </span>
                </li>
            </ul>

            {bill.canPay && <PaymentStep token={token} amount={bill.due} />}

            {Number(bill.credit) > 0 && (
                <p className="rounded-lg border p-4 text-sm text-pretty">
                    The property holds {money(bill.credit, bill.currency)} for
                    you, for example a deposit. The front desk gives it back
                    when you check out: please visit the desk.
                </p>
            )}

            {bill.canCheckOut && (
                <Button
                    size="lg"
                    className="h-12 w-full rounded-full"
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
                    {busy && <Spinner data-icon="inline-start" />}
                    Check out now
                </Button>
            )}

            {bill.checkOutAsked && (
                <p className="rounded-lg border p-4 text-sm text-pretty">
                    Check-out asked. The front desk has your request and will
                    prepare your bill.
                </p>
            )}

            {!bill.canCheckOut && !bill.checkOutAsked && (
                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold">
                        Ask the front desk to check you out
                    </h2>
                    <p className="text-sm text-pretty text-muted-foreground">
                        Use this if you cannot check out here, for example when
                        a deposit is held or you leave early.
                    </p>
                    <Form
                        action={ask.url({ token })}
                        method="post"
                        resetOnSuccess
                    >
                        {({ processing }) => (
                            <div className="flex flex-col gap-4">
                                <Field>
                                    <FieldLabel htmlFor="note">
                                        Anything we should know? (optional)
                                    </FieldLabel>
                                    <Textarea
                                        id="note"
                                        name="note"
                                        maxLength={900}
                                        placeholder="e.g. We leave at 10 am and need a taxi"
                                    />
                                </Field>
                                <Button
                                    type="submit"
                                    size="lg"
                                    variant="outline"
                                    className="h-12 w-full rounded-full"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Ask to check out
                                </Button>
                            </div>
                        )}
                    </Form>
                </section>
            )}

            {!bill.canCheckOut && bill.checkOutOpensOn && (
                <p className="text-sm text-pretty text-muted-foreground">
                    Check-out opens on your departure day,{' '}
                    {bill.checkOutOpensOn}. For an earlier check-out, ask the
                    front desk.
                </p>
            )}

            <DocumentList documents={documents} />
        </GuestFrame>
    );
}
