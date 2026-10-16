import { Badge } from '@/components/ui/badge';
import { cn } from 'cn';

/** Soft tints only: no solid fills. Success is the brand green, warning is amber. */
const toneClasses: Record<App.Enums.Tone, string> = {
    neutral: 'bg-muted text-foreground',
    info: 'bg-info text-info-foreground',
    success: 'bg-primary/10 text-primary',
    warning: 'bg-warning/10 text-warning-text',
    danger: 'bg-destructive/10 text-foreground',
};

/** Status labels for the dashboard, in soft tints that read in both themes. */
export function DashboardBadge({
    label,
    tone,
}: {
    label: string;
    tone: App.Enums.Tone;
}) {
    return (
        <Badge variant="secondary" className={cn(toneClasses[tone])}>
            {tone === 'danger' && (
                <span
                    aria-hidden
                    className="size-1.5 rounded-full bg-destructive"
                />
            )}
            {label}
        </Badge>
    );
}
