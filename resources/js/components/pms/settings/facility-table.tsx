import { Link } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Clock,
    Ellipsis,
    Pencil,
    Power,
    PowerOff,
    ReceiptText,
    Tent,
} from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { edit as editChargeItem } from '@/routes/settings/charge-items';
import { index as sessionsIndex } from '@/routes/settings/facilities/sessions';

type Facility = App.Data.Facilities.FacilityData;
type Option = { value: string; label: string };

const ACTIVE: App.Data.Shared.BadgeData = {
    value: 'active',
    label: 'Active',
    tone: 'success',
};
const OFF: App.Data.Shared.BadgeData = {
    value: 'off',
    label: 'Off',
    tone: 'neutral',
};

function labelOf(options: Option[], value: string): string {
    return options.find((option) => option.value === value)?.label ?? value;
}

/**
 * The Facilities settings list in its saved order. Facilities that are off stay in place and are muted. With no
 * facilities the table keeps its header and shows the empty state in one row.
 */
export function FacilityTable({
    facilities,
    sharingOptions,
    capacityUnits,
    rateBases,
    currency,
    busyIds,
    onEdit,
    onMove,
    onToggleActive,
}: {
    facilities: Facility[];
    sharingOptions: Option[];
    capacityUnits: Option[];
    rateBases: Option[];
    currency: App.Data.Property.CurrencyData | null;
    busyIds: Set<number>;
    onEdit: (facility: Facility) => void;
    onMove: (from: number, to: number) => void;
    onToggleActive: (facility: Facility, active: boolean) => void;
}) {
    return (
        <div className="overflow-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Sharing</TableHead>
                        <TableHead className="text-right">Capacity</TableHead>
                        <TableHead className="text-right">
                            Rate{currency ? ` (${currency.code})` : ''}
                        </TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead className="w-12">
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {facilities.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={6}>
                                <EmptyState
                                    icon={Tent}
                                    title="No facilities yet."
                                    description="Add the halls, equipment and activities guests can book."
                                />
                            </TableCell>
                        </TableRow>
                    )}
                    {facilities.map((facility, position) => (
                        <TableRow
                            key={facility.id}
                            className={
                                facility.active
                                    ? undefined
                                    : 'text-muted-foreground'
                            }
                        >
                            <TableCell className="font-medium">
                                {facility.name}
                            </TableCell>
                            <TableCell>
                                {labelOf(sharingOptions, facility.sharing)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {facility.capacity}{' '}
                                {labelOf(capacityUnits, facility.capacityUnit)}
                            </TableCell>
                            <TableCell className="text-right">
                                {facility.amount === null ? (
                                    <span className="text-muted-foreground">
                                        Charge by hand
                                    </span>
                                ) : (
                                    <>
                                        <MoneyText
                                            amount={facility.amount}
                                            currency={currency}
                                            plain
                                        />{' '}
                                        <span className="text-muted-foreground">
                                            {labelOf(
                                                rateBases,
                                                facility.rateBasis,
                                            ).toLowerCase()}
                                        </span>
                                    </>
                                )}
                            </TableCell>
                            <TableCell>
                                <StatusBadge
                                    status={facility.active ? ACTIVE : OFF}
                                />
                            </TableCell>
                            <TableCell>
                                <DropdownMenu>
                                    <DropdownMenuTrigger
                                        render={
                                            <Button
                                                variant="ghost"
                                                size="icon-sm"
                                            />
                                        }
                                        aria-label={`Actions for ${facility.name}`}
                                        disabled={busyIds.has(facility.id)}
                                    >
                                        <Ellipsis />
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        className="w-44"
                                    >
                                        <DropdownMenuItem
                                            onClick={() => onEdit(facility)}
                                        >
                                            <Pencil />
                                            Edit
                                        </DropdownMenuItem>
                                        {facility.sharing === 'shared' && (
                                            <DropdownMenuItem
                                                render={
                                                    <Link
                                                        href={sessionsIndex.url(
                                                            {
                                                                facility:
                                                                    facility.id,
                                                            },
                                                        )}
                                                    />
                                                }
                                            >
                                                <Clock />
                                                Sessions
                                            </DropdownMenuItem>
                                        )}
                                        <DropdownMenuItem
                                            disabled={position === 0}
                                            onClick={() =>
                                                onMove(position, position - 1)
                                            }
                                        >
                                            <ArrowUp />
                                            Move up
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            disabled={
                                                position ===
                                                facilities.length - 1
                                            }
                                            onClick={() =>
                                                onMove(position, position + 1)
                                            }
                                        >
                                            <ArrowDown />
                                            Move down
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            onClick={() =>
                                                onToggleActive(
                                                    facility,
                                                    !facility.active,
                                                )
                                            }
                                        >
                                            {facility.active ? (
                                                <PowerOff />
                                            ) : (
                                                <Power />
                                            )}
                                            {facility.active
                                                ? 'Turn off'
                                                : 'Turn on'}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            render={
                                                <Link
                                                    href={editChargeItem.url({
                                                        chargeItem:
                                                            facility.chargeItemId,
                                                    })}
                                                />
                                            }
                                        >
                                            <ReceiptText />
                                            More settings
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
