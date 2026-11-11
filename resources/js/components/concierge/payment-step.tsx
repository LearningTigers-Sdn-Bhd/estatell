import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { payment } from '@/routes/concierge';
import { confirm } from '@/routes/concierge/payment';

const CHECKOUT_SRC = 'https://checkout.razorpay.com/v1/checkout.js';

type Order = {
    key: string;
    order_id: string;
    amount: number;
    currency: string;
    name: string;
};

type CheckoutResult = {
    razorpay_order_id: string;
    razorpay_payment_id: string;
    razorpay_signature: string;
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
}: {
    token: string;
    amount: string;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

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
                handler: (result: CheckoutResult) =>
                    router.post(confirm.url({ token }), result),
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
        <div className="flex flex-col gap-6">
            <div className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold">Payment due</h2>
                <p className="text-sm text-pretty text-muted-foreground">
                    RM {amount} is due. Pay online now with your card or online
                    banking.
                </p>
            </div>

            {error && (
                <p
                    role="alert"
                    className="rounded-lg border border-destructive/50 p-3 text-sm text-pretty"
                >
                    {error}
                </p>
            )}

            <Button
                size="lg"
                className="h-12 w-full rounded-full"
                disabled={busy}
                focusableWhenDisabled
                onClick={start}
            >
                {busy && <Spinner data-icon="inline-start" />}
                Pay RM {amount}
            </Button>
        </div>
    );
}
