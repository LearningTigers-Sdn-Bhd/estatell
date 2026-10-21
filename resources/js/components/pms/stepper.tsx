import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * A row of numbered circles joined by lines. Steps before `current` are done (ticked, line filled), the current one is
 * outlined with the primary colour. Below `sm` only the current label shows.
 */
export function Stepper({
    steps,
    current,
    label,
}: {
    steps: string[];
    /** Zero-based index of the open step. */
    current: number;
    label: string;
}) {
    return (
        <nav aria-label={label}>
            <ol className="flex items-center gap-2">
                {steps.map((step, index) => {
                    const done = index < current;
                    const active = index === current;

                    return (
                        <li
                            key={step}
                            aria-current={active ? 'step' : undefined}
                            className="flex items-center gap-2"
                        >
                            <span
                                className={cn(
                                    'flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-medium tabular-nums',
                                    done &&
                                        'border-primary bg-primary text-primary-foreground',
                                    active && 'border-primary text-primary',
                                    !done &&
                                        !active &&
                                        'border-border text-muted-foreground',
                                )}
                            >
                                {done ? (
                                    <Check aria-hidden className="size-3.5" />
                                ) : (
                                    index + 1
                                )}
                            </span>
                            <span
                                className={cn(
                                    'text-sm',
                                    active
                                        ? 'font-medium text-foreground'
                                        : 'text-muted-foreground max-sm:sr-only',
                                )}
                            >
                                {step}
                                {done && (
                                    <span className="sr-only"> (done)</span>
                                )}
                            </span>
                            {index < steps.length - 1 && (
                                <span
                                    aria-hidden
                                    className={cn(
                                        'h-px w-6 shrink-0 sm:w-10',
                                        done ? 'bg-primary' : 'bg-border',
                                    )}
                                />
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
