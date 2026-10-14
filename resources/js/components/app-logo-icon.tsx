import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export default function AppLogoIcon({
    className,
    compact = false,
    ...props
}: HTMLAttributes<HTMLSpanElement> & { compact?: boolean }) {
    return (
        <span
            role="img"
            aria-label="Estatell"
            className={cn(
                'inline-block shrink-0 font-wordmark text-2xl leading-none font-black tracking-wordmark whitespace-nowrap text-primary',
                className,
            )}
            {...props}
        >
            {compact ? 'E' : 'Estatell'}
        </span>
    );
}
