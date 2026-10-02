import { Receipt } from 'lucide-react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type AddOn = App.Data.Reservations.ReservationAddOnData;

/**
 * The Add-ons tab: flat rows grouped by unit. A nightly add-on has one row for each night.
 */
export function AddOnsTab({
    addOns,
    currency,
}: {
    addOns: AddOn[];
    currency: App.Enums.Currency | null;
}) {
    if (addOns.length === 0) {
        return (
            <EmptyState
                icon={Receipt}
                title="No add-ons"
                description="Extras sold with a unit appear here."
            />
        );
    }

    const groups = Map.groupBy(addOns, (addOn) => addOn.unitId);

    return (
        <div className="flex flex-col gap-6">
            {[...groups.values()].map((rows) => (
                <section key={rows[0].unitId} className="flex flex-col gap-2">
                    <h2 className="text-sm font-medium">{rows[0].unitLabel}</h2>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Charge item</TableHead>
                                <TableHead className="text-right">
                                    Quantity
                                </TableHead>
                                <TableHead>Service date</TableHead>
                                <TableHead className="text-right">
                                    Unit amount
                                </TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>{row.chargeItem}</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.quantity}
                                    </TableCell>
                                    <TableCell>
                                        <DateText date={row.serviceDate} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <MoneyText
                                            amount={row.unitAmount}
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
                </section>
            ))}
        </div>
    );
}
