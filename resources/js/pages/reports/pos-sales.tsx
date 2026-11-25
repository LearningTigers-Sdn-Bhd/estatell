import { Head, router } from '@inertiajs/react';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldLabel } from '@/components/ui/field';
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
    redeemedMeals: {
        outletName: string;
        mealPeriodName: string;
        portions: string;
        grossValue: string;
    }[];
    timeClocks: {
        id: number;
        staffName: string;
        outletName: string;
        clockedInAt: string;
        clockedOutAt: string | null;
        minutes: number | null;
    }[];
    currency: App.Data.Property.CurrencyData | null;
};

function formatDuration(minutes: number | null): string {
    if (minutes === null) {
        return 'On shift';
    }

    const hours = Math.floor(minutes / 60);
    const rem = minutes % 60;

    return hours > 0 ? `${hours}h ${rem}m` : `${rem}m`;
}

export default function PosSales({
    filters,
    byOutlet,
    byCategory,
    audit,
    redeemedMeals,
    timeClocks,
    currency,
}: Props) {
    const currencyCode = currency?.code ?? 'MYR';

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
                    description="Net of refunds, by outlet and by category, with voids, discounts, redeemed meals, and staff time clock."
                />
                <div className="flex flex-wrap items-end gap-3">
                    <Field className="w-44">
                        <FieldLabel htmlFor="pos-sales-from">From</FieldLabel>
                        <Input
                            id="pos-sales-from"
                            type="date"
                            value={filters.from}
                            onChange={(event) =>
                                visit({ from: event.target.value })
                            }
                        />
                    </Field>
                    <Field className="w-44">
                        <FieldLabel htmlFor="pos-sales-to">To</FieldLabel>
                        <Input
                            id="pos-sales-to"
                            type="date"
                            value={filters.to}
                            onChange={(event) =>
                                visit({ to: event.target.value })
                            }
                        />
                    </Field>
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
                                            Discounts ({currencyCode})
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Total ({currencyCode})
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
                                                    plain
                                                />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <MoneyText
                                                    amount={row.total}
                                                    currency={currency}
                                                    plain
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {byOutlet.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={4}
                                                className="text-muted-foreground"
                                            >
                                                No settled orders in this range.
                                            </TableCell>
                                        </TableRow>
                                    )}
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
                                            Net sales ({currencyCode})
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
                                                    plain
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {byCategory.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={3}
                                                className="text-muted-foreground"
                                            >
                                                No category sales in this range.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Audit</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-8 text-sm">
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

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Included meals redeemed</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Outlet</TableHead>
                                        <TableHead>Meal period</TableHead>
                                        <TableHead className="text-right">
                                            Portions
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Menu value ({currencyCode})
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {redeemedMeals.map((row) => (
                                        <TableRow
                                            key={`${row.outletName}-${row.mealPeriodName}`}
                                        >
                                            <TableCell className="font-medium">
                                                {row.outletName}
                                            </TableCell>
                                            <TableCell>
                                                {row.mealPeriodName}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.portions}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <MoneyText
                                                    amount={row.grossValue}
                                                    currency={currency}
                                                    plain
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {redeemedMeals.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={4}
                                                className="text-muted-foreground"
                                            >
                                                No included meals redeemed in
                                                this range.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Staff time clock</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Staff</TableHead>
                                        <TableHead>Outlet</TableHead>
                                        <TableHead>Clock in</TableHead>
                                        <TableHead className="text-right">
                                            Duration
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {timeClocks.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell className="font-medium">
                                                {row.staffName}
                                            </TableCell>
                                            <TableCell>
                                                {row.outletName}
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {new Date(
                                                    row.clockedInAt,
                                                ).toLocaleString()}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.minutes === null ? (
                                                    <Badge variant="secondary">
                                                        On shift
                                                    </Badge>
                                                ) : (
                                                    formatDuration(row.minutes)
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {timeClocks.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={4}
                                                className="text-muted-foreground"
                                            >
                                                No clock entries in this range.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

PosSales.layout = {
    breadcrumbs: [{ title: 'POS sales', href: posSales() }],
};
