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
 * The ticket: draft lines are still editable, sent lines are locked. Amounts are the server's, shown as it calculated them.
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
        <div className="flex min-h-0 flex-1 flex-col gap-3">
            <ul
                role="list"
                className="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto pr-0.5"
            >
                {lines.length === 0 && (
                    <li className="flex flex-1 flex-col items-center justify-center gap-2 rounded-lg border border-dashed bg-muted/20 p-6 text-center">
                        <Receipt
                            className="size-7 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="text-sm font-medium text-foreground">
                            No items on this check
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Tap any menu item or scan a barcode to add it here.
                        </p>
                    </li>
                )}
                {lines.map((line) => (
                    <li
                        key={line.id}
                        className={cn(
                            'flex flex-col gap-2 rounded-lg border bg-background p-3',
                            line.isComboPart &&
                                'ms-4 border-dashed bg-muted/20',
                            line.isVoided && 'opacity-55',
                        )}
                    >
                        <div className="flex items-start justify-between gap-2">
                            <div className="flex min-w-0 items-start gap-2.5">
                                <span
                                    className={cn(
                                        'inline-flex min-w-9 shrink-0 items-center justify-center rounded-md bg-muted px-2 py-0.5 text-xs font-semibold text-foreground tabular-nums',
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
                                        <span className="text-xs text-muted-foreground italic">
                                            Note: {line.note}
                                        </span>
                                    )}
                                </div>
                            </div>
                            <span className="shrink-0 text-sm font-semibold text-foreground tabular-nums">
                                <MoneyText
                                    amount={line.lineGross}
                                    currency={currency}
                                    plain
                                />
                            </span>
                        </div>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex flex-wrap items-center gap-1.5">
                                <Badge
                                    variant={
                                        line.isDraft ? 'outline' : 'secondary'
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
                                        Course {line.courseNumber}
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
                                    <Badge variant="secondary">Discount</Badge>
                                )}
                            </div>
                            {!line.isVoided && !line.isPackagingFee && (
                                <div className="flex items-center gap-1.5">
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
                                                        Number(line.quantity) <=
                                                        1
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
            <dl className="flex flex-col gap-1.5 border-t pt-3 text-sm">
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
                    <dd className="font-heading text-2xl font-semibold text-foreground tabular-nums">
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
                    tone === 'discount' && 'font-medium text-success',
                )}
            >
                <MoneyText amount={amount} currency={currency} />
            </dd>
        </div>
    );
}
