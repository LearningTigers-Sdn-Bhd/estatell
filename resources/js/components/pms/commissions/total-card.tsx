import { MoneyText } from '@/components/pms/money-text';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

/**
 * One figure card at the top of the admin Commissions and the property Payouts pages.
 */
export function TotalCard({
    title,
    amount,
    note,
    currency,
}: {
    title: string;
    amount: string;
    note: string;
    currency: App.Data.Property.CurrencyData;
}) {
    return (
        <Card className="gap-1">
            <CardHeader>
                <CardDescription>{title}</CardDescription>
                <CardTitle className="text-2xl tabular-nums">
                    <MoneyText amount={amount} currency={currency} />
                </CardTitle>
                <CardDescription>{note}</CardDescription>
            </CardHeader>
        </Card>
    );
}
