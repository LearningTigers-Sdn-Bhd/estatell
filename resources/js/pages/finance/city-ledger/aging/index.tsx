import { Head, Link, router } from '@inertiajs/react';
import { Download, Printer, Scale } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { Button, buttonVariants } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { aging } from '@/routes/finance/city-ledger';
import { csv, pdf } from '@/routes/finance/city-ledger/aging';
import { show } from '@/routes/finance/city-ledger/companies';

type Row = App.Data.CityLedger.AgingRowData;

const columns: { key: keyof Row; label: string }[] = [
    { key: 'current', label: 'Current' },
    { key: 'days1to30', label: '1-30' },
    { key: 'days31to60', label: '31-60' },
    { key: 'days61to90', label: '61-90' },
    { key: 'over90', label: 'Over 90' },
    { key: 'credit', label: 'Credit' },
    { key: 'total', label: 'Total' },
];

export default function AgingIndex({
    report,
    today,
    currency,
}: {
    report: App.Data.CityLedger.AgingReportData;
    today: string;
    currency: App.Enums.Currency | null;
}) {
    const [asAt, setAsAt] = useState(report.asAt);
    const query = { as_at: report.asAt };

    return (
        <>
            <Head title="Aging" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Aging"
                    description="What companies owe, by days past due. Unallocated payments show as credit."
                    actions={
                        <>
                            <a
                                href={csv.url(undefined, { query })}
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                <Download data-icon="inline-start" />
                                Download CSV
                            </a>
                            <a
                                href={pdf.url(undefined, { query })}
                                target="_blank"
                                rel="noreferrer"
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                <Printer data-icon="inline-start" />
                                Download PDF
                            </a>
                        </>
                    }
                />

                <div className="flex flex-wrap items-end gap-3">
                    <Field className="w-44">
                        <FieldLabel htmlFor="aging-as_at">As at</FieldLabel>
                        <Input
                            id="aging-as_at"
                            type="date"
                            max={today}
                            value={asAt}
                            onChange={(event) => setAsAt(event.target.value)}
                        />
                    </Field>
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.get(
                                aging.url(undefined, {
                                    query: { as_at: asAt },
                                }),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Show
                    </Button>
                </div>

                {report.rows.length === 0 ? (
                    <EmptyState
                        icon={Scale}
                        title="Nothing is owed"
                        description="No company has an open invoice or credit on this date."
                    />
                ) : (
                    <div className="overflow-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Company</TableHead>
                                    {columns.map((column) => (
                                        <TableHead
                                            key={column.key}
                                            className="text-right"
                                        >
                                            {column.label}
                                        </TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {report.rows.map((row) => (
                                    <TableRow key={row.companyId}>
                                        <TableCell>
                                            <Link
                                                href={show.url({
                                                    company: row.companyId ?? 0,
                                                })}
                                                className="font-medium hover:underline"
                                            >
                                                {row.companyName}
                                            </Link>
                                        </TableCell>
                                        {columns.map((column) => (
                                            <TableCell
                                                key={column.key}
                                                className="text-right"
                                            >
                                                <MoneyText
                                                    amount={String(
                                                        row[column.key],
                                                    )}
                                                    currency={currency}
                                                    plain
                                                />
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell>Total</TableCell>
                                    {columns.map((column) => (
                                        <TableCell
                                            key={column.key}
                                            className="text-right"
                                        >
                                            <MoneyText
                                                amount={String(
                                                    report.totals[column.key],
                                                )}
                                                currency={currency}
                                                plain
                                            />
                                        </TableCell>
                                    ))}
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                )}
            </div>
        </>
    );
}

AgingIndex.layout = {
    breadcrumbs: [{ title: 'Aging', href: aging() }],
};
