import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * A section heading with its description and actions on the right. It sits in a Workspace panel, so only the heading
 * gets the normal inset. With an icon, the icon box sets the heading apart, so it takes no inset.
 */
export function SectionHeader({
    title,
    description,
    icon: Icon,
    actions,
}: {
    title: string;
    description: string;
    icon?: LucideIcon;
    actions?: ReactNode;
}) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-start justify-between gap-3 pt-2',
                !Icon && 'ps-3',
            )}
        >
            <div className="flex min-w-0 items-start gap-3">
                {Icon && (
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                        <Icon aria-hidden className="size-4" />
                    </span>
                )}
                <div className="flex min-w-0 flex-col">
                    <h2 className="text-base font-medium">{title}</h2>
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
    );
}
