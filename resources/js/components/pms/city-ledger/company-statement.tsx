import { router } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
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
import { statement } from '@/routes/finance/city-ledger/companies';
import { pdf as statementPdf } from '@/routes/finance/city-ledger/companies/statement';

type Statement = App.Data.CityLedger.StatementData;

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
    const query = { from, to };
    const money = (amount: string) => (
        <MoneyText amount={amount} currency={currency} />
    );

    return (
        <section className="flex flex-col gap-4">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div className="flex flex-wrap items-end gap-3">
                    <Field className="w-40">
                        <FieldLabel htmlFor="statement-from">From</FieldLabel>
                        <Input
                            id="statement-from"
                            type="date"
                            value={from}
                            onChange={(event) => setFrom(event.target.value)}
                        />
                    </Field>
                    <Field className="w-40">
                        <FieldLabel htmlFor="statement-to">To</FieldLabel>
                        <Input
                            id="statement-to"
                            type="date"
                            value={to}
                            onChange={(event) => setTo(event.target.value)}
                        />
                    </Field>
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.get(
                                statement.url(
                                    { company: companyId },
                                    { query },
                                ),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Show
                    </Button>
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
