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
    className,
    onNavigate,
}: {
    step: OnboardingStep;
    current?: boolean;
    className?: string;
    onNavigate?: () => void;
}) {
    const locked = step.state === 'locked';
    const content = (
        <>
            <OnboardingStateIcon state={step.state} number={step.number} />
            <span className="min-w-0 truncate">{step.label}</span>
            <span className="sr-only">
                {stateWord[step.state]}
                {locked ? ', preview' : ''}
            </span>
        </>
    );
    const classes = cn(
        'flex min-w-0 items-center gap-2 rounded-md px-2 py-1.5 text-sm outline-hidden focus-visible:ring-2 focus-visible:ring-ring',
        current
            ? 'bg-accent font-medium text-accent-foreground'
            : 'text-foreground hover:bg-accent/60',
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
