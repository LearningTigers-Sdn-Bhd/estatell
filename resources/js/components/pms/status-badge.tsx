import type { LucideIcon } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const toneClasses: Record<App.Enums.Tone, string> = {
    neutral: 'bg-muted text-muted-foreground',
    info: 'bg-info/10 text-info',
    success: 'bg-success/10 text-success',
    warning: 'bg-warning/10 text-warning',
    danger: 'bg-destructive/10 text-destructive',
};

/**
 * An enum status with its server label and tone. The label always carries the meaning, never the color alone.
 */
export function StatusBadge({
    status,
    icon: Icon,
    dot = true,
    size,
}: {
    status: App.Data.Shared.BadgeData;
    /** Replaces the dot when the badge needs an icon as well as a tone. */
    icon?: LucideIcon;
    /** Set to false for a badge with tone only. */
    dot?: boolean;
    size?: 'default' | 'lg';
}) {
    return (
        <Badge
            variant="secondary"
            size={size}
            className={cn(toneClasses[status.tone])}
        >
            {Icon && <Icon aria-hidden />}
            {!Icon && dot && (
                <span
                    aria-hidden
                    className="size-1.5 rounded-full bg-current"
                />
            )}
            {status.label}
        </Badge>
    );
}
