import { Ban, Info, Link2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

/**
 * An icon button that opens what the markers in a rate calendar cell mean.
 */
export function RateCalendarLegend() {
    return (
        <Popover>
            <PopoverTrigger
                render={
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label="Legend"
                        title="Legend"
                    />
                }
            >
                <Info />
            </PopoverTrigger>
            <PopoverContent align="end" className="w-64">
                <ul className="flex flex-col gap-2 text-sm">
                    <li className="flex items-center gap-2">
                        <span aria-hidden className="flex w-10">
                            <Ban className="size-4 text-destructive" />
                        </span>
                        Stop sell
                    </li>
                    <li className="flex items-center gap-2">
                        <span aria-hidden className="flex w-10">
                            <span className="rounded-sm bg-warning px-1 text-xs text-warning-foreground">
                                CTA
                            </span>
                        </span>
                        Closed to arrival
                    </li>
                    <li className="flex items-center gap-2">
                        <span aria-hidden className="flex w-10">
                            <span className="rounded-sm bg-warning px-1 text-xs text-warning-foreground">
                                CTD
                            </span>
                        </span>
                        Closed to departure
                    </li>
                    <li className="flex items-center gap-2">
                        <span aria-hidden className="w-10 text-xs">
                            Min 2
                        </span>
                        Minimum nights
                    </li>
                    <li className="flex items-center gap-2">
                        <span aria-hidden className="flex w-10">
                            <Link2 className="size-4 text-info" />
                        </span>
                        Price follows the parent plan
                    </li>
                </ul>
            </PopoverContent>
        </Popover>
    );
}
