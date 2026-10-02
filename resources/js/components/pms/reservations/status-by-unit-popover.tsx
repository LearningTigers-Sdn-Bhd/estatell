import { ChevronDown } from 'lucide-react';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

/**
 * The reservation status as a badge that opens the status of every unit: room type, room or bed, and status.
 */
export function StatusByUnitPopover({
    status,
    units,
}: {
    status: App.Data.Shared.BadgeData;
    units: App.Data.Reservations.ReservationUnitOptionData[];
}) {
    return (
        <Popover>
            <PopoverTrigger
                render={
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label="Status by unit"
                    />
                }
            >
                <StatusBadge status={status} />
                <ChevronDown data-icon="inline-end" />
            </PopoverTrigger>
            <PopoverContent align="start" className="w-80">
                <p className="mb-2 text-sm font-medium">Status by unit</p>
                <ul className="flex flex-col gap-2">
                    {units.map((unit) => (
                        <li
                            key={unit.id}
                            className="flex items-center justify-between gap-3 text-sm"
                        >
                            <span className="min-w-0 truncate">
                                {unit.label}
                            </span>
                            <StatusBadge status={unit.status} />
                        </li>
                    ))}
                </ul>
            </PopoverContent>
        </Popover>
    );
}
