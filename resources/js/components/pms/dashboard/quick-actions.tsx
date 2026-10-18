import { Link } from '@inertiajs/react';
import { Plus, Search, UserRoundPlus } from 'lucide-react';
import { buttonVariants } from '@/components/ui/button';
import { index, newMethod, walkIn } from '@/routes/reservations';

/**
 * The buttons that start the day's work. Each follows its permission, and a user with none sees no row.
 * Find reservation opens the reservations list until the command palette is built. Run night audit sits in the page header.
 */
export function QuickActions({
    can,
}: {
    can: App.Data.Dashboard.DashboardAccessData;
}) {
    if (!can.canManageReservations && !can.canViewReservations) {
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
        </div>
    );
}
