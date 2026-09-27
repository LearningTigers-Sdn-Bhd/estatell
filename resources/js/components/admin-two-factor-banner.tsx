import { Link, usePage } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { Alert, AlertAction, AlertTitle } from '@/components/ui/alert';
import { buttonVariants } from '@/components/ui/button';
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
            <Alert className="border-warning/50 bg-warning/10">
                <ShieldAlert className="text-warning" />
                <AlertTitle>Set up two-factor to use admin actions</AlertTitle>
                <AlertAction>
                    <Link
                        href={edit()}
                        className={buttonVariants({
                            size: 'sm',
                            variant: 'outline',
                        })}
                    >
                        Set up
                    </Link>
                </AlertAction>
            </Alert>
        </div>
    );
}
