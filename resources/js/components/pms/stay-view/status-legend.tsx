import {
    BellOff,
    CircleCheck,
    Diamond,
    Info,
    ShieldCheck,
    SprayCan,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

/** An icon button that opens what the marks on the Stay View mean. */
export function StatusLegend() {
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
                        <CircleCheck
                            aria-hidden
                            className="size-4 text-success"
                        />
                        Clean
                    </li>
                    <li className="flex items-center gap-2">
                        <ShieldCheck aria-hidden className="size-4 text-info" />
                        Inspected
                    </li>
                    <li className="flex items-center gap-2">
                        <SprayCan aria-hidden className="size-4 text-warning" />
                        Dirty
                    </li>
                    <li className="flex items-center gap-2">
                        <BellOff
                            aria-hidden
                            className="size-4 text-muted-foreground"
                        />
                        Do not disturb
                    </li>
                    <li className="flex items-center gap-2">
                        <Diamond
                            aria-hidden
                            className="size-4 text-muted-foreground"
                        />
                        Part of a group booking
                    </li>
                    <li className="flex items-center gap-2">
                        <span
                            aria-hidden
                            className="h-4 w-1 rounded-sm bg-warning"
                        />
                        Balance due
                    </li>
                    <li className="flex items-center gap-2">
                        <span
                            aria-hidden
                            className="h-4 w-6 rounded-sm bg-[repeating-linear-gradient(135deg,transparent_0_4px,var(--color-border)_4px_8px)] ring-1 ring-border"
                        />
                        Room or bed blocked
                    </li>
                </ul>
            </PopoverContent>
        </Popover>
    );
}
