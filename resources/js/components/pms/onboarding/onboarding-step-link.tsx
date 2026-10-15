import { Link } from '@inertiajs/react';
import { OnboardingStateIcon } from '@/components/pms/onboarding/onboarding-state-icon';
import {
    type OnboardingStep,
    stateWord,
} from '@/components/pms/onboarding/onboarding-steps';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from 'cn';

/**
 * A step in the stepper or the sections sheet. A locked step is a link too: it opens as a preview, with a tooltip that
 * names the step it waits for. A step without a page is shown as text.
 */
export function OnboardingStepLink({
    step,
    current = false,
    variant = 'pill',
    first = false,
    className,
    onNavigate,
}: {
    step: OnboardingStep;
    current?: boolean;
    variant?: 'pill' | 'segment' | 'tab';
    first?: boolean;
    className?: string;
    onNavigate?: () => void;
}) {
    const locked = step.state === 'locked';
    const content = (
        <>
            {variant !== 'pill' ? (
                step.state !== 'not_started' &&
                step.state !== 'in_progress' && (
                    <OnboardingStateIcon
                        state={step.state}
                        number={step.number}
                        className={
                            variant === 'segment' && current
                                ? 'text-primary-foreground'
                                : undefined
                        }
                    />
                )
            ) : (
                <OnboardingStateIcon state={step.state} number={step.number} />
            )}
            <span className="min-w-0 truncate">
                {variant === 'tab' && `${step.number}. `}
                {step.label}
            </span>
            <span className="sr-only">
                {stateWord[step.state]}
                {locked ? ', preview' : ''}
            </span>
        </>
    );
    const classes = cn(
        'flex min-w-0 items-center gap-2 text-sm outline-hidden focus-visible:ring-2 focus-visible:ring-ring',
        variant === 'pill' &&
            cn(
                'rounded-md px-2 py-1.5',
                current
                    ? 'bg-accent font-medium text-accent-foreground'
                    : 'text-foreground hover:bg-accent/60',
            ),
        variant === 'segment' &&
            cn(
                'h-10 w-full justify-center px-5 font-medium focus-visible:ring-inset',
                first ? 'chevron-first' : 'chevron',
                current
                    ? 'bg-primary text-primary-foreground'
                    : step.state === 'done'
                      ? 'bg-primary/10 text-foreground hover:bg-primary/20'
                      : 'bg-muted text-muted-foreground hover:bg-muted/70',
            ),
        variant === 'tab' &&
            cn(
                'h-11 border-b-2 px-4 focus-visible:ring-inset',
                current
                    ? 'border-primary font-medium text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground',
            ),
        className,
    );

    if (step.url === null) {
        return (
            <span className={cn(classes, 'text-muted-foreground')}>
                {content}
            </span>
        );
    }

    const link = (
        <Link
            href={step.url}
            className={classes}
            aria-current={current ? 'step' : undefined}
            onClick={onNavigate}
        >
            {content}
        </Link>
    );

    if (!locked) {
        return link;
    }

    return (
        <Tooltip>
            <TooltipTrigger render={link}>{content}</TooltipTrigger>
            <TooltipContent>
                Locked until {step.blockedBy ?? 'the earlier sections'} is done.
                Open to preview.
            </TooltipContent>
        </Tooltip>
    );
}
