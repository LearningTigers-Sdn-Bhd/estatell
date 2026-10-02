import { Plus, Receipt } from 'lucide-react';
import { useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { AddAddOnDialog } from '@/components/pms/reservations/add-add-on-dialog';
import { RemoveAddOnDialog } from '@/components/pms/reservations/remove-add-on-dialog';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type AddOn = App.Data.Reservations.ReservationAddOnData;
type Unit = App.Data.Reservations.ReservationUnitOptionData;

const OPEN = ['confirmed', 'checked_in'];

/**
 * The Add-ons tab: flat rows grouped by unit. A nightly add-on has one row for each night.
 */
export function AddOnsTab({
    reservationId,
    addOns,
    units,
    chargeItems,
    canManage,
    currency,
}: {
    reservationId: number;
    addOns: AddOn[];
    units: Unit[];
    chargeItems?: App.Data.Reservations.ChargeItemOptionData[];
    canManage: boolean;
    currency: App.Enums.Currency | null;
}) {
    const [adding, setAdding] = useState(false);
    const openUnits = units.filter((unit) => OPEN.includes(unit.status.value));
    const openIds = new Set(openUnits.map((unit) => unit.id));
    const canAdd = canManage && openUnits.length > 0;
    const groups = Map.groupBy(addOns, (addOn) => addOn.unitId);

    return (
        <div className="flex flex-col gap-6">
            {canAdd && (
                <div className="flex justify-end">
                    <Button variant="outline" onClick={() => setAdding(true)}>
                        <Plus data-icon="inline-start" />
                        Add add-on
                    </Button>
                </div>
            )}
            {addOns.length === 0 && (
                <EmptyState
                    icon={Receipt}
                    title="No add-ons"
                    description="Extras sold with a unit appear here."
                />
            )}
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
                                {canManage && (
                                    <TableHead className="w-12">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                )}
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
                                    {canManage && (
                                        <TableCell className="text-right">
                                            {openIds.has(row.unitId) && (
                                                <RemoveAddOnDialog
                                                    reservationId={
                                                        reservationId
                                                    }
                                                    addOn={row}
                                                />
                                            )}
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </section>
            ))}
            <AddAddOnDialog
                open={adding}
                onOpenChange={setAdding}
                reservationId={reservationId}
                units={openUnits}
                chargeItems={chargeItems}
                currency={currency}
            />
        </div>
    );
}
