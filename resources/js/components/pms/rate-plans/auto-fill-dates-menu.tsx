import { ChevronDown, WandSparkles } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { AUTO_FILL_RANGES, monthsEnd } from './bulk-price-model';

/**
 * Fills the date range of a pricing form from today: next month, 3, 6 or 12 months. It only fills the dates.
 */
export function AutoFillDatesMenu({
    today,
    onPick,
}: {
    /** The property's today, `Y-m-d`. */
    today: string;
    onPick: (from: string, to: string) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                render={<Button type="button" variant="outline" />}
            >
                <WandSparkles data-icon="inline-start" />
                Auto fill dates
                <ChevronDown data-icon="inline-end" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {AUTO_FILL_RANGES.map((range) => (
                    <DropdownMenuItem
                        key={range.months}
                        onClick={() =>
                            onPick(today, monthsEnd(today, range.months))
                        }
                    >
                        {range.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
