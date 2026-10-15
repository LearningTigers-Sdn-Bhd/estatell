import { Link, router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { store as skip } from '@/routes/onboarding/skips';
import {
    type OnboardingLayoutProps,
    type OnboardingProgress,
    neighbours,
    widthClass,
} from '@/components/pms/onboarding/onboarding-steps';
import { Button, buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

/**
 * The fixed footer. A form step shows Back on the left, and Skip (optional steps only; Taxes has its own "We don't charge tax" answer) with Save & continue on the right. Save submits the form
 * named by `formId`. A locked step is a preview with Back and Next only. A page can ask for Back only, or add a `primaryAction` button on the right.
 */
export function OnboardingFooter({
    progress,
    layout,
}: {
    progress: OnboardingProgress;
    layout: OnboardingLayoutProps;
}) {
    const { current, previous, next } = neighbours(progress, layout.step);
    const preview = layout.footer === 'auto' && current?.state === 'locked';
    const editing = layout.footer === 'auto' && !preview;
    const canSkip =
        editing &&
        current !== null &&
        current.isSkippable &&
        current.value !== 'taxes' &&
        current.state !== 'done';

    return (
        <footer className="shrink-0 border-t bg-background px-4 py-3">
            <div
                className={cn(
                    'mx-auto flex w-full items-center justify-between gap-3',
                    widthClass[layout.width ?? 'default'],
                )}
            >
                <div className="flex items-center gap-2">
                    {previous?.url && (
                        <Link
                            href={previous.url}
                            className={buttonVariants({ variant: 'outline' })}
                            aria-label={`Back to ${previous.label}`}
                        >
                            <ArrowLeft data-icon="inline-start" />
                            <span className="max-md:sr-only">Back</span>
                        </Link>
                    )}
                </div>

                <div className="flex items-center gap-2">
                    {canSkip && current !== null && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={layout.processing}
                            focusableWhenDisabled
                            onClick={() =>
                                router.post(skip.url({ step: current.value }))
                            }
                        >
                            Skip
                        </Button>
                    )}
                    {layout.primaryAction && (
                        <PrimaryAction action={layout.primaryAction} />
                    )}
                    {editing && layout.formId !== null && (
                        <Button
                            type="submit"
                            form={layout.formId}
                            disabled={layout.processing}
                            focusableWhenDisabled
                        >
                            {layout.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Save & continue
                            <ArrowRight data-icon="inline-end" />
                        </Button>
                    )}
                </div>

                {preview && next?.url && (
                    <Link href={next.url} className={buttonVariants()}>
                        Next
                        <ArrowRight data-icon="inline-end" />
                    </Link>
                )}
            </div>
        </footer>
    );
}

function PrimaryAction({
    action,
}: {
    action: NonNullable<OnboardingLayoutProps['primaryAction']>;
}) {
    const button = (
        <Button
            type="button"
            disabled={action.disabled}
            focusableWhenDisabled
            onClick={action.onClick}
        >
            {action.label}
        </Button>
    );

    if (!action.disabled || !action.tooltip) {
        return button;
    }

    return (
        <Tooltip>
            <TooltipTrigger render={button} />
            <TooltipContent>{action.tooltip}</TooltipContent>
        </Tooltip>
    );
}
