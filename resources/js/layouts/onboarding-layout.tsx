import { Fieldset } from '@base-ui/react/fieldset';
import { Link, usePage } from '@inertiajs/react';
import { Hourglass } from 'lucide-react';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import { OnboardingFooter } from '@/components/pms/onboarding/onboarding-footer';
import { OnboardingPhaseBar } from '@/components/pms/onboarding/onboarding-phase-bar';
import {
    type OnboardingLayoutProps,
    widthClass,
} from '@/components/pms/onboarding/onboarding-steps';
import { OnboardingTopBar } from '@/components/pms/onboarding/onboarding-top-bar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { buttonVariants } from '@/components/ui/button';
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
    width,
    primaryAction = null,
    children,
}: Partial<OnboardingLayoutProps> & { children: React.ReactNode }) {
    const { onboarding } = usePage().props;

    if (!onboarding) {
        return <>{children}</>;
    }

    const preview = onboarding.awaitingApproval && step !== 'review';
    const reviewUrl =
        onboarding.phases
            .flatMap((phase) => phase.steps)
            .find((item) => item.value === 'review')?.url ?? null;

    return (
        <div className="flex h-svh flex-col overflow-clip overscroll-none bg-background text-foreground">
            <ImpersonationBanner />
            <OnboardingTopBar progress={onboarding} />
            <OnboardingPhaseBar progress={onboarding} step={step} />
            <main className="min-h-0 flex-1 overflow-y-auto">
                <div
                    className={cn(
                        'mx-auto w-full px-4 py-6 md:py-8',
                        width && 'lg:px-8',
                        widthClass[width ?? 'default'],
                    )}
                >
                    {preview ? (
                        <div className="flex flex-col gap-6">
                            <Alert>
                                <Hourglass />
                                <AlertTitle>Waiting for approval</AlertTitle>
                                <AlertDescription className="flex flex-wrap items-center gap-x-3 gap-y-2">
                                    <span>
                                        Waiting for approval. Withdraw to make
                                        changes.
                                    </span>
                                    {reviewUrl !== null && (
                                        <Link
                                            href={reviewUrl}
                                            className={buttonVariants({
                                                variant: 'link',
                                                size: 'sm',
                                                className: 'h-auto p-0',
                                            })}
                                        >
                                            Go to Review →
                                        </Link>
                                    )}
                                </AlertDescription>
                            </Alert>
                            <Fieldset.Root disabled>{children}</Fieldset.Root>
                        </div>
                    ) : (
                        children
                    )}
                </div>
            </main>
            <OnboardingFooter
                progress={onboarding}
                layout={{
                    step,
                    footer,
                    formId,
                    processing,
                    width,
                    primaryAction,
                }}
            />
        </div>
    );
}
