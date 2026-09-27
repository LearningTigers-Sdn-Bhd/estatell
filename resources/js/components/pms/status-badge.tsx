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
export function StatusBadge({ status }: { status: App.Data.Shared.BadgeData }) {
    return (
        <Badge variant="ghost" className={cn(toneClasses[status.tone])}>
            <span aria-hidden className="size-1.5 rounded-full bg-current" />
            {status.label}
        </Badge>
    );
}
