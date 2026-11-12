import { MoneyText } from '@/components/pms/money-text';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type Line = App.Data.Reservations.AgentBillData['agentLines'][number];

/**
 * The bill of a portal booking as tables: what the company pays, then what the guest pays at the resort. Lines with
 * the same name are already added up by the server, so there is no unit price.
 */
export function PortalBillTable({
    bill,
    currency,
}: {
    bill: App.Data.Reservations.AgentBillData;
    currency: App.Data.Property.CurrencyData | null;
}) {
    return (
        <div className="flex flex-col gap-6">
            {bill.nationalityMissing && (
                <p className="rounded-md bg-muted px-3 py-2 text-sm">
                    A guest has no nationality yet, so tourism tax is not
                    included. Foreign guests pay it.
                </p>
            )}
            <BillPart
                title="You pay"
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
        <section className="flex flex-col gap-2">
            <h3 className="text-sm font-medium">{title}</h3>
            <div className="overflow-hidden rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Item</TableHead>
                            <TableHead className="w-24 text-right">
                                Qty
                            </TableHead>
                            <TableHead className="w-40 text-right">
                                Amount
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {lines.map((line) => (
                            <TableRow key={line.label}>
                                <TableCell>{line.label}</TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {line.quantity}
                                </TableCell>
                                <TableCell className="text-right">
                                    <MoneyText
                                        amount={line.amount}
                                        currency={currency}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell colSpan={2} className="font-medium">
                                Total
                            </TableCell>
                            <TableCell className="text-right font-medium">
                                <MoneyText amount={total} currency={currency} />
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </div>
        </section>
    );
}
