import { router } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useState } from 'react';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
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
import { statements as statementsPage } from '@/routes/portal';
import { pdf as statementPdf } from '@/routes/portal/statements';

type Statement = App.Data.CityLedger.StatementData;

/**
 * The company's statement of account at the property: the period, the opening balance, each invoice, credit note and
 * payment with a running balance, the closing balance and the aging. The dates are in the URL, so a refresh keeps them.
 * The server decides every figure; the page only shows them.
 */
export function PortalStatementSection({
    propertyCode,
    statement,
    currency,
}: {
    propertyCode: string;
    statement: Statement;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [from, setFrom] = useState(statement.from);
    const [to, setTo] = useState(statement.to);
    const money = (amount: string) => (
        <MoneyText amount={amount} currency={currency} />
    );

    return (
        <section className="flex flex-col gap-4">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <Field className="w-auto">
                    <FieldLabel htmlFor="statement-period">Period</FieldLabel>
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
                                router.get(
                                    statementsPage.url(
                                        { property: propertyCode },
                                        {
                                            query: { from: first, to: last },
                                        },
                                    ),
                                    {},
                                    {
                                        preserveScroll: true,
                                        preserveState: true,
                                    },
                                );
                            }
                        }}
                    />
                </Field>
                <a
                    href={statementPdf.url(
                        { property: propertyCode },
                        { query: { from: statement.from, to: statement.to } },
                    )}
                    target="_blank"
                    rel="noreferrer"
                    className={buttonVariants({ variant: 'outline' })}
                >
                    <Printer data-icon="inline-start" />
                    Statement PDF
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
                                {money(statement.openingBalance)}
                            </TableCell>
                        </TableRow>
                        {statement.lines.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={5}>
                                    <EmptyState title="Nothing in this period" />
                                </TableCell>
                            </TableRow>
                        ) : (
                            statement.lines.map((line, position) => (
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
                                {money(statement.closingBalance)}
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </div>

            <div className="flex flex-col gap-2">
                <h3 className="text-sm font-medium">
                    Aging as at <DateText date={statement.to} />
                </h3>
                <p className="text-xs text-muted-foreground">
                    Days past due, by the open invoices.
                </p>
                <dl className="grid gap-2 sm:grid-cols-5">
                    {[
                        ['Current', statement.aging.current],
                        ['1-30', statement.aging.days1to30],
                        ['31-60', statement.aging.days31to60],
                        ['61-90', statement.aging.days61to90],
                        ['Over 90', statement.aging.over90],
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
