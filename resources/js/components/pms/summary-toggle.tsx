import { PanelRightClose, PanelRightOpen } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

/**
 * Hides or shows the reservation summary panel, on the Units and Guests tabs.
 */
export function SummaryToggle({
    hidden,
    onToggle,
}: {
    hidden: boolean;
    onToggle: () => void;
}) {
    const label = hidden ? 'Show summary' : 'Hide summary';

    return (
        <Tooltip>
            <TooltipTrigger
                render={
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label={label}
                        aria-pressed={hidden}
                        onClick={onToggle}
                    />
                }
            >
                {hidden ? <PanelRightOpen /> : <PanelRightClose />}
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}
