import {
    Check,
    Maximize2,
    Minimize2,
    QrCode,
    Receipt,
    Sparkles,
    Utensils,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { MoneyText } from '@/components/pms/money-text';
import { PosNavbar } from '@/components/pms/pos/pos-navbar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
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

function useLiveClock(): {
    time: string;
    digits: string;
    period: string;
    date: string;
} {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 15000);

        return () => window.clearInterval(timer);
    }, []);

    const time = now.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
    const parts = new Intl.DateTimeFormat([], {
        hour: '2-digit',
        minute: '2-digit',
    }).formatToParts(now);
    const period = parts.find((part) => part.type === 'dayPeriod')?.value ?? '';
    const digits = parts
        .filter((part) => part.type !== 'dayPeriod')
        .map((part) => part.value)
        .join('')
        .trim();

    const date = now.toLocaleDateString([], {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });

    return { time, digits, period, date };
}

function CfdFullscreenToggle() {
    const [isFullscreen, setIsFullscreen] = useState(false);

    useEffect(() => {
        const onChange = () =>
            setIsFullscreen(document.fullscreenElement !== null);
        document.addEventListener('fullscreenchange', onChange);

        return () => document.removeEventListener('fullscreenchange', onChange);
    }, []);

    const toggle = () => {
        if (document.fullscreenElement !== null) {
            void document.exitFullscreen().catch(() => undefined);

            return;
        }

        void document.documentElement
            .requestFullscreen?.()
            .catch(() => undefined);
    };

    const label = isFullscreen ? 'Exit fullscreen' : 'Enter fullscreen';

    return (
        <Tooltip>
            <TooltipTrigger
                render={
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-11"
                        aria-label={label}
                        onClick={toggle}
                    />
                }
            >
                {isFullscreen ? (
                    <Minimize2 className="size-4" aria-hidden="true" />
                ) : (
                    <Maximize2 className="size-4" aria-hidden="true" />
                )}
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}

function CfdShell({
    state,
    showNavbarClock = true,
    rightExtra,
    progressPercent,
    children,
}: {
    state: CfdState;
    showNavbarClock?: boolean;
    rightExtra?: ReactNode;
    progressPercent?: number;
    children: ReactNode;
}) {
    const { time, date } = useLiveClock();

    return (
        <div className="flex min-h-dvh flex-col bg-background lg:h-dvh lg:overflow-hidden">
            <div className="relative shrink-0">
                <PosNavbar
                    signage
                    title={state.outletName}
                    subtitle={`${state.propertyName} · ${state.registerName}`}
                    right={
                        <div className="flex items-center gap-2">
                            {rightExtra}
                            {showNavbarClock && (
                                <div className="hidden items-baseline gap-2.5 pr-1 sm:flex">
                                    <span className="text-xs text-muted-foreground">
                                        {date}
                                    </span>
                                    <span className="font-heading text-lg font-semibold text-sidebar-foreground tabular-nums">
                                        {time}
                                    </span>
                                </div>
                            )}
                            <CfdFullscreenToggle />
                        </div>
                    }
                />
                {progressPercent !== undefined && (
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-x-0 bottom-0 h-1 bg-transparent"
                    >
                        <div
                            className="h-full bg-primary motion-safe:transition-[width] motion-safe:duration-1000 motion-safe:ease-linear"
                            style={{ width: `${progressPercent}%` }}
                        />
                    </div>
                )}
            </div>
            {children}
        </div>
    );
}

/**
 * The idle screen: full-bleed boutique hospitality split with a deep forest brand hero stage
 * and a serene ambient clock rail.
 */
export function CfdIdleView({ state }: { state: CfdState }) {
    const { digits, period, date } = useLiveClock();

    return (
        <CfdShell state={state} showNavbarClock={false}>
            <main className="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[minmax(0,1.45fr)_minmax(22rem,0.95fr)]">
                <section className="flex flex-col justify-between gap-10 bg-brand-forest px-8 py-10 text-primary-foreground sm:px-12 lg:px-16 lg:py-14">
                    <div className="flex items-center">
                        {state.propertyLogoUrl !== null ? (
                            <div className="inline-flex h-16 items-center justify-center rounded-2xl bg-primary-foreground px-5 py-2.5 shadow-xs">
                                <img
                                    src={state.propertyLogoUrl}
                                    alt={state.propertyName}
                                    width={180}
                                    height={48}
                                    fetchPriority="high"
                                    className="max-h-11 max-w-44 object-contain"
                                />
                            </div>
                        ) : (
                            <span className="text-xs font-semibold tracking-wider text-primary-foreground/75 uppercase">
                                Welcome
                            </span>
                        )}
                    </div>

                    <div className="flex flex-col gap-5 py-4">
                        <h1 className="max-w-2xl font-heading text-5xl leading-none font-bold tracking-tight text-primary-foreground sm:text-6xl lg:text-7xl">
                            {state.propertyName}
                        </h1>
                        {state.welcomeMessage && (
                            <p className="max-w-xl text-xl leading-relaxed text-primary-foreground/80 lg:text-2xl">
                                {state.welcomeMessage}
                            </p>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-4 border-t border-primary-foreground/15 pt-5 text-sm text-primary-foreground/75">
                        <span>{state.outletName}</span>
                        <span>{state.registerName}</span>
                    </div>
                </section>

                <aside
                    aria-label="Local time"
                    className="flex flex-col justify-center bg-muted/40 px-8 py-10 sm:px-12 lg:border-l lg:px-14 lg:py-14"
                >
                    <div className="flex flex-col gap-3">
                        <div className="flex items-baseline gap-2.5">
                            <span className="font-heading text-6xl leading-none font-bold tracking-tight text-foreground tabular-nums sm:text-7xl">
                                {digits}
                            </span>
                            {period !== '' && (
                                <span className="font-heading text-2xl font-semibold text-muted-foreground uppercase">
                                    {period}
                                </span>
                            )}
                        </div>
                        <p className="text-lg font-medium text-muted-foreground">
                            {date}
                        </p>
                    </div>
                </aside>
            </main>
        </CfdShell>
    );
}

function SummaryRow({
    label,
    amount,
    currency,
}: {
    label: string;
    amount: string;
    currency: CfdCurrency;
}) {
    return (
        <div className="flex items-baseline justify-between gap-4 text-base lg:text-lg">
            <span className="text-primary-foreground/80">{label}</span>
            <span className="font-semibold text-primary-foreground tabular-nums">
                <MoneyText amount={amount} currency={currency} />
            </span>
        </div>
    );
}

/**
 * The active check & checkout view: ruled itemized receipt on the left and a full-bleed
 * deep forest total & DuitNow checkout rail on the right.
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
    const isCheckout = state.mode === 'checkout';

    return (
        <CfdShell state={state}>
            <main className="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[minmax(0,1fr)_27rem] xl:grid-cols-[minmax(0,1fr)_31rem]">
                <section className="flex min-h-0 flex-col bg-card">
                    <div className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b bg-muted/25 px-6 py-5 lg:px-10">
                        <div className="flex items-baseline gap-3">
                            <h1 className="font-heading text-2xl font-bold tracking-tight text-foreground lg:text-3xl">
                                Your order
                            </h1>
                            {order.orderNumber !== null && (
                                <span className="text-sm text-muted-foreground tabular-nums">
                                    {order.orderNumber}
                                </span>
                            )}
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge
                                variant="outline"
                                className="px-3 py-1 text-sm"
                            >
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
                                {totalItems}{' '}
                                {totalItems === 1 ? 'item' : 'items'}
                            </Badge>
                        </div>
                    </div>

                    <ul
                        ref={listRef}
                        role="list"
                        className="flex min-h-0 flex-1 flex-col divide-y overflow-y-auto px-6 lg:px-10"
                    >
                        {order.lines.map((line) => (
                            <li
                                key={line.id}
                                className={cn(
                                    'flex items-start justify-between gap-4 py-5',
                                    line.isComboPart && 'py-3.5 pl-14',
                                )}
                            >
                                <div className="flex min-w-0 items-start gap-4">
                                    <span className="inline-flex min-w-12 shrink-0 items-center justify-center rounded-xl bg-muted px-2.5 py-1.5 font-heading text-base font-bold text-foreground tabular-nums">
                                        {quantityPrefix(line)}
                                    </span>
                                    <div className="flex min-w-0 flex-col gap-1">
                                        <span className="font-heading text-lg leading-snug font-semibold text-foreground lg:text-xl">
                                            {line.itemName}
                                            {line.variantName !== '' &&
                                                line.variantName !==
                                                    'Regular' && (
                                                    <span className="font-normal text-muted-foreground">
                                                        {' '}
                                                        · {line.variantName}
                                                    </span>
                                                )}
                                        </span>
                                        {line.modifiers.length > 0 && (
                                            <span className="text-sm text-muted-foreground lg:text-base">
                                                {line.modifiers.join(', ')}
                                            </span>
                                        )}
                                        {line.isEntitlementCovered && (
                                            <Badge
                                                variant="secondary"
                                                className="mt-0.5 w-fit"
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

                                <div className="flex shrink-0 flex-col items-end gap-0.5 font-heading text-lg font-bold text-foreground tabular-nums lg:text-xl">
                                    <MoneyText
                                        amount={line.lineGross}
                                        currency={state.currency}
                                    />
                                    {Number(line.discountAmount) > 0 && (
                                        <span className="font-sans text-sm font-medium text-muted-foreground">
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

                <aside
                    aria-label="Order summary"
                    className="flex min-h-0 flex-col justify-between gap-6 overflow-y-auto bg-brand-forest px-7 py-7 text-primary-foreground lg:px-9 lg:py-8"
                >
                    <div className="flex flex-col gap-3">
                        <span className="text-xs font-semibold tracking-wider text-primary-foreground/75 uppercase">
                            {isCheckout ? 'Checkout' : 'Summary'}
                        </span>

                        <div className="mt-1 flex flex-col gap-2.5">
                            <SummaryRow
                                label="Subtotal"
                                amount={order.subtotal}
                                currency={state.currency}
                            />
                            {Number(order.discountTotal) > 0 && (
                                <SummaryRow
                                    label="Discounts"
                                    amount={`-${order.discountTotal}`}
                                    currency={state.currency}
                                />
                            )}
                            {Number(order.serviceChargeTotal) > 0 && (
                                <SummaryRow
                                    label="Service charge"
                                    amount={order.serviceChargeTotal}
                                    currency={state.currency}
                                />
                            )}
                            {Number(order.taxTotal) > 0 && (
                                <SummaryRow
                                    label="Tax"
                                    amount={order.taxTotal}
                                    currency={state.currency}
                                />
                            )}
                            {Number(order.roundingAdjustment) !== 0 && (
                                <SummaryRow
                                    label="Rounding"
                                    amount={order.roundingAdjustment}
                                    currency={state.currency}
                                />
                            )}
                        </div>

                        <div className="mt-3 flex flex-col gap-2 border-t border-primary-foreground/20 pt-5">
                            <span className="text-sm font-medium text-primary-foreground/80">
                                {isCheckout ? 'Total to pay' : 'Total'}
                            </span>
                            <span className="font-heading text-5xl leading-none font-bold tracking-tight text-primary-foreground tabular-nums xl:text-6xl">
                                <MoneyText
                                    amount={order.netPayable}
                                    currency={state.currency}
                                />
                            </span>
                        </div>
                    </div>

                    {isCheckout && state.duitnowQrUrl !== null && (
                        <div className="flex flex-col items-center gap-3.5 rounded-2xl bg-primary-foreground p-5 text-center text-brand-forest shadow-sm">
                            <div className="flex items-center gap-2 font-heading text-lg font-bold">
                                <QrCode
                                    className="size-5 shrink-0"
                                    aria-hidden="true"
                                />
                                <span>Scan to pay with DuitNow</span>
                            </div>
                            <img
                                src={state.duitnowQrUrl}
                                alt="DuitNow QR code"
                                width={192}
                                height={192}
                                className="size-44 rounded-lg object-contain lg:size-48"
                            />
                        </div>
                    )}
                </aside>
            </main>
        </CfdShell>
    );
}

/**
 * The thank-you screen: paid total, payment methods, high-contrast change due callout, and
 * optional LHDN e-Invoice self-claim QR. Automatically returns to idle after `seconds`.
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
        <CfdShell
            state={state}
            progressPercent={progressPercent}
            rightExtra={
                order.orderNumber !== null ? (
                    <Badge
                        variant="outline"
                        className="px-3 py-1 text-xs tabular-nums"
                    >
                        <Receipt data-icon="inline-start" aria-hidden="true" />
                        {order.orderNumber}
                    </Badge>
                ) : undefined
            }
        >
            <main
                className={cn(
                    'grid min-h-0 flex-1 grid-cols-1',
                    hasClaimQr &&
                        'lg:grid-cols-[minmax(0,1.2fr)_minmax(22rem,0.85fr)]',
                )}
            >
                <section className="relative flex flex-col justify-between gap-10 overflow-hidden bg-brand-forest px-8 py-10 text-primary-foreground sm:px-12 lg:px-16 lg:py-14">
                    <svg
                        aria-hidden="true"
                        viewBox="0 0 320 320"
                        fill="none"
                        className="pointer-events-none absolute -top-16 -right-16 size-80 text-primary-foreground/10"
                    >
                        <circle
                            cx="160"
                            cy="160"
                            r="128"
                            stroke="currentColor"
                            strokeWidth="1.5"
                            strokeDasharray="6 10"
                        />
                        <circle
                            cx="160"
                            cy="160"
                            r="84"
                            stroke="currentColor"
                            strokeWidth="1.5"
                        />
                        <path
                            d="M160 52L166 70L184 76L166 82L160 100L154 82L136 76L154 70L160 52Z"
                            fill="currentColor"
                        />
                        <path
                            d="M236 144L240 156L252 160L240 164L236 176L232 164L220 160L232 156L236 144Z"
                            fill="currentColor"
                        />
                    </svg>

                    <div
                        className={cn(
                            'relative my-auto flex w-full flex-col gap-8 motion-safe:animate-in motion-safe:duration-500 motion-safe:fade-in-0',
                            hasClaimQr ? 'max-w-xl' : 'mx-auto max-w-2xl',
                        )}
                    >
                        <div className="flex flex-col gap-5">
                            <div className="flex items-center gap-4">
                                <span
                                    aria-hidden="true"
                                    className="flex size-16 shrink-0 items-center justify-center rounded-full bg-primary-foreground text-brand-forest ring-8 ring-primary-foreground/15 motion-safe:animate-in motion-safe:duration-500 motion-safe:zoom-in-75"
                                >
                                    <Check className="size-8 stroke-[2.75]" />
                                </span>
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-primary-foreground/15 px-3.5 py-1 text-xs font-semibold tracking-wide text-primary-foreground uppercase">
                                    <Sparkles
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Paid
                                </span>
                            </div>

                            <div className="flex flex-col gap-2.5">
                                <h1 className="font-heading text-5xl leading-none font-bold tracking-tight text-primary-foreground lg:text-6xl">
                                    Thank you!
                                </h1>
                                <p className="max-w-lg text-lg leading-relaxed text-primary-foreground/85 lg:text-xl">
                                    Enjoy the rest of your day. We hope to see
                                    you again soon.
                                </p>
                            </div>
                        </div>

                        <div className="flex flex-col gap-4 border-t border-primary-foreground/20 pt-6">
                            <div className="flex flex-wrap items-baseline justify-between gap-4">
                                <span className="text-lg text-primary-foreground/80">
                                    Total paid
                                </span>
                                <span className="font-heading text-4xl font-bold tracking-tight text-primary-foreground tabular-nums lg:text-5xl">
                                    <MoneyText
                                        amount={order.netPayable}
                                        currency={state.currency}
                                    />
                                </span>
                            </div>

                            {Number(order.roundingAdjustment) !== 0 && (
                                <div className="flex items-baseline justify-between gap-4 text-sm text-primary-foreground/75">
                                    <span>Rounding</span>
                                    <span className="tabular-nums">
                                        <MoneyText
                                            amount={order.roundingAdjustment}
                                            currency={state.currency}
                                        />
                                    </span>
                                </div>
                            )}

                            {order.payments.length > 0 && (
                                <div className="flex flex-col gap-2 border-t border-primary-foreground/15 pt-4 text-base">
                                    {order.payments.map((payment, index) => (
                                        <div
                                            key={`${payment.tender}-${index}`}
                                            className="flex items-baseline justify-between gap-4 text-primary-foreground/85"
                                        >
                                            <span>{payment.tender}</span>
                                            <span className="font-medium tabular-nums">
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
                                <div className="mt-2 flex items-baseline justify-between gap-4 rounded-2xl bg-primary-foreground px-6 py-4 text-brand-forest shadow-sm">
                                    <span className="font-heading text-lg font-semibold">
                                        Change due
                                    </span>
                                    <span className="font-heading text-4xl font-bold tabular-nums">
                                        <MoneyText
                                            amount={order.totalChange}
                                            currency={state.currency}
                                        />
                                    </span>
                                </div>
                            )}
                        </div>
                    </div>

                    <div
                        className={cn(
                            'relative flex w-full flex-wrap items-center justify-between gap-3 border-t border-primary-foreground/15 pt-5 text-sm text-primary-foreground/75',
                            hasClaimQr ? 'max-w-xl' : 'mx-auto max-w-2xl',
                        )}
                    >
                        <span>Warm regards from {state.outletName}</span>
                        <span>{state.propertyName}</span>
                    </div>
                </section>

                {hasClaimQr && (
                    <aside
                        aria-label="e-Invoice QR claim"
                        className="flex flex-col items-center justify-center bg-muted/40 px-8 py-10 text-center lg:border-l lg:px-12"
                    >
                        <div className="flex w-full max-w-sm flex-col items-center gap-6 rounded-3xl border bg-card p-8 shadow-sm">
                            <div className="flex flex-col items-center gap-2">
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-muted px-3 py-1 text-xs font-semibold text-foreground">
                                    <QrCode
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Digital receipt
                                </span>
                                <h2 className="font-heading text-2xl font-bold tracking-tight text-foreground lg:text-3xl">
                                    e-Invoice
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Scan with your phone to claim anytime
                                </p>
                            </div>
                            <div
                                className="size-52 rounded-2xl border bg-primary-foreground p-4 shadow-xs [&>svg]:size-full"
                                role="img"
                                aria-label="QR code to claim an e-Invoice"
                                dangerouslySetInnerHTML={{
                                    __html: order.claimQrSvg!,
                                }}
                            />
                        </div>
                    </aside>
                )}
            </main>
        </CfdShell>
    );
}
