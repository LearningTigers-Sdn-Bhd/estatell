import {
    ArrowUpRight,
    CalendarPlus,
    DoorOpen,
    LogIn,
    LogOut,
    Receipt,
    RotateCw,
    Ship,
    UserX,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/** Icon names sent by `App\Data\NightAudit\ReadinessActionData`, used by the night audit sheet and the dashboard To do. */
const icons: Record<string, LucideIcon> = {
    'door-open': DoorOpen,
    'user-x': UserX,
    'calendar-plus': CalendarPlus,
    'log-in': LogIn,
    'log-out': LogOut,
    receipt: Receipt,
    'rotate-cw': RotateCw,
    ship: Ship,
    x: X,
    'arrow-up-right': ArrowUpRight,
};

/** The Lucide icon for an action, with an arrow for a name it does not know. */
export function actionIcon(name: string): LucideIcon {
    return icons[name] ?? ArrowUpRight;
}
