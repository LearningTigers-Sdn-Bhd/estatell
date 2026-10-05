import { router } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useState } from 'react';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';
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
import { statement } from '@/routes/finance/city-ledger/companies';
import { pdf as statementPdf } from '@/routes/finance/city-ledger/companies/statement';

type Statement = App.Data.CityLedger.StatementData;

const quickRanges = [
    { value: 'this_month', label: 'This month' },
    { value: 'last_month', label: 'Last month' },
    { value: 'last_3_months', label: 'Last 3 months' },
    { value: 'this_year', label: 'This year' },
    { value: 'last_year', label: 'Last year' },
];

function ymd(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** The first and last date of a quick range, counted from today. */
function quickRange(value: string): { from: string; to: string } {
    const today = new Date();
    const year = today.getFullYear();
    const month = today.getMonth();

    switch (value) {
        case 'last_month':
            return {
                from: ymd(new Date(year, month - 1, 1)),
                to: ymd(new Date(year, month, 0)),
            };
        case 'last_3_months':
            return { from: ymd(new Date(year, month - 2, 1)), to: ymd(today) };
        case 'this_year':
            return { from: ymd(new Date(year, 0, 1)), to: ymd(today) };
        case 'last_year':
            return {
                from: ymd(new Date(year - 1, 0, 1)),
                to: ymd(new Date(year - 1, 11, 31)),
            };
        default:
            return { from: ymd(new Date(year, month, 1)), to: ymd(today) };
    }
}

/**
 * The statement of account of one company between two dates: the opening balance, each invoice, credit note and
 * payment with a running balance, and the closing balance. The dates are in the URL, so a refresh keeps them.
 */
export function CompanyStatement({
    companyId,
    statement: data,
    currency,
}: {
    companyId: number;
    statement: Statement;
    currency: App.Enums.Currency | null;
}) {
    const [from, setFrom] = useState(data.from);
    const [to, setTo] = useState(data.to);
    const load = (range: { from: string; to: string }) =>
        router.get(
            statement.url({ company: companyId }, { query: range }),
            {},
            { preserveScroll: true },
        );
    const money = (amount: string) => (
        <MoneyText amount={amount} currency={currency} />
    );

    return (
        <section className="flex flex-col gap-4">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div className="flex flex-wrap items-end gap-3">
                    <Field className="w-auto">
                        <FieldLabel htmlFor="statement-period">
                            Period
                        </FieldLabel>
                        <DateRangePicker
                            id="statement-period"
                            from={from}
                            to={to}
                            months={2}
                            className="w-80"
                            onChange={(first, last) => {
                                setFrom(first);
                                setTo(last);

                                if (first !== last) {
                                    load({ from: first, to: last });
                                }
                            }}
                        />
                    </Field>
                    <Field className="w-40">
                        <FieldLabel htmlFor="statement-quick-range">
                            Quick range
                        </FieldLabel>
                        <EnumSelect
                            id="statement-quick-range"
                            options={quickRanges}
                            value=""
                            placeholder="Pick a range"
                            onValueChange={(value) => {
                                if (!value) {
                                    return;
                                }

                                const range = quickRange(value);

                                setFrom(range.from);
                                setTo(range.to);
                                load(range);
                            }}
                        />
                    </Field>
                </div>
                <a
                    href={statementPdf.url(
                        { company: companyId },
                        { query: { from: data.from, to: data.to } },
                    )}
                    target="_blank"
                    rel="noreferrer"
                    className={buttonVariants({ variant: 'outline' })}
                >
                    <Printer data-icon="inline-start" />
                    Download PDF
                </a>
            </header>

            <div className="overflow-hidden rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead>Document</TableHead>
                            <TableHead>Details</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                            <TableHead className="text-right">
                                Balance
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow>
                            <TableCell
                                colSpan={4}
                                className="text-muted-foreground"
                            >
                                Opening balance
                            </TableCell>
                            <TableCell className="text-right">
                                {money(data.openingBalance)}
                            </TableCell>
                        </TableRow>
                        {data.lines.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={5}>
                                    <EmptyState title="Nothing in this period" />
                                </TableCell>
                            </TableRow>
                        ) : (
                            data.lines.map((line, position) => (
                                <TableRow key={position}>
                                    <TableCell>
                                        <DateText date={line.date} />
                                    </TableCell>
                                    <TableCell>
                                        {line.kindLabel} {line.number}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {line.description}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {money(line.amount)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {money(line.balance)}
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell colSpan={4}>Closing balance</TableCell>
                            <TableCell className="text-right">
                                {money(data.closingBalance)}
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </div>

            <div className="flex flex-col gap-2">
                <h3 className="text-sm font-medium">
                    Aging as at <DateText date={data.to} />
                </h3>
                <p className="text-xs text-muted-foreground">
                    By today's allocations, so for a past date it is an
                    estimate.
                </p>
                <dl className="grid gap-2 sm:grid-cols-5">
                    {[
                        ['Current', data.aging.current],
                        ['1-30', data.aging.days1to30],
                        ['31-60', data.aging.days31to60],
                        ['61-90', data.aging.days61to90],
                        ['Over 90', data.aging.over90],
                    ].map(([label, amount]) => (
                        <div
                            key={label}
                            className="flex flex-col gap-1 rounded-lg border p-3"
                        >
                            <dt className="text-xs text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="text-sm font-medium">
                                {money(amount)}
                            </dd>
                        </div>
                    ))}
                </dl>
            </div>
        </section>
    );
}
