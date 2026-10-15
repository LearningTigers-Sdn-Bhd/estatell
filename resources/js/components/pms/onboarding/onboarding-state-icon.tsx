import { CircleCheck, Lock, TriangleAlert } from 'lucide-react';
import { cn } from 'cn';

/**
 * The state icon. It is decorative: the state word is read by screen readers next to the label.
 */
export function OnboardingStateIcon({
    state,
    number,
    className,
}: {
    state: string;
    number: number;
    /** Overrides the state colour, for an icon on a filled background. */
    className?: string;
}) {
    switch (state) {
        case 'done':
            return (
                <CircleCheck
                    aria-hidden
                    className={cn('size-4 shrink-0 text-success', className)}
                />
            );
        case 'locked':
            return (
                <Lock
                    aria-hidden
                    className={cn(
                        'size-4 shrink-0 text-muted-foreground',
                        className,
                    )}
                />
            );
        case 'needs_attention':
            return (
                <TriangleAlert
                    aria-hidden
                    className={cn('size-4 shrink-0 text-warning', className)}
                />
            );
        default:
            return (
                <span
                    aria-hidden
                    className="flex size-5 shrink-0 items-center justify-center rounded-full border border-border text-xs font-medium text-muted-foreground"
                >
                    {number}
                </span>
            );
    }
}
