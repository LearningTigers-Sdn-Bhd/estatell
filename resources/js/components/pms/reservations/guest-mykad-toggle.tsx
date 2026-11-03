import { CircleCheck, IdCard, ScanLine } from 'lucide-react';
import type { MykadToggleState } from '@/components/pms/reservations/guest-mykad';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

const TOOLTIP: Record<MykadToggleState, string> = {
    off: 'Read MyKad for this guest',
    on: 'Stop reading for this guest',
    filled: 'Clear and read another card',
};

function ariaLabel(state: MykadToggleState, label: string): string {
    if (state === 'off') {
        return `Read MyKad for ${label}`;
    }

    return state === 'on'
        ? `Stop reading MyKad for ${label}`
        : `Clear the MyKad scan of ${label} and read another card`;
}

/**
 * Picks the guest row the next MyKad fills. One row is on at a time. A filled row clears its scan on click and turns on.
 */
export function GuestMykadToggle({
    label,
    state,
    onPress,
}: {
    label: string;
    state: MykadToggleState;
    onPress: () => void;
}) {
    return (
        <Tooltip>
            <TooltipTrigger
                render={
                    <Button
                        type="button"
                        variant={state === 'on' ? 'default' : 'outline'}
                        size="icon-sm"
                        aria-label={ariaLabel(state, label)}
                        aria-pressed={state === 'on'}
                        onClick={onPress}
                    />
                }
            >
                {state === 'off' && <IdCard />}
                {state === 'on' && <ScanLine />}
                {state === 'filled' && <CircleCheck className="text-success" />}
            </TooltipTrigger>
            <TooltipContent>{TOOLTIP[state]}</TooltipContent>
        </Tooltip>
    );
}
