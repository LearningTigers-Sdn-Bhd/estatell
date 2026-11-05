import type { ReactNode } from 'react';

/** A section heading with its description and actions on the right, as on the other Booking website tabs. */
export function SectionHeader({
    title,
    description,
    actions,
}: {
    title: string;
    description: string;
    actions?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 className="text-base font-medium">{title}</h2>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
    );
}
