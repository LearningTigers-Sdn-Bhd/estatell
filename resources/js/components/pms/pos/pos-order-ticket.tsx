import { Minus, Plus, X } from 'lucide-react';
import { MoneyText } from '@/components/pms/money-text';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { lineStatusLabel } from './pos-types';
import type { PosCurrency } from './pos-types';

type Order = App.Data.Pos.PosOrderData;
type Line = App.Data.Pos.PosOrderLineData;

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
            <ul className="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto">
                {lines.length === 0 && (
                    <li className="text-sm text-muted-foreground">
                        Tap an item to start.
                    </li>
                )}
                {lines.map((line) => (
                    <li
                        key={line.id}
                        className={cn(
                            'flex flex-col gap-1 rounded-lg border p-3',
                            line.isComboPart && 'ml-4 border-dashed',
                            line.isVoided && 'opacity-50',
                        )}
                    >
                        <div className="flex items-start justify-between gap-2">
                            <div className="flex flex-col">
                                <span
                                    className={cn(
                                        'font-medium',
                                        line.isVoided && 'line-through',
                                    )}
                                >
                                    <span className="tabular-nums">
                                        {line.quantity}
                                    </span>{' '}
                                    × {line.itemName}
                                    {line.variantName !== 'Regular' &&
                                        ` (${line.variantName})`}
                                </span>
                                {line.modifiers.length > 0 && (
                                    <span className="text-xs text-muted-foreground">
                                        {line.modifiers.join(', ')}
                                    </span>
                                )}
                                {line.note && (
                                    <span className="text-xs text-muted-foreground">
                                        {line.note}
                                    </span>
                                )}
                            </div>
                            <span className="font-medium">
                                <MoneyText
                                    amount={line.lineGross}
                                    currency={currency}
                                    plain
                                />
                            </span>
                        </div>
                        <div className="flex items-center justify-between gap-2">
                            <div className="flex items-center gap-2">
                                <Badge
                                    variant={
                                        line.isDraft ? 'outline' : 'secondary'
                                    }
                                    className={
                                        line.isDraft
                                            ? 'text-warning'
                                            : undefined
                                    }
                                >
                                    {lineStatusLabel(line)}
                                </Badge>
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
                                <div className="flex items-center gap-1">
                                    {line.isDraft &&
                                        !line.isWeighed &&
                                        !line.isComboPart && (
                                            <>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="icon-sm"
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
                                                    <Minus />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="icon-sm"
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
                                                    <Plus />
                                                </Button>
                                            </>
                                        )}
                                    {!line.isComboPart && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`Remove ${line.itemName}`}
                                            onClick={() => onRemove(line)}
                                        >
                                            <X />
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
            <dl className="flex flex-col gap-1 border-t pt-3 text-sm">
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
                <div className="flex items-center justify-between pt-1 text-lg font-semibold">
                    <dt>Total</dt>
                    <dd>
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
}: {
    label: string;
    amount: string;
    currency: PosCurrency;
}) {
    return (
        <div className="flex items-center justify-between text-muted-foreground">
            <dt>{label}</dt>
            <dd>
                <MoneyText amount={amount} currency={currency} />
            </dd>
        </div>
    );
}
