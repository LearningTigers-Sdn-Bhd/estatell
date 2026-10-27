import {
    CalendarClock,
    Clock,
    LogIn,
    LogOut,
    MoreHorizontal,
    MoveRight,
    RotateCcw,
    Undo2,
    UserX,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { CheckInDialog } from '@/components/pms/reservations/check-in-dialog';
import { CheckOutDialog } from '@/components/pms/reservations/check-out-dialog';
import { DayUseCheckOutDialog } from '@/components/pms/reservations/day-use-check-out-dialog';
import { ChangeDatesDialog } from '@/components/pms/reservations/change-dates-dialog';
import { MoveRoomDialog } from '@/components/pms/reservations/move-room-dialog';
import { LateCheckOutDialog } from '@/components/pms/reservations/late-check-out-dialog';
import { ReinstateUnitDialog } from '@/components/pms/reservations/reinstate-unit-dialog';
import { UndoStayDialog } from '@/components/pms/reservations/undo-stay-dialog';
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
    'check-in': LogIn,
    'check-out': LogOut,
    'check-out-as-day-use': LogOut,
    'late-check-out': Clock,
    cancel: XCircle,
    'no-show': UserX,
    reinstate: RotateCcw,
    'change-dates': CalendarClock,
    'move-room': MoveRight,
    'undo-check-in': Undo2,
    'undo-check-out': Undo2,
};

type DialogProps = {
    reservationId: number;
    unit: Unit;
    canWaiveFees: boolean;
    canOverrideRestrictions: boolean;
    paymentOptions?: App.Data.Payments.PaymentFormOptionsData;
    currency: App.Data.Property.CurrencyData | null;
};

/**
 * The forms of the unit actions, driven by the caller. The unit ⋯ menu and, for a single-unit reservation, More both
 * open them, so the same Dialog runs from either place. `lastAction` keeps a form's kind until its exit animation
 * ends.
 */
export function UnitActionDialogs({
    reservationId,
    unit,
    canWaiveFees,
    canOverrideRestrictions,
    paymentOptions,
    currency,
    action,
    lastAction,
    onChoose,
    onClose,
}: DialogProps & {
    action: App.Enums.UnitAction | null;
    lastAction: App.Enums.UnitAction;
    onChoose: (action: App.Enums.UnitAction) => void;
    onClose: () => void;
}) {
    const common = {
        reservationId,
        unit,
        currency,
        onOpenChange: (open: boolean) => !open && onClose(),
    };

    return (
        <>
            <CheckInDialog
                {...common}
                open={action === 'check-in'}
                paymentOptions={paymentOptions}
                canAssignRoom={unit.actions.some(
                    (item) => item.key === 'move-room',
                )}
                onAssignRoom={() => onChoose('move-room')}
            />
            <CheckOutDialog
                {...common}
                open={action === 'check-out'}
                paymentOptions={paymentOptions}
                canWaiveFees={canWaiveFees}
            />
            <DayUseCheckOutDialog
                {...common}
                open={action === 'check-out-as-day-use'}
                paymentOptions={paymentOptions}
            />
            <LateCheckOutDialog
                {...common}
                open={action === 'late-check-out'}
                canWaiveFees={canWaiveFees}
            />
            <UnitPolicyFeeDialog
                {...common}
                open={action === 'cancel' || action === 'no-show'}
                kind={lastAction === 'no-show' ? 'no-show' : 'cancel'}
                canWaiveFees={canWaiveFees}
            />
            <ReinstateUnitDialog {...common} open={action === 'reinstate'} />
            <MoveRoomDialog {...common} open={action === 'move-room'} />
            <UndoStayDialog
                {...common}
                open={action === 'undo-check-in' || action === 'undo-check-out'}
                kind={
                    lastAction === 'undo-check-out' ? 'check-out' : 'check-in'
                }
            />
            <ChangeDatesDialog
                {...common}
                open={action === 'change-dates'}
                canWaiveFees={canWaiveFees}
                canOverrideRestrictions={canOverrideRestrictions}
            />
        </>
    );
}

/**
 * The unit ⋯ menu and the forms it opens. The server sends only the actions the unit's status and the user's
 * permissions allow, so the menu has no rules of its own and hides itself when the list is empty.
 */
export function UnitActionsMenu(props: DialogProps) {
    const { unit } = props;
    const [action, setAction] = useState<App.Enums.UnitAction | null>(null);
    const [lastAction, setLastAction] =
        useState<App.Enums.UnitAction>('cancel');

    if (unit.actions.length === 0) {
        return null;
    }

    const choose = (next: App.Enums.UnitAction) => {
        setAction(next);
        setLastAction(next);
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
                <DropdownMenuContent align="end" className="min-w-56">
                    {unit.actions.map((item) => {
                        const Icon = ICONS[item.key];

                        return (
                            <DropdownMenuItem
                                key={item.key}
                                disabled={item.disabled}
                                onClick={() => choose(item.key)}
                            >
                                <Icon />
                                <span className="flex flex-col gap-1">
                                    <span>{item.label}</span>
                                    {item.note && (
                                        <span className="max-w-64 text-xs whitespace-normal text-muted-foreground">
                                            {item.note}
                                        </span>
                                    )}
                                </span>
                            </DropdownMenuItem>
                        );
                    })}
                </DropdownMenuContent>
            </DropdownMenu>

            <UnitActionDialogs
                {...props}
                action={action}
                lastAction={lastAction}
                onChoose={choose}
                onClose={() => setAction(null)}
            />
        </>
    );
}
