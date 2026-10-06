import {
    CalendarClock,
    MoreHorizontal,
    RotateCcw,
    UserX,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { ChangeDatesDialog } from '@/components/pms/reservations/change-dates-dialog';
import { ReinstateUnitDialog } from '@/components/pms/reservations/reinstate-unit-dialog';
import { UnitPolicyFeeDialog } from '@/components/pms/reservations/unit-policy-fee-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type Unit = App.Data.Reservations.ReservationUnitData;

const ICONS: Record<App.Enums.UnitAction, LucideIcon> = {
    cancel: XCircle,
    'no-show': UserX,
    reinstate: RotateCcw,
    'change-dates': CalendarClock,
};

/**
 * The unit ⋯ menu and the forms it opens. The server sends only the actions the unit's status and the user's
 * permissions allow, so the menu has no rules of its own and hides itself when the list is empty.
 */
export function UnitActionsMenu({
    reservationId,
    unit,
    canWaiveFees,
    canOverrideRestrictions,
    currency,
}: {
    reservationId: number;
    unit: Unit;
    canWaiveFees: boolean;
    canOverrideRestrictions: boolean;
    currency: App.Enums.Currency | null;
}) {
    const [action, setAction] = useState<App.Enums.UnitAction | null>(null);
    // The form keeps its kind until the exit animation ends.
    const [lastAction, setLastAction] =
        useState<App.Enums.UnitAction>('cancel');

    if (unit.actions.length === 0) {
        return null;
    }

    const choose = (next: App.Enums.UnitAction) => {
        setAction(next);
        setLastAction(next);
    };
    const common = {
        reservationId,
        unit,
        currency,
        onOpenChange: (open: boolean) => !open && setAction(null),
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger
                    render={<Button variant="outline" size="icon" />}
                    aria-label="Unit actions"
                >
                    <MoreHorizontal />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    {unit.actions.map((item) => {
                        const Icon = ICONS[item.key];

                        return (
                            <DropdownMenuItem
                                key={item.key}
                                onClick={() => choose(item.key)}
                            >
                                <Icon />
                                {item.label}
                            </DropdownMenuItem>
                        );
                    })}
                </DropdownMenuContent>
            </DropdownMenu>

            <UnitPolicyFeeDialog
                {...common}
                open={action === 'cancel' || action === 'no-show'}
                kind={lastAction === 'no-show' ? 'no-show' : 'cancel'}
                canWaiveFees={canWaiveFees}
            />
            <ReinstateUnitDialog {...common} open={action === 'reinstate'} />
            <ChangeDatesDialog
                {...common}
                open={action === 'change-dates'}
                canWaiveFees={canWaiveFees}
                canOverrideRestrictions={canOverrideRestrictions}
            />
        </>
    );
}
