import { MoneyText } from '@/components/pms/money-text';
type PosCurrency = App.Data.Property.CurrencyData | null;

type Order = App.Data.Pos.PosOrderData;

/**
 * The 80 mm guest receipt or pre-bill slip. Prints alone through the scoped `.pos-print-area` print rule.
 */
export function PosReceipt({
    order,
    currency,
    prebill = false,
}: {
    order: Order;
    currency: PosCurrency;
    prebill?: boolean;
}) {
    const lines = order.lines.filter((line) => !line.isVoided);
    const refund = order.orderType === 'refund';
    const rounding = Number(order.roundingAdjustment);
    const netPayable = (Number(order.grandTotal) + rounding).toFixed(2);

    return (
        <div className="pos-print-area mx-auto flex w-full max-w-[80mm] flex-col gap-2 font-mono text-xs">
            <div className="text-center">
                <p className="text-sm font-semibold">{order.outletName}</p>
                <p>
                    {prebill
                        ? 'PRE-BILL (NOT A TAX INVOICE)'
                        : refund
                          ? 'REFUND'
                          : 'RECEIPT'}
                </p>
                {order.orderNumber && <p>{order.orderNumber}</p>}
                {order.tableCode && <p>Table {order.tableCode}</p>}
                <p>
                    {[order.businessDate, order.registerName, order.cashier]
                        .filter(Boolean)
                        .join(' · ')}
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
                                {line.variantName !== 'Regular'
                                    ? ` (${line.variantName})`
                                    : ''}
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
                        {Number(line.discountAmount) > 0 && (
                            <div className="flex justify-between gap-2 pl-3">
                                <span>Item discount</span>
                                <MoneyText
                                    amount={`-${line.discountAmount}`}
                                    currency={currency}
                                    plain
                                />
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
                <div className="flex justify-between text-sm font-semibold">
                    <dt>{rounding !== 0 ? 'Bill total' : 'Total'}</dt>
                    <dd>
                        <MoneyText
                            amount={order.grandTotal}
                            currency={currency}
                        />
                    </dd>
                </div>
                {rounding !== 0 && (
                    <>
                        <Row
                            label="Cash rounding"
                            amount={order.roundingAdjustment}
                            currency={currency}
                        />
                        <div className="flex justify-between text-sm font-semibold">
                            <dt>Net payable</dt>
                            <dd>
                                <MoneyText
                                    amount={netPayable}
                                    currency={currency}
                                />
                            </dd>
                        </div>
                    </>
                )}
            </dl>
            {!prebill && (
                <>
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
                                    {payment.change &&
                                        Number(payment.change) > 0 && (
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
                                dangerouslySetInnerHTML={{
                                    __html: order.claimQrSvg,
                                }}
                            />
                            <p className="break-all">{order.claimUrl}</p>
                        </div>
                    )}
                    <p className="pt-2 text-center">Thank you</p>
                </>
            )}
            {prebill && (
                <p className="pt-2 text-center">
                    Please check your bill. Payment due on settlement.
                </p>
            )}
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
