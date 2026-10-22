import { MoneyText } from '@/components/pms/money-text';

export function SummaryRow({
    label,
    amount,
    currency,
    negative = false,
}: {
    label: string;
    amount: number;
    currency: App.Data.Property.CurrencyData | null;
    negative?: boolean;
}) {
    return (
        <div className="flex justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <span>
                {negative && '−'}
                <MoneyText amount={amount.toFixed(2)} currency={currency} />
            </span>
        </div>
    );
}
