import { useEffect, useRef, useState } from 'react';
import { MoneyText } from '@/components/pms/money-text';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export type CfdCurrency = App.Data.Property.CurrencyData | null;

export type CfdLine = {
    id: number;
    itemName: string;
    variantName: string;
    quantity: string;
    isWeighed: boolean;
    weightUnit: string | null;
    weightUnitDivisor: string | null;
    modifiers: string[];
    lineGross: string;
    discountAmount: string;
    isEntitlementCovered: boolean;
    isComboPart: boolean;
};

export type CfdOrder = {
    orderNumber: string | null;
    diningOptionName: string;
    tableCode: string | null;
    subtotal: string;
    discountTotal: string;
    serviceChargeTotal: string;
    taxTotal: string;
    roundingAdjustment: string;
    grandTotal: string;
    netPayable: string;
    lines: CfdLine[];
    payments: {
        tender: string;
        amount: string;
        tendered: string | null;
        change: string | null;
    }[];
    totalChange: string;
    claimQrSvg: string | null;
    settledAt: string | null;
};

export type CfdState = {
    mode: 'idle' | 'active' | 'checkout' | 'settled';
    propertyName: string;
    propertyLogoUrl: string | null;
    outletName: string;
    registerName: string;
    welcomeMessage: string | null;
    duitnowQrUrl: string | null;
    currency: CfdCurrency;
    order: CfdOrder | null;
};

function quantityPrefix(line: CfdLine): string {
    if (line.isWeighed && line.weightUnit !== null) {
        const divisor = Number(line.weightUnitDivisor ?? '1');
        const total = Number(line.quantity) * (divisor > 0 ? divisor : 1);
        const formatted = Number.isInteger(total)
            ? String(total)
            : total.toFixed(2).replace(/\.?0+$/, '');

        return `${formatted} ${line.weightUnit}`;
    }

    const qty = Number(line.quantity);

    return `${Number.isInteger(qty) ? qty : line.quantity} ×`;
}

/** The idle screen: the property, the outlet and the register, with the outlet's welcome message. */
export function CfdIdleView({ state }: { state: CfdState }) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 p-10 text-center">
            {state.propertyLogoUrl !== null && (
                <img
                    src={state.propertyLogoUrl}
                    alt={state.propertyName}
                    className="max-h-28 max-w-64 object-contain"
                />
            )}
            <div className="flex flex-col gap-2">
                <h1 className="text-4xl font-semibold">{state.propertyName}</h1>
                <p className="text-2xl text-muted-foreground">
                    {state.outletName}
                </p>
                <p className="text-lg text-muted-foreground">
                    {state.registerName}
                </p>
            </div>
            {state.welcomeMessage !== null && (
                <p className="max-w-2xl text-3xl font-medium">
                    {state.welcomeMessage}
                </p>
            )}
        </div>
    );
}

function TotalsRow({
    label,
    amount,
    currency,
    strong = false,
}: {
    label: string;
    amount: string;
    currency: CfdCurrency;
    strong?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex items-baseline justify-between gap-4',
                strong && 'text-2xl font-semibold',
            )}
        >
            <span className={strong ? undefined : 'text-muted-foreground'}>
                {label}
            </span>
            <MoneyText amount={amount} currency={currency} />
        </div>
    );
}

/** The open check: the itemized lines on the left, the running totals (and the static DuitNow QR at checkout) on the right. */
export function CfdActiveOrderView({ state }: { state: CfdState }) {
    const order = state.order;
    const listRef = useRef<HTMLUListElement>(null);
    const lineCount = order?.lines.length ?? 0;

    useEffect(() => {
        const list = listRef.current;

        if (list !== null) {
            list.scrollTop = list.scrollHeight;
        }
    }, [lineCount]);

    if (order === null) {
        return <CfdIdleView state={state} />;
    }

    return (
        <div className="grid min-h-svh gap-6 p-8 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <section className="flex min-h-0 flex-col gap-4">
                <header className="flex flex-wrap items-baseline gap-3">
                    <h1 className="text-3xl font-semibold">
                        {order.tableCode !== null
                            ? `Table ${order.tableCode}`
                            : (order.orderNumber ?? 'Your order')}
                    </h1>
                    <span className="text-lg text-muted-foreground">
                        {order.diningOptionName}
                    </span>
                </header>
                <ul
                    ref={listRef}
                    className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto"
                >
                    {order.lines.map((line) => (
                        <li
                            key={line.id}
                            className={cn(
                                'flex items-start justify-between gap-4 rounded-lg border p-4',
                                line.isComboPart && 'ms-6 border-dashed',
                            )}
                        >
                            <div className="flex flex-col gap-1">
                                <span className="text-xl font-medium">
                                    <span className="tabular-nums">
                                        {quantityPrefix(line)}
                                    </span>{' '}
                                    {line.itemName}
                                    {line.variantName !== '' &&
                                        line.variantName !== 'Regular' &&
                                        ` (${line.variantName})`}
                                </span>
                                {line.modifiers.length > 0 && (
                                    <span className="text-base text-muted-foreground">
                                        {line.modifiers.join(', ')}
                                    </span>
                                )}
                                {line.isEntitlementCovered && (
                                    <Badge variant="secondary">Included</Badge>
                                )}
                            </div>
                            <div className="flex flex-col items-end gap-1 text-xl">
                                <MoneyText
                                    amount={line.lineGross}
                                    currency={state.currency}
                                />
                                {Number(line.discountAmount) !== 0 && (
                                    <span className="text-base text-success">
                                        −
                                        <MoneyText
                                            amount={line.discountAmount}
                                            currency={state.currency}
                                        />
                                    </span>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            </section>

            <section className="flex flex-col gap-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Your total</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <TotalsRow
                            label="Subtotal"
                            amount={order.subtotal}
                            currency={state.currency}
                        />
                        {Number(order.discountTotal) !== 0 && (
                            <TotalsRow
                                label="Discounts"
                                amount={order.discountTotal}
                                currency={state.currency}
                            />
                        )}
                        {Number(order.serviceChargeTotal) !== 0 && (
                            <TotalsRow
                                label="Service charge"
                                amount={order.serviceChargeTotal}
                                currency={state.currency}
                            />
                        )}
                        {Number(order.taxTotal) !== 0 && (
                            <TotalsRow
                                label="Tax"
                                amount={order.taxTotal}
                                currency={state.currency}
                            />
                        )}
                        {Number(order.roundingAdjustment) !== 0 && (
                            <TotalsRow
                                label="Rounding"
                                amount={order.roundingAdjustment}
                                currency={state.currency}
                            />
                        )}
                        <div className="border-t pt-3">
                            <TotalsRow
                                label="Total"
                                amount={order.netPayable}
                                currency={state.currency}
                                strong
                            />
                        </div>
                    </CardContent>
                </Card>
                {state.mode === 'checkout' && state.duitnowQrUrl !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Scan to pay with DuitNow</CardTitle>
                        </CardHeader>
                        <CardContent className="flex justify-center">
                            <img
                                src={state.duitnowQrUrl}
                                alt="DuitNow QR code"
                                className="size-56 bg-white object-contain p-2"
                            />
                        </CardContent>
                    </Card>
                )}
            </section>
        </div>
    );
}

/**
 * The thank-you screen: the paid amount, the change due and, for a walk-in sale, the e-invoice claim QR. It counts down
 * from `seconds` and returns to idle on its own, so the next guest never sees this receipt.
 */
export function CfdSettledView({
    state,
    seconds = 30,
    onExpire,
}: {
    state: CfdState;
    seconds?: number;
    onExpire: () => void;
}) {
    const order = state.order;
    const [remaining, setRemaining] = useState(() => {
        const settledAt = state.order?.settledAt;

        if (settledAt == null) {
            return seconds;
        }

        const elapsed = (Date.now() - new Date(settledAt).getTime()) / 1000;

        return Math.min(seconds, Math.max(0, Math.round(seconds - elapsed)));
    });

    useEffect(() => {
        const timer = window.setInterval(
            () => setRemaining((value) => Math.max(0, value - 1)),
            1000,
        );

        return () => window.clearInterval(timer);
    }, []);

    useEffect(() => {
        if (remaining === 0) {
            onExpire();
        }
    }, [remaining, onExpire]);

    if (order === null) {
        return <CfdIdleView state={state} />;
    }

    const change = Number(order.totalChange);

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-8 p-10 text-center">
            <h1 className="text-6xl font-semibold text-success">Thank you!</h1>
            {order.orderNumber !== null && (
                <p className="text-xl text-muted-foreground tabular-nums">
                    Receipt {order.orderNumber}
                </p>
            )}
            <div className="flex flex-col items-center gap-2">
                <span className="text-lg text-muted-foreground">Paid</span>
                <span className="text-5xl font-semibold">
                    <MoneyText
                        amount={order.grandTotal}
                        currency={state.currency}
                    />
                </span>
            </div>
            {change > 0 && (
                <div className="flex flex-col items-center gap-2">
                    <span className="text-lg text-muted-foreground">
                        Change due
                    </span>
                    <span className="text-6xl font-semibold text-success">
                        <MoneyText
                            amount={order.totalChange}
                            currency={state.currency}
                        />
                    </span>
                </div>
            )}
            {order.claimQrSvg !== null && (
                <div className="flex flex-col items-center gap-3">
                    <p className="text-xl font-medium">
                        Scan with your phone to claim your e-Invoice
                    </p>
                    <div
                        className="size-56 bg-white p-3 [&>svg]:size-full"
                        role="img"
                        aria-label="QR code to claim an e-Invoice"
                        dangerouslySetInnerHTML={{ __html: order.claimQrSvg }}
                    />
                </div>
            )}
        </div>
    );
}
