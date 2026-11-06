import { MoneyText } from '@/components/pms/money-text';

type Line = App.Data.Reservations.AgentBillData['agentLines'][number];

/**
 * What the booking costs and who pays each part: the agent's lines, which the deposit and balance are worked out from,
 * and what the guest pays at the resort. The reservation page and the portal booking share it.
 */
export function AgentBillBreakdown({
    bill,
    agentLabel,
    currency,
}: {
    bill: App.Data.Reservations.AgentBillData;
    agentLabel: string;
    currency: App.Data.Property.CurrencyData | null;
}) {
    return (
        <div className="flex flex-col gap-3 text-sm">
            {bill.nationalityMissing && (
                <p className="rounded-md bg-muted px-3 py-2">
                    A guest has no nationality yet, so tourism tax is not
                    included. Foreign guests pay it.
                </p>
            )}
            <BillPart
                title={agentLabel}
                lines={bill.agentLines}
                total={bill.agentTotal}
                currency={currency}
            />
            {bill.guestLines.length > 0 && (
                <BillPart
                    title="Guest pays at the resort"
                    lines={bill.guestLines}
                    total={bill.guestTotal}
                    currency={currency}
                />
            )}
        </div>
    );
}

function BillPart({
    title,
    lines,
    total,
    currency,
}: {
    title: string;
    lines: Line[];
    total: string;
    currency: App.Data.Property.CurrencyData | null;
}) {
    return (
        <div className="flex flex-col gap-1">
            <h3 className="font-medium">{title}</h3>
            <ul className="flex flex-col gap-0.5">
                {lines.map((line) => (
                    <li
                        key={line.label}
                        className="flex items-baseline justify-between gap-3"
                    >
                        <span className="text-muted-foreground">
                            {line.label}
                            {line.quantity > 1 && ` × ${line.quantity}`}
                        </span>
                        <MoneyText amount={line.amount} currency={currency} />
                    </li>
                ))}
            </ul>
            <div className="flex items-baseline justify-between gap-3 border-t pt-1 font-medium">
                <span>Total</span>
                <MoneyText amount={total} currency={currency} />
            </div>
        </div>
    );
}
