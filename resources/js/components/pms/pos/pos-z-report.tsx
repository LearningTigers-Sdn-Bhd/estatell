import { Fragment } from 'react';
import { MoneyText } from '@/components/pms/money-text';
import { cashMovementKindLabel, tenderTypeLabel } from './pos-types';
import type { PosCurrency } from './pos-types';

type Snapshot = {
    shift_number: string;
    outlet: string;
    register: string;
    opened_by: string;
    closed_by: string | null;
    opened_at: string;
    closed_at: string | null;
    cash: Record<string, string | number | null>;
    sales: Record<string, string | number>;
    refunds: Record<string, string | number>;
    voided_orders: number;
    tenders: {
        tender_type: string;
        direction: string;
        payments: number;
        amount: string;
        rounding_adjustment: string;
    }[];
    cash_movements: {
        kind: string;
        amount: string;
        reason: string;
        business_date: string;
    }[];
    note: string | null;
};

const CASH_LABELS: [string, string][] = [
    ['opening_cash', 'Opening cash'],
    ['cash_payments', 'Cash payments'],
    ['cash_refunds', 'Cash refunds'],
    ['paid_in', 'Paid in'],
    ['paid_out', 'Paid out'],
    ['expected_cash', 'Expected cash'],
    ['counted_cash', 'Counted cash'],
    ['cash_variance', 'Variance'],
];

/**
 * The frozen Z-report of a closed shift, printable on 80 mm. Amounts were fixed when the shift closed.
 */
export function PosZReport({
    report,
    currency,
}: {
    report: Record<string, unknown>;
    currency: PosCurrency;
}) {
    const z = report as unknown as Snapshot;

    return (
        <div className="pos-print-area mx-auto flex w-full max-w-[80mm] flex-col gap-2 font-mono text-xs">
            <div className="text-center">
                <p className="text-sm font-semibold">
                    Z-report {z.shift_number}
                </p>
                <p>
                    {z.outlet} · {z.register}
                </p>
                <p>
                    {z.opened_by} → {z.closed_by ?? 'open'}
                </p>
            </div>
            <hr />
            <Block title="Cash">
                {CASH_LABELS.map(([key, label]) => (
                    <Line
                        key={key}
                        label={label}
                        amount={
                            z.cash[key] === null || z.cash[key] === undefined
                                ? null
                                : String(z.cash[key])
                        }
                        currency={currency}
                    />
                ))}
            </Block>
            <Block title={`Sales (${z.sales.orders})`}>
                {/* The stored subtotal is already net of discounts, so the report adds them back to make the rows add up. */}
                <Line
                    label="Subtotal"
                    amount={(
                        Number(z.sales.subtotal) + Number(z.sales.discounts)
                    ).toFixed(2)}
                    currency={currency}
                />
                <Line
                    label="Discounts"
                    amount={`-${z.sales.discounts}`}
                    currency={currency}
                />
                <Line
                    label="Service charge"
                    amount={String(z.sales.service_charge)}
                    currency={currency}
                />
                <Line
                    label="Tax"
                    amount={String(z.sales.tax)}
                    currency={currency}
                />
                <Line
                    label="Total"
                    amount={String(z.sales.grand_total)}
                    currency={currency}
                />
            </Block>
            <Block
                title={`Refunds (${z.refunds.orders}) · voided orders ${z.voided_orders}`}
            >
                <Line
                    label="Refunded"
                    amount={String(z.refunds.grand_total)}
                    currency={currency}
                />
            </Block>
            <Block title="Tenders">
                {z.tenders.map((tender) => (
                    <Fragment key={`${tender.tender_type}-${tender.direction}`}>
                        <Line
                            label={`${tenderTypeLabel(tender.tender_type)}${tender.direction === 'out' ? ' (refund)' : ''} ×${tender.payments}`}
                            amount={tender.amount}
                            currency={currency}
                        />
                        {Number(tender.rounding_adjustment) !== 0 && (
                            <Line
                                label="Cash rounding"
                                amount={tender.rounding_adjustment}
                                currency={currency}
                            />
                        )}
                    </Fragment>
                ))}
            </Block>
            {z.cash_movements.length > 0 && (
                <Block title="Cash movements">
                    {z.cash_movements.map((movement, index) => (
                        <Line
                            key={index}
                            label={`${cashMovementKindLabel(movement.kind)}: ${movement.reason}`}
                            amount={movement.amount}
                            currency={currency}
                        />
                    ))}
                </Block>
            )}
            {z.note && <p>Note: {z.note}</p>}
        </div>
    );
}

function Block({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-0.5">
            <h3 className="font-semibold">{title}</h3>
            {children}
        </section>
    );
}

function Line({
    label,
    amount,
    currency,
}: {
    label: string;
    amount: string | null;
    currency: PosCurrency;
}) {
    if (amount === null) {
        return null;
    }

    return (
        <div className="flex justify-between gap-2">
            <span>{label}</span>
            <MoneyText amount={amount} currency={currency} plain />
        </div>
    );
}
