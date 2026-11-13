import { Link, router, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    CircleAlert,
    CreditCard,
    Landmark,
    LockKeyhole,
    RotateCcw,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { contact, payment } from '@/routes/concierge';

const CHECKOUT_SRC = 'https://checkout.razorpay.com/v1/checkout.js';

type Order = {
    key: string;
    order_id: string;
    amount: number;
    currency: string;
    name: string;
    callback_url: string;
};

type Checkout = { open: () => void };

declare global {
    interface Window {
        Razorpay?: new (options: Record<string, unknown>) => Checkout;
    }
}

/** Loads Curlec's checkout script once. It is Razorpay's checkout, which Curlec documents. */
function loadCheckout(): Promise<void> {
    if (window.Razorpay) {
        return Promise.resolve();
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = CHECKOUT_SRC;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('checkout script'));
        document.head.appendChild(script);
    });
}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** The payment step: the guest pays what is still due in Curlec's checkout, then the page confirms it with the server. */
export function PaymentStep({
    token,
    amount,
    description = 'Pay the amount due to continue with check-in.',
}: {
    token: string;
    amount: string;
    description?: string;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const { property, stay } = usePage<{
        property: App.Data.Property.PropertySummaryData | null;
        stay?: App.Data.Concierge.ConciergeStayData;
    }>().props;
    const propertyCode = property?.code ?? stay?.property.code;

    async function start() {
        setBusy(true);
        setError(null);

        try {
            const response = await fetch(payment.url({ token }), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
            });
            const body = await response.json();

            if (response.status === 409 && body.code === 'nothing_due') {
                // The payment already went through (for example after a bank redirect), so show the next step.
                router.reload();

                return;
            }

            if (!response.ok) {
                throw new Error(body.message ?? 'payment');
            }

            await loadCheckout();

            const order = body as Order;
            const checkout = new window.Razorpay!({
                key: order.key,
                order_id: order.order_id,
                amount: order.amount,
                currency: order.currency,
                name: order.name,
                // Bank (FPX) payments leave the page, so the result comes back by redirect, not a JS handler.
                redirect: true,
                callback_url: order.callback_url,
                modal: { ondismiss: () => setBusy(false) },
            });
            checkout.open();
        } catch (failure) {
            setBusy(false);
            setError(
                failure instanceof Error &&
                    failure.message !== 'checkout script'
                    ? failure.message
                    : 'Online payment could not open. Check your connection and try again, or pay at the front desk.',
            );
        }
    }

    return (
        <section
            aria-labelledby="payment-title"
            className="mx-auto flex w-full max-w-md flex-col items-center gap-6 py-4 text-center sm:py-6"
        >
            <div className="flex size-16 items-center justify-center rounded-full bg-muted text-primary">
                <Wallet className="size-7" strokeWidth={1.5} aria-hidden />
            </div>
            <div className="flex flex-col gap-3">
                <h2
                    id="payment-title"
                    className="text-2xl font-semibold tracking-tight sm:text-3xl"
                >
                    Complete your payment
                </h2>
                <p className="text-sm leading-relaxed text-pretty text-muted-foreground">
                    {description}
                </p>
            </div>

            <div className="flex w-full flex-col gap-5 border-y border-border/40 py-7">
                <div className="flex flex-col gap-2">
                    <p className="text-sm text-muted-foreground">Amount due</p>
                    <p className="flex items-baseline justify-center gap-2 font-semibold tracking-tight tabular-nums">
                        <span className="text-xl text-muted-foreground">
                            RM
                        </span>
                        <span className="text-5xl sm:text-6xl">{amount}</span>
                    </p>
                </div>
                <div className="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm text-muted-foreground">
                    <span className="flex items-center gap-2">
                        <CreditCard className="size-4" aria-hidden /> Card
                    </span>
                    <span className="flex items-center gap-2">
                        <Landmark className="size-4" aria-hidden /> Online
                        banking
                    </span>
                </div>
            </div>

            {error && (
                <Alert className="gap-y-2 p-4">
                    <CircleAlert aria-hidden />
                    <AlertTitle>
                        Online payment is temporarily unavailable
                    </AlertTitle>
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}

            <div className="flex w-full flex-col items-center gap-4">
                <Button
                    size="lg"
                    className="h-12 w-full rounded-lg"
                    disabled={busy}
                    focusableWhenDisabled
                    onClick={start}
                >
                    {busy && <Spinner data-icon="inline-start" />}
                    {error ? 'Try again' : `Pay RM ${amount}`}
                    {error ? (
                        <RotateCcw data-icon="inline-end" aria-hidden />
                    ) : (
                        <ArrowRight data-icon="inline-end" aria-hidden />
                    )}
                </Button>
                {error ? (
                    propertyCode && (
                        <Link
                            href={contact.url({ property: propertyCode })}
                            className={buttonVariants({
                                variant: 'outline',
                                size: 'lg',
                                className: 'min-h-12 w-full',
                            })}
                        >
                            Contact front desk
                        </Link>
                    )
                ) : (
                    <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <LockKeyhole className="size-3.5" aria-hidden /> Secure
                        payment
                    </p>
                )}
            </div>
        </section>
    );
}
