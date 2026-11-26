import { Minus, Plus, Receipt, X } from 'lucide-react';
import { MoneyText } from '@/components/pms/money-text';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { lineStatusLabel } from './pos-types';
import type { PosCurrency } from './pos-types';

type Order = App.Data.Pos.PosOrderData;
type Line = App.Data.Pos.PosOrderLineData;

function formatQuantityPrefix(line: Line): string {
    if (line.isWeighed && line.weightUnit !== null) {
        const divisor = Number(line.weightUnitDivisor ?? '1');
        const totalWeight = Number(line.quantity) * (divisor > 0 ? divisor : 1);
        const formatted = Number.isInteger(totalWeight)
            ? String(totalWeight)
            : totalWeight.toFixed(2).replace(/\.?0+$/, '');

        return `${formatted} ${line.weightUnit}`;
    }

    const qty = Number(line.quantity);

    return `${Number.isInteger(qty) ? qty : line.quantity}×`;
}

/**
 * The active check ticket: ruled receipt rows where draft lines stay editable and sent lines lock.
 * Amounts are server-calculated and displayed as received.
 */
export function PosOrderTicket({
    order,
    currency,
    onQuantity,
    onRemove,
}: {
    order: Order;
    currency: PosCurrency;
    onQuantity: (line: Line, quantity: number) => void;
    onRemove: (line: Line) => void;
}) {
    const lines = order.lines.filter(
        (line) => !line.isPackagingFee || !line.isVoided,
    );

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            {lines.length === 0 ? (
                <div className="flex flex-1 flex-col items-center justify-center gap-2.5 px-6 py-10 text-center">
                    <span
                        aria-hidden="true"
                        className="flex size-11 items-center justify-center rounded-xl border bg-muted/40 text-muted-foreground"
                    >
                        <Receipt className="size-5" />
                    </span>
                    <p className="font-heading text-sm font-semibold text-foreground">
                        No items on this check
                    </p>
                    <p className="max-w-xs text-xs text-muted-foreground">
                        Tap a menu item or scan a barcode to add it here.
                    </p>
                </div>
            ) : (
                <ul
                    role="list"
                    className="flex min-h-0 flex-1 flex-col divide-y overflow-y-auto px-4"
                >
                    {lines.map((line) => (
                        <li
                            key={line.id}
                            className={cn(
                                'flex flex-col gap-2 py-3',
                                line.isComboPart && 'pl-5',
                                line.isVoided && 'opacity-55',
                            )}
                        >
                            <div className="flex items-start justify-between gap-2.5">
                                <div className="flex min-w-0 items-start gap-2.5">
                                    <span
                                        className={cn(
                                            'w-8 shrink-0 font-heading text-sm leading-snug font-bold text-foreground tabular-nums',
                                            line.isVoided && 'line-through',
                                        )}
                                    >
                                        {formatQuantityPrefix(line)}
                                    </span>
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <span
                                            className={cn(
                                                'text-sm leading-snug font-medium text-foreground',
                                                line.isVoided && 'line-through',
                                            )}
                                        >
                                            {line.itemName}
                                            {line.variantName !== 'Regular' && (
                                                <span className="font-normal text-muted-foreground">
                                                    {' '}
                                                    · {line.variantName}
                                                </span>
                                            )}
                                        </span>
                                        {line.modifiers.length > 0 && (
                                            <span className="text-xs text-muted-foreground">
                                                {line.modifiers.join(', ')}
                                            </span>
                                        )}
                                        {line.note && (
                                            <span className="text-xs text-warning-text">
                                                Note: {line.note}
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <span className="shrink-0 font-heading text-sm font-semibold text-foreground tabular-nums">
                                    <MoneyText
                                        amount={line.lineGross}
                                        currency={currency}
                                        plain
                                    />
                                </span>
                            </div>

                            <div className="flex flex-wrap items-center justify-between gap-2 pl-10">
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <Badge
                                        variant={
                                            line.isDraft
                                                ? 'outline'
                                                : 'secondary'
                                        }
                                        className={
                                            line.isDraft
                                                ? 'border-warning/60 bg-warning/10 text-warning-text'
                                                : undefined
                                        }
                                    >
                                        {lineStatusLabel(line)}
                                    </Badge>
                                    {line.courseNumber > 1 && (
                                        <Badge variant="outline">
                                            C{line.courseNumber}
                                        </Badge>
                                    )}
                                    {line.seatNumber !== null && (
                                        <Badge variant="outline">
                                            Seat {line.seatNumber}
                                        </Badge>
                                    )}
                                    {line.isEntitlementCovered && (
                                        <Badge variant="secondary">
                                            Included meal
                                        </Badge>
                                    )}
                                    {Number(line.discountAmount) > 0 && (
                                        <Badge variant="secondary">
                                            Discount
                                        </Badge>
                                    )}
                                </div>

                                {!line.isVoided && !line.isPackagingFee && (
                                    <div className="flex items-center gap-1">
                                        {line.isDraft &&
                                            !line.isWeighed &&
                                            !line.isComboPart && (
                                                <>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="icon"
                                                        className="size-11"
                                                        aria-label={`One less ${line.itemName}`}
                                                        disabled={
                                                            Number(
                                                                line.quantity,
                                                            ) <= 1
                                                        }
                                                        onClick={() =>
                                                            onQuantity(
                                                                line,
                                                                Number(
                                                                    line.quantity,
                                                                ) - 1,
                                                            )
                                                        }
                                                    >
                                                        <Minus aria-hidden="true" />
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="icon"
                                                        className="size-11"
                                                        aria-label={`One more ${line.itemName}`}
                                                        onClick={() =>
                                                            onQuantity(
                                                                line,
                                                                Number(
                                                                    line.quantity,
                                                                ) + 1,
                                                            )
                                                        }
                                                    >
                                                        <Plus aria-hidden="true" />
                                                    </Button>
                                                </>
                                            )}
                                        {!line.isComboPart && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-11 text-muted-foreground hover:text-destructive"
                                                aria-label={`Remove ${line.itemName}`}
                                                onClick={() => onRemove(line)}
                                            >
                                                <X aria-hidden="true" />
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <dl className="flex flex-col gap-1.5 border-t bg-muted/25 px-4 py-3 text-sm">
                <Row
                    label="Subtotal"
                    amount={order.subtotal}
                    currency={currency}
                />
                {Number(order.discountTotal) > 0 && (
                    <Row
                        label="Discount"
                        amount={`-${order.discountTotal}`}
                        currency={currency}
                        tone="discount"
                    />
                )}
                {Number(order.serviceChargeTotal) > 0 && (
                    <Row
                        label="Service charge"
                        amount={order.serviceChargeTotal}
                        currency={currency}
                    />
                )}
                {Number(order.taxTotal) > 0 && (
                    <Row
                        label="Tax"
                        amount={order.taxTotal}
                        currency={currency}
                    />
                )}
                <div className="mt-1 flex items-baseline justify-between border-t pt-2.5">
                    <dt className="font-heading text-base font-semibold text-foreground">
                        Total
                    </dt>
                    <dd className="font-heading text-2xl font-bold text-foreground tabular-nums">
                        <MoneyText
                            amount={order.grandTotal}
                            currency={currency}
                        />
                    </dd>
                </div>
            </dl>
        </div>
    );
}

function Row({
    label,
    amount,
    currency,
    tone = 'default',
}: {
    label: string;
    amount: string;
    currency: PosCurrency;
    tone?: 'default' | 'discount';
}) {
    return (
        <div className="flex items-center justify-between text-muted-foreground">
            <dt>{label}</dt>
            <dd
                className={cn(
                    'tabular-nums',
                    tone === 'discount' && 'font-medium text-foreground',
                )}
            >
                <MoneyText amount={amount} currency={currency} />
            </dd>
        </div>
    );
}
