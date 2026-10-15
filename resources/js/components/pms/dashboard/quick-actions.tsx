import { Link } from '@inertiajs/react';
import { Moon, Plus, Search, UserRoundPlus } from 'lucide-react';
import { Button, buttonVariants } from '@/components/ui/button';
import { index, newMethod, walkIn } from '@/routes/reservations';

/**
 * The buttons that start the day's work. Each follows its permission, and a user with none sees no row.
 * Find reservation opens the reservations list until the command palette is built.
 */
export function QuickActions({
    can,
    onRunAudit,
}: {
    can: App.Data.Dashboard.DashboardAccessData;
    onRunAudit: () => void;
}) {
    if (
        !can.canManageReservations &&
        !can.canViewReservations &&
        !can.canRunNightAudit
    ) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            {can.canManageReservations && (
                <>
                    <Link href={newMethod.url()} className={buttonVariants()}>
                        <Plus data-icon="inline-start" />
                        New booking
                    </Link>
                    <Link
                        href={walkIn.url()}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        <UserRoundPlus data-icon="inline-start" />
                        Walk-in
                    </Link>
                </>
            )}
            {can.canViewReservations && (
                <Link
                    href={index.url()}
                    className={buttonVariants({ variant: 'outline' })}
                >
                    <Search data-icon="inline-start" />
                    Find reservation
                </Link>
            )}
            {can.canRunNightAudit && (
                <Button
                    variant="outline"
                    className="sm:ml-auto"
                    onClick={onRunAudit}
                >
                    <Moon data-icon="inline-start" />
                    Run night audit
                </Button>
            )}
        </div>
    );
}
