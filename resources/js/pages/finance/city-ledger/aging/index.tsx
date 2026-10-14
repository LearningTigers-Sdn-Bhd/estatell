import { Head, Link, router } from '@inertiajs/react';
import { Download, Printer, Scale } from 'lucide-react';
import { DatePicker } from '@/components/pms/date-picker';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { buttonVariants } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
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

const quickDates = [
    { value: 'today', label: 'Today' },
    { value: 'last_month_end', label: 'End of last month' },
    { value: 'last_quarter_end', label: 'End of last quarter' },
    { value: 'last_year_end', label: 'End of last year' },
];

function ymd(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** The date of a quick pick, counted from the property's today. */
function quickDate(value: string, today: string): string {
    const [year, month] = today.split('-').map(Number);

    switch (value) {
        case 'last_month_end':
            return ymd(new Date(year, month - 1, 0));
        case 'last_quarter_end':
            return ymd(new Date(year, Math.floor((month - 1) / 3) * 3, 0));
        case 'last_year_end':
            return ymd(new Date(year - 1, 11, 31));
        default:
            return today;
    }
}

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
    currency: App.Data.Property.CurrencyData | null;
}) {
    const query = { as_at: report.asAt };
    const load = (asAt: string) =>
        router.get(
            aging.url(undefined, { query: { as_at: asAt } }),
            {},
            { preserveScroll: true },
        );

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
                        <DatePicker
                            id="aging-as_at"
                            value={report.asAt}
                            max={today}
                            className="w-full"
                            onChange={(value) => {
                                if (value) {
                                    load(value);
                                }
                            }}
                        />
                    </Field>
                    <Field className="w-48">
                        <FieldLabel htmlFor="aging-quick">
                            Quick date
                        </FieldLabel>
                        <EnumSelect
                            id="aging-quick"
                            options={quickDates}
                            value=""
                            placeholder="Pick a date"
                            onValueChange={(value) => {
                                if (value) {
                                    load(quickDate(value, today));
                                }
                            }}
                        />
                    </Field>
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
