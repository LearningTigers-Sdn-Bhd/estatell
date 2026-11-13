import { Info } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

/**
 * "Room total" with an info tooltip: the figure counts room nights only, so a correct payment never looks like an
 * overpayment. Used by the reservation list and card and the Stay View peek sheet.
 */
export function RoomTotalLabel() {
    const label = 'Room nights only, before tax and extras.';

    return (
        <span className="inline-flex items-center gap-1">
            Room total
            <Tooltip>
                <TooltipTrigger
                    render={
                        <span
                            role="img"
                            aria-label={label}
                            className="relative z-10 inline-flex shrink-0"
                        />
                    }
                >
                    <Info
                        aria-hidden
                        className="size-3.5 text-muted-foreground"
                    />
                </TooltipTrigger>
                <TooltipContent>{label}</TooltipContent>
            </Tooltip>
        </span>
    );
}
