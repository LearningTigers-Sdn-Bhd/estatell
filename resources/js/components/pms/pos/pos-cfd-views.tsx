import { CheckCircle2, QrCode, Receipt, Utensils } from 'lucide-react';
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

    return `${Number.isInteger(qty) ? qty : line.quantity}×`;
}

function formatClock(now: Date): { time: string; date: string } {
    return {
        time: now.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
        }),
        date: now.toLocaleDateString([], {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
        }),
    };
}

/**
 * The idle screen: serene hospitality welcome canvas with live clock, property logo, outlet badge, and optional welcome
 * message. Uses semantic tokens only.
 */
export function CfdIdleView({ state }: { state: CfdState }) {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 15000);

        return () => window.clearInterval(timer);
    }, []);

    const { time, date } = formatClock(now);

    return (
        <div className="flex min-h-dvh flex-col justify-between border-t-4 border-primary bg-background p-8 lg:p-12">
            <header className="flex items-center justify-between gap-4">
                <Badge
                    variant="secondary"
                    className="px-3 py-1 text-sm font-medium"
                >
                    {state.outletName}
                </Badge>
                <div className="text-right">
                    <p className="text-xl font-semibold tabular-nums">{time}</p>
                    <p className="text-sm text-muted-foreground">{date}</p>
                </div>
            </header>

            <main className="mx-auto flex max-w-3xl flex-col items-center gap-6 py-10 text-center">
                {state.propertyLogoUrl !== null && (
                    <div className="flex h-28 items-center justify-center rounded-2xl border bg-card px-8 py-4 shadow-xs">
                        <img
                            src={state.propertyLogoUrl}
                            alt={state.propertyName}
                            width={224}
                            height={80}
                            fetchPriority="high"
                            className="max-h-20 max-w-56 object-contain"
                        />
                    </div>
                )}
                <div className="flex flex-col items-center gap-3">
                    <h1 className="font-heading text-4xl font-semibold tracking-tight lg:text-6xl">
                        {state.propertyName}
                    </h1>
                    <p className="max-w-2xl text-xl text-muted-foreground lg:text-2xl">
                        {state.welcomeMessage ??
                            'Welcome. We are ready whenever you are.'}
                    </p>
                </div>
            </main>

            <footer className="flex items-center justify-between text-sm text-muted-foreground">
                <span>{state.registerName}</span>
                <span>{state.outletName}</span>
            </footer>
        </div>
    );
}

function TotalsRow({
    label,
    amount,
    currency,
    tone = 'default',
}: {
    label: string;
    amount: string;
    currency: CfdCurrency;
    tone?: 'default' | 'discount';
}) {
    return (
        <div className="flex items-baseline justify-between gap-4 text-lg">
            <span className="text-muted-foreground">{label}</span>
            <span
                className={cn(
                    'font-medium tabular-nums',
                    tone === 'discount' && 'text-success',
                )}
            >
                <MoneyText amount={amount} currency={currency} />
            </span>
        </div>
    );
}

/**
 * The active check & checkout view: generous itemized order list on the left, elevated order summary and hero emerald
 * total banner (plus static DuitNow QR at checkout) on the right.
 */
export function CfdActiveOrderView({ state }: { state: CfdState }) {
    const order = state.order;
    const listRef = useRef<HTMLUListElement>(null);
    const lineCount = order?.lines.length ?? 0;

    useEffect(() => {
        const list = listRef.current;

        if (list !== null) {
            const reduced = window.matchMedia(
                '(prefers-reduced-motion: reduce)',
            ).matches;
            list.scrollTo({
                top: list.scrollHeight,
                behavior: reduced ? 'auto' : 'smooth',
            });
        }
    }, [lineCount]);

    if (order === null) {
        return <CfdIdleView state={state} />;
    }

    const totalItems = order.lines
        .filter((line) => !line.isComboPart)
        .reduce(
            (sum, line) => sum + (line.isWeighed ? 1 : Number(line.quantity)),
            0,
        );

    return (
        <div className="flex min-h-svh flex-col bg-background">
            <header className="flex flex-wrap items-center justify-between gap-4 border-b bg-card px-8 py-4">
                <div className="flex items-center gap-4">
                    {state.propertyLogoUrl !== null && (
                        <img
                            src={state.propertyLogoUrl}
                            alt={state.propertyName}
                            width={112}
                            height={40}
                            className="max-h-10 max-w-28 object-contain"
                        />
                    )}
                    <div className="flex flex-col">
                        <span className="font-heading text-xl font-semibold">
                            {state.outletName}
                        </span>
                        <span className="text-sm text-muted-foreground">
                            {state.propertyName}
                        </span>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="outline" className="px-3 py-1 text-sm">
                        {order.diningOptionName}
                    </Badge>
                    {order.tableCode !== null && (
                        <Badge
                            variant="secondary"
                            className="px-3 py-1 text-sm"
                        >
                            Table {order.tableCode}
                        </Badge>
                    )}
                    <Badge
                        variant="secondary"
                        className="px-3 py-1 text-sm tabular-nums"
                    >
                        {totalItems} {totalItems === 1 ? 'item' : 'items'}
                    </Badge>
                </div>
            </header>

            <div className="grid min-h-0 flex-1 gap-8 p-8 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <section className="flex min-h-0 flex-col gap-4">
                    <div className="flex items-baseline justify-between gap-3">
                        <h1 className="font-heading text-2xl font-semibold lg:text-3xl">
                            Your order
                        </h1>
                        {order.orderNumber !== null && (
                            <span className="text-sm text-muted-foreground tabular-nums">
                                {order.orderNumber}
                            </span>
                        )}
                    </div>
                    <ul
                        ref={listRef}
                        className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto pr-1"
                    >
                        {order.lines.map((line) => (
                            <li
                                key={line.id}
                                className={cn(
                                    'flex items-start justify-between gap-4 rounded-xl border bg-card p-4 shadow-2xs',
                                    line.isComboPart &&
                                        'ms-8 border-dashed bg-muted/30 py-3',
                                )}
                            >
                                <div className="flex min-w-0 items-start gap-3">
                                    <span className="mt-0.5 inline-flex min-w-11 shrink-0 items-center justify-center rounded-lg bg-secondary px-2.5 py-1 text-base font-semibold text-secondary-foreground tabular-nums">
                                        {quantityPrefix(line)}
                                    </span>
                                    <div className="flex min-w-0 flex-col gap-1">
                                        <span className="text-xl leading-snug font-medium">
                                            {line.itemName}
                                            {line.variantName !== '' &&
                                                line.variantName !==
                                                    'Regular' && (
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        · {line.variantName}
                                                    </span>
                                                )}
                                        </span>
                                        {line.modifiers.length > 0 && (
                                            <span className="text-base text-muted-foreground">
                                                {line.modifiers.join(', ')}
                                            </span>
                                        )}
                                        {line.isEntitlementCovered && (
                                            <Badge
                                                variant="secondary"
                                                className="w-fit"
                                            >
                                                <Utensils
                                                    data-icon="inline-start"
                                                    aria-hidden="true"
                                                />
                                                Included meal
                                            </Badge>
                                        )}
                                    </div>
                                </div>
                                <div className="flex shrink-0 flex-col items-end gap-0.5 text-xl font-medium tabular-nums">
                                    <MoneyText
                                        amount={line.lineGross}
                                        currency={state.currency}
                                    />
                                    {Number(line.discountAmount) > 0 && (
                                        <span className="text-sm font-medium text-success">
                                            <MoneyText
                                                amount={`-${line.discountAmount}`}
                                                currency={state.currency}
                                            />
                                        </span>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>

                <aside className="flex flex-col gap-5">
                    <Card className="overflow-hidden">
                        <CardHeader className="pb-3">
                            <CardTitle className="font-heading text-xl">
                                Order summary
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            <TotalsRow
                                label="Subtotal"
                                amount={order.subtotal}
                                currency={state.currency}
                            />
                            {Number(order.discountTotal) > 0 && (
                                <TotalsRow
                                    label="Discounts"
                                    amount={`-${order.discountTotal}`}
                                    currency={state.currency}
                                    tone="discount"
                                />
                            )}
                            {Number(order.serviceChargeTotal) > 0 && (
                                <TotalsRow
                                    label="Service charge"
                                    amount={order.serviceChargeTotal}
                                    currency={state.currency}
                                />
                            )}
                            {Number(order.taxTotal) > 0 && (
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
                        </CardContent>
                        <div className="flex items-baseline justify-between gap-4 bg-primary px-6 py-5 text-primary-foreground">
                            <span className="text-xl font-medium">
                                {state.mode === 'checkout'
                                    ? 'Total to pay'
                                    : 'Total'}
                            </span>
                            <span className="text-4xl font-semibold tracking-tight tabular-nums lg:text-5xl">
                                <MoneyText
                                    amount={order.netPayable}
                                    currency={state.currency}
                                />
                            </span>
                        </div>
                    </Card>

                    {state.mode === 'checkout' &&
                        state.duitnowQrUrl !== null && (
                            <Card className="border-primary/40">
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 font-heading text-xl">
                                        <QrCode
                                            aria-hidden
                                            className="size-5 text-primary"
                                        />
                                        Scan to pay with DuitNow
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-col items-center gap-3">
                                    <div className="rounded-xl border bg-primary-foreground p-3 shadow-2xs">
                                        <img
                                            src={state.duitnowQrUrl}
                                            alt="DuitNow QR code"
                                            width={208}
                                            height={208}
                                            className="size-52 object-contain"
                                        />
                                    </div>
                                    <p className="text-center text-sm text-muted-foreground">
                                        Show your payment confirmation to the
                                        cashier once complete.
                                    </p>
                                </CardContent>
                            </Card>
                        )}
                </aside>
            </div>
        </div>
    );
}

/**
 * The thank-you screen: net paid amount (including BNM cash rounding), payment methods, change due, and (for eligible
 * walk-in sales) the LHDN e-Invoice self-claim QR. Automatically returns to idle after `seconds`.
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
    const hasClaimQr = order.claimQrSvg !== null;
    const progressPercent = Math.max(
        0,
        Math.min(100, Math.round((remaining / seconds) * 100)),
    );

    return (
        <div className="relative flex min-h-svh flex-col justify-between bg-background p-8 lg:p-12">
            <header className="flex items-center justify-between gap-4">
                <div className="flex items-center gap-3">
                    {state.propertyLogoUrl !== null && (
                        <img
                            src={state.propertyLogoUrl}
                            alt={state.propertyName}
                            width={112}
                            height={40}
                            className="max-h-10 max-w-28 object-contain"
                        />
                    )}
                    <span className="font-heading text-lg font-semibold">
                        {state.outletName}
                    </span>
                </div>
                {order.orderNumber !== null && (
                    <Badge
                        variant="outline"
                        className="px-3 py-1 text-sm tabular-nums"
                    >
                        <Receipt data-icon="inline-start" />
                        {order.orderNumber}
                    </Badge>
                )}
            </header>

            <main
                className={cn(
                    'mx-auto grid w-full max-w-5xl items-center gap-8 py-8',
                    hasClaimQr ? 'lg:grid-cols-2' : 'max-w-xl',
                )}
            >
                <div className="flex flex-col items-center gap-6 text-center">
                    <div className="flex size-20 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <CheckCircle2 className="size-11" aria-hidden />
                    </div>
                    <div className="flex flex-col gap-2">
                        <h1 className="font-heading text-5xl font-semibold tracking-tight lg:text-6xl">
                            Thank you!
                        </h1>
                        <p className="text-lg text-muted-foreground">
                            Your payment has been received.
                        </p>
                    </div>

                    <Card className="w-full">
                        <CardContent className="flex flex-col gap-4 pt-6">
                            <div className="flex items-baseline justify-between gap-4">
                                <span className="text-lg text-muted-foreground">
                                    Total paid
                                </span>
                                <span className="text-4xl font-semibold tabular-nums">
                                    <MoneyText
                                        amount={order.netPayable}
                                        currency={state.currency}
                                    />
                                </span>
                            </div>
                            {Number(order.roundingAdjustment) !== 0 && (
                                <div className="flex items-baseline justify-between gap-4 text-sm text-muted-foreground">
                                    <span>Includes cash rounding</span>
                                    <MoneyText
                                        amount={order.roundingAdjustment}
                                        currency={state.currency}
                                    />
                                </div>
                            )}
                            {order.payments.length > 0 && (
                                <div className="flex flex-col gap-1.5 border-t pt-3 text-base">
                                    {order.payments.map((payment, index) => (
                                        <div
                                            key={`${payment.tender}-${index}`}
                                            className="flex items-baseline justify-between gap-4 text-muted-foreground"
                                        >
                                            <span>{payment.tender}</span>
                                            <span className="tabular-nums">
                                                <MoneyText
                                                    amount={
                                                        payment.tendered ??
                                                        payment.amount
                                                    }
                                                    currency={state.currency}
                                                />
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            )}
                            {change > 0 && (
                                <div className="flex items-baseline justify-between gap-4 rounded-xl border border-primary/30 bg-primary/10 px-4 py-3">
                                    <span className="text-lg font-medium text-foreground">
                                        Change due
                                    </span>
                                    <span className="text-4xl font-semibold text-primary tabular-nums">
                                        <MoneyText
                                            amount={order.totalChange}
                                            currency={state.currency}
                                        />
                                    </span>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {hasClaimQr && (
                    <Card className="flex flex-col items-center p-8 text-center">
                        <CardHeader className="items-center pb-4">
                            <CardTitle className="font-heading text-2xl">
                                Need an e-Invoice?
                            </CardTitle>
                            <p className="text-base text-muted-foreground">
                                Scan this QR code with your phone to claim your
                                LHDN e-Invoice anytime this month.
                            </p>
                        </CardHeader>
                        <CardContent className="flex flex-col items-center gap-3">
                            <div
                                className="size-56 rounded-2xl border bg-primary-foreground p-4 shadow-xs [&>svg]:size-full"
                                role="img"
                                aria-label="QR code to claim an e-Invoice"
                                dangerouslySetInnerHTML={{
                                    __html: order.claimQrSvg!,
                                }}
                            />
                        </CardContent>
                    </Card>
                )}
            </main>

            <footer className="mx-auto flex w-full max-w-md flex-col items-center gap-2 text-center">
                <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        className="h-full bg-primary motion-safe:transition-[width] motion-safe:duration-1000 motion-safe:ease-linear"
                        style={{ width: `${progressPercent}%` }}
                    />
                </div>
                <p className="text-xs text-muted-foreground tabular-nums">
                    Returning to welcome screen in {remaining}s
                </p>
            </footer>
        </div>
    );
}
