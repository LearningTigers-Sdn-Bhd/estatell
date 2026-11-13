import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

/**
 * The bare layout for screens a guest holds: the property name and nothing else.
 * No sidebar, no breadcrumbs and no staff actions.
 * Concierge pages share their editorial identity and own navigation. Other guest screens keep the property header.
 */
export default function GuestLayout({ children }: { children: ReactNode }) {
    const { component, props } = usePage<{
        property: App.Data.Property.PropertySummaryData | null;
    }>();
    const { property } = props;
    const isConcierge = component.startsWith('concierge/');

    return (
        <div
            className={`flex h-svh flex-col overflow-hidden bg-background ${isConcierge ? 'concierge-editorial concierge-editorial-shell' : ''}`}
        >
            {!isConcierge && (
                <header className="flex h-14 shrink-0 items-center border-b px-4">
                    <p className="font-heading text-sm font-semibold">
                        {property?.name}
                    </p>
                </header>
            )}
            <main className="flex min-h-0 flex-1 flex-col">{children}</main>
        </div>
    );
}
