import { usePage } from '@inertiajs/react';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import { OnboardingFooter } from '@/components/pms/onboarding/onboarding-footer';
import { OnboardingPhaseBar } from '@/components/pms/onboarding/onboarding-phase-bar';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
import { OnboardingTopBar } from '@/components/pms/onboarding/onboarding-top-bar';
import { cn } from '@/lib/utils';

/**
 * The layout of the setup wizard. It replaces the PMS sidebar while a property is being set up. The content column
 * scrolls and the footer sits outside it, so it never covers the last field.
 */
export default function OnboardingLayout({
    step = null,
    footer = 'auto',
    formId = null,
    processing = false,
    wide = false,
    children,
}: Partial<OnboardingLayoutProps> & { children: React.ReactNode }) {
    const { onboarding } = usePage().props;

    if (!onboarding) {
        return <>{children}</>;
    }

    return (
        <div className="flex h-svh flex-col overflow-hidden bg-background text-foreground">
            <ImpersonationBanner />
            <OnboardingTopBar progress={onboarding} />
            <OnboardingPhaseBar progress={onboarding} step={step} />
            <main className="min-h-0 flex-1 overflow-y-auto">
                <div
                    className={cn(
                        'mx-auto w-full px-4 py-6 md:py-8',
                        wide ? 'max-w-none px-6 lg:px-12' : 'max-w-180',
                    )}
                >
                    {children}
                </div>
            </main>
            <OnboardingFooter
                progress={onboarding}
                layout={{ step, footer, formId, processing }}
            />
        </div>
    );
}
