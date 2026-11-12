import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * A section heading inside a Workspace panel: an icon box, the title and an optional description, and actions on the
 * right. The icon box sets it apart; `ps-2 pt-2` lines the box up with the text of the tables below it.
 */
export function PanelHeading({
    icon: Icon,
    title,
    description,
    actions,
}: {
    icon: LucideIcon;
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-3 ps-2 pt-2">
            <div className="flex min-w-0 items-start gap-3">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <Icon aria-hidden className="size-4" />
                </span>
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h2 className="flex items-center gap-2 text-base font-medium">
                        {title}
                    </h2>
                    {description && (
                        <div className="text-sm text-muted-foreground">
                            {description}
                        </div>
                    )}
                </div>
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
    );
}
