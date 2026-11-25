import { MoneyText } from '@/components/pms/money-text';
type PosCurrency = App.Data.Property.CurrencyData | null;

type Order = App.Data.Pos.PosOrderData;

/**
 * The 80 mm guest receipt. It shows on screen and prints alone through the `.pos-print-area` print rule. A refund shows
 * its amounts as stored (positive) under a "Refund" title.
 */
export function PosReceipt({
    order,
    currency,
}: {
    order: Order;
    currency: PosCurrency;
}) {
    const lines = order.lines.filter((line) => !line.isVoided);
    const refund = order.orderType === 'refund';

    return (
        <div className="pos-print-area mx-auto flex w-full max-w-[80mm] flex-col gap-2 font-mono text-xs">
            <div className="text-center">
                <p className="text-sm font-semibold">{order.outletName}</p>
                <p>{refund ? 'REFUND' : 'RECEIPT'}</p>
                <p>{order.orderNumber}</p>
                <p>
                    {order.businessDate} · {order.registerName} ·{' '}
                    {order.cashier}
                </p>
                {order.refundForOrderNumber && (
                    <p>For {order.refundForOrderNumber}</p>
                )}
                {order.roomLabel && <p>Room {order.roomLabel}</p>}
            </div>
            <hr />
            <ul className="flex flex-col gap-1">
                {lines.map((line) => (
                    <li key={line.id}>
                        <div className="flex justify-between gap-2">
                            <span>
                                {line.quantity} × {line.itemName}
                            </span>
                            <MoneyText
                                amount={line.lineGross}
                                currency={currency}
                                plain
                            />
                        </div>
                        {line.modifiers.length > 0 && (
                            <div className="pl-3">
                                {line.modifiers.join(', ')}
                            </div>
                        )}
                        {line.isEntitlementCovered && (
                            <div className="pl-3">Included meal</div>
                        )}
                    </li>
                ))}
            </ul>
            <hr />
            <dl className="flex flex-col gap-0.5">
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
                {Number(order.roundingAdjustment) !== 0 && (
                    <Row
                        label="Rounding"
                        amount={order.roundingAdjustment}
                        currency={currency}
                    />
                )}
                <div className="flex justify-between text-sm font-semibold">
                    <dt>Total</dt>
                    <dd>
                        <MoneyText
                            amount={order.grandTotal}
                            currency={currency}
                        />
                    </dd>
                </div>
            </dl>
            <hr />
            <ul className="flex flex-col gap-0.5">
                {order.payments
                    .filter((payment) => payment.status === 'captured')
                    .map((payment) => (
                        <li key={payment.id}>
                            <div className="flex justify-between gap-2">
                                <span>
                                    {payment.direction === 'out'
                                        ? 'Refunded '
                                        : ''}
                                    {payment.tender}
                                </span>
                                <MoneyText
                                    amount={payment.amount}
                                    currency={currency}
                                    plain
                                />
                            </div>
                            {payment.change && Number(payment.change) > 0 && (
                                <div className="flex justify-between gap-2">
                                    <span>Change</span>
                                    <MoneyText
                                        amount={payment.change}
                                        currency={currency}
                                        plain
                                    />
                                </div>
                            )}
                        </li>
                    ))}
            </ul>
            {order.claimQrSvg !== null && order.claimUrl !== null && (
                <div className="flex flex-col items-center gap-1 pt-2 text-center">
                    <p>Need an e-invoice? Scan within the month.</p>
                    <div
                        className="size-32 shrink-0 [&>svg]:size-full"
                        role="img"
                        aria-label="QR code to claim an e-invoice"
                        dangerouslySetInnerHTML={{ __html: order.claimQrSvg }}
                    />
                    <p className="break-all">{order.claimUrl}</p>
                </div>
            )}
            <p className="pt-2 text-center">Thank you</p>
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
        <div className="flex justify-between">
            <dt>{label}</dt>
            <dd>
                <MoneyText amount={amount} currency={currency} plain />
            </dd>
        </div>
    );
}
