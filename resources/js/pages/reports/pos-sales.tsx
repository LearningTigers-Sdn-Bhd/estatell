import { Head, router } from '@inertiajs/react';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { posSales } from '@/routes/reports';

type Props = {
    filters: { from: string; to: string };
    byOutlet: {
        name: string;
        total: string;
        orders: number;
        discounts: string;
    }[];
    byCategory: { name: string; net: string; quantity: string }[];
    audit: { voidedOrders: number; voidedLines: number; refunds: number };
    currency: App.Data.Property.CurrencyData | null;
};

export default function PosSales({
    filters,
    byOutlet,
    byCategory,
    audit,
    currency,
}: Props) {
    function visit(change: Partial<Props['filters']>) {
        router.get(
            posSales.url(undefined, { query: { ...filters, ...change } }),
            {},
            { preserveState: true, replace: true },
        );
    }

    return (
        <>
            <Head title="POS sales" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="POS sales"
                    description="Net of refunds, by outlet and by category, with the voids and discounts to audit."
                />
                <div className="flex gap-2">
                    <Input
                        type="date"
                        aria-label="From"
                        className="w-40"
                        value={filters.from}
                        onChange={(event) =>
                            visit({ from: event.target.value })
                        }
                    />
                    <Input
                        type="date"
                        aria-label="To"
                        className="w-40"
                        value={filters.to}
                        onChange={(event) => visit({ to: event.target.value })}
                    />
                </div>
                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>By outlet</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Outlet</TableHead>
                                        <TableHead className="text-right">
                                            Orders
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Discounts
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Total
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {byOutlet.map((row) => (
                                        <TableRow key={row.name}>
                                            <TableCell className="font-medium">
                                                {row.name}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.orders}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <MoneyText
                                                    amount={row.discounts}
                                                    currency={currency}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <MoneyText
                                                    amount={row.total}
                                                    currency={currency}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>By category</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Category</TableHead>
                                        <TableHead className="text-right">
                                            Quantity
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Net sales
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {byCategory.map((row) => (
                                        <TableRow key={row.name}>
                                            <TableCell className="font-medium">
                                                {row.name}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.quantity}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <MoneyText
                                                    amount={row.net}
                                                    currency={currency}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Audit</CardTitle>
                    </CardHeader>
                    <CardContent className="flex gap-8 text-sm">
                        <p>
                            Voided orders{' '}
                            <span className="font-medium tabular-nums">
                                {audit.voidedOrders}
                            </span>
                        </p>
                        <p>
                            Voided lines{' '}
                            <span className="font-medium tabular-nums">
                                {audit.voidedLines}
                            </span>
                        </p>
                        <p>
                            Refunds{' '}
                            <span className="font-medium tabular-nums">
                                {audit.refunds}
                            </span>
                        </p>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

PosSales.layout = {
    breadcrumbs: [{ title: 'POS sales', href: posSales() }],
};
