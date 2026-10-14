import { Link } from '@inertiajs/react';
import {
    ChartColumn,
    Moon,
    Plus,
    Search,
    Settings,
    UserRoundPlus,
} from 'lucide-react';
import { cn } from 'cn';
import { Button, buttonVariants } from '@/components/ui/button';
import { index, newMethod, walkIn } from '@/routes/reservations';
import { revenue } from '@/routes/reports';
import { edit } from '@/routes/settings';

const toneClasses = {
    primary: '',
    neutral: '',
    info: 'border-info/30 bg-info/10 text-info hover:bg-info/20 hover:text-info',
    success:
        'border-success/30 bg-success/10 text-success hover:bg-success/20 hover:text-success',
    warning:
        'border-warning/30 bg-warning/10 text-warning hover:bg-warning/20 hover:text-warning',
};

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
        !can.canRunNightAudit &&
        !can.canViewReports &&
        !can.canManageSettings
    ) {
        return null;
    }

    const tile = (tone: keyof typeof toneClasses = 'neutral') =>
        cn(
            buttonVariants({
                variant: tone === 'primary' ? 'default' : 'outline',
            }),
            'h-24 flex-col gap-1 p-1 text-center whitespace-normal [&_svg]:size-9',
            toneClasses[tone],
        );

    return (
        <div className="grid grid-cols-2 gap-x-2 gap-y-4 sm:grid-cols-4 lg:grid-cols-6">
            {can.canManageReservations && (
                <>
                    <Link href={newMethod.url()} className={tile('primary')}>
                        <Plus />
                        New booking
                    </Link>
                    <Link href={walkIn.url()} className={tile('success')}>
                        <UserRoundPlus />
                        Walk-in
                    </Link>
                </>
            )}
            {can.canViewReservations && (
                <Link href={index.url()} className={tile('info')}>
                    <Search />
                    Find reservation
                </Link>
            )}
            {can.canRunNightAudit && (
                <Button
                    variant="outline"
                    className={tile('warning')}
                    onClick={onRunAudit}
                >
                    <Moon />
                    Run night audit
                </Button>
            )}
            {can.canViewReports && (
                <Link href={revenue.url()} className={tile('info')}>
                    <ChartColumn />
                    Reports
                </Link>
            )}
            {can.canManageSettings && (
                <Link href={edit.url()} className={tile()}>
                    <Settings />
                    Settings
                </Link>
            )}
        </div>
    );
}
