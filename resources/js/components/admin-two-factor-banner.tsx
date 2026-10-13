import { Link, usePage } from '@inertiajs/react';
import { ShieldAlert, X } from 'lucide-react';
import { useState } from 'react';
import { Button, buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { edit } from '@/routes/account/security';

const DISMISSED_KEY = 'estatell.support-2fa-banner-dismissed';

function isDismissed(): boolean {
    try {
        return window.localStorage.getItem(DISMISSED_KEY) === '1';
    } catch {
        return false;
    }
}

function rememberDismissed(): void {
    try {
        window.localStorage.setItem(DISMISSED_KEY, '1');
    } catch {
        // Browser storage can be blocked. The banner still hides until the page reloads.
    }
}

/**
 * Shown to admins who have not confirmed two-factor. While the switch requires it, the banner cannot be dismissed.
 * While it does not, the banner is a reminder that the admin can dismiss for this browser.
 */
export function AdminTwoFactorBanner() {
    const { supportTwoFactor } = usePage().props;
    const [dismissed, setDismissed] = useState(isDismissed);

    if (supportTwoFactor === null) {
        return null;
    }

    const required = supportTwoFactor.required;

    if (!required && dismissed) {
        return null;
    }

    function dismiss(): void {
        rememberDismissed();
        setDismissed(true);
    }

    return (
        <div className="px-4 pt-4">
            <div
                role="status"
                className="flex items-center gap-3 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-sm"
            >
                <ShieldAlert className="size-4 shrink-0 text-warning" />
                <p className="flex-1 font-medium">
                    {required
                        ? 'Set up two-factor to use admin actions'
                        : 'Two-factor is recommended for support access'}
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
                {!required && (
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Dismiss"
                        onClick={dismiss}
                    >
                        <X />
                    </Button>
                )}
            </div>
        </div>
    );
}
