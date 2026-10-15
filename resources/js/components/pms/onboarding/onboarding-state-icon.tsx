import { CircleCheck, Lock, TriangleAlert } from 'lucide-react';

/**
 * The state icon. It is decorative: the state word is read by screen readers next to the label.
 */
export function OnboardingStateIcon({
    state,
    number,
}: {
    state: string;
    number: number;
}) {
    switch (state) {
        case 'done':
            return (
                <CircleCheck
                    aria-hidden
                    className="size-4 shrink-0 text-success"
                />
            );
        case 'locked':
            return (
                <Lock
                    aria-hidden
                    className="size-4 shrink-0 text-muted-foreground"
                />
            );
        case 'needs_attention':
            return (
                <TriangleAlert
                    aria-hidden
                    className="size-4 shrink-0 text-warning"
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
