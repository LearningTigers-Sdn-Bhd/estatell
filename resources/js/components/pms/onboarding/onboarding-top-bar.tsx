import { usePage } from '@inertiajs/react';
import { OnboardingHelp } from '@/components/pms/onboarding/onboarding-help';
import type { OnboardingProgress } from '@/components/pms/onboarding/onboarding-steps';
import AppLogo from '@/components/app-logo';
import { NavUser } from '@/components/nav-user';

/**
 * The top bar of the wizard: logo, property name and code (not a link), progress, Help and the user menu. There is no
 * property switcher and no navigation to the rest of the PMS.
 */
export function OnboardingTopBar({
    progress,
}: {
    progress: OnboardingProgress;
}) {
    const { property } = usePage().props;

    return (
        <header className="flex h-14 shrink-0 items-center gap-3 border-b bg-background px-4">
            <div className="flex shrink-0 items-center">
                <AppLogo />
            </div>
            <div className="min-w-0 flex-1 leading-tight">
                <p className="truncate text-sm font-semibold">
                    {property?.name}
                </p>
                <p className="truncate text-xs text-muted-foreground">
                    ID {property?.code}
                </p>
            </div>
            <p className="hidden text-sm text-muted-foreground md:block">
                Setup {progress.requiredDone} of {progress.requiredTotal}{' '}
                required
                {progress.remainingMinutes > 0 &&
                    ` · ~${progress.remainingMinutes} min`}
            </p>
            <OnboardingHelp />
            <NavUser />
        </header>
    );
}
