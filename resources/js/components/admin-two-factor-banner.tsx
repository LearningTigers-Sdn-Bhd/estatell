import { Link, usePage } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { edit } from '@/routes/account/security';

/**
 * Shown to admins who have not confirmed two-factor while admin actions require it.
 */
export function AdminTwoFactorBanner() {
    const { adminNeedsTwoFactor } = usePage().props;

    if (!adminNeedsTwoFactor) {
        return null;
    }

    return (
        <div className="px-4 pt-4">
            <div
                role="status"
                className="flex items-center gap-3 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-sm"
            >
                <ShieldAlert className="size-4 shrink-0 text-warning" />
                <p className="flex-1 font-medium">
                    Set up two-factor to use admin actions
                </p>
                <Link
                    href={edit()}
                    className={cn(
                        buttonVariants({ size: 'sm' }),
                        'bg-warning text-warning-foreground hover:bg-warning/80',
                    )}
                >
                    Set up
                </Link>
            </div>
        </div>
    );
}
