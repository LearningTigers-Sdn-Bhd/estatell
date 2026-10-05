import { cn } from 'cn';
import type { ReactNode } from 'react';

export default function Heading({
    title,
    description,
    variant = 'default',
    className,
    badge,
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    className?: string;
    /** Shown next to the title, for example a status badge. */
    badge?: ReactNode;
}) {
    return (
        <header
            className={cn(
                variant === 'small' ? '' : 'mb-8 space-y-0.5',
                className,
            )}
        >
            {badge ? (
                <div className="flex flex-wrap items-center gap-2">
                    <h2
                        className={
                            variant === 'small'
                                ? 'mb-0.5 text-base font-medium'
                                : 'text-xl font-semibold tracking-tight'
                        }
                    >
                        {title}
                    </h2>
                    {badge}
                </div>
            ) : (
                <h2
                    className={
                        variant === 'small'
                            ? 'mb-0.5 text-base font-medium'
                            : 'text-xl font-semibold tracking-tight'
                    }
                >
                    {title}
                </h2>
            )}
            {description && (
                <p className="text-sm text-muted-foreground">{description}</p>
            )}
        </header>
    );
}
