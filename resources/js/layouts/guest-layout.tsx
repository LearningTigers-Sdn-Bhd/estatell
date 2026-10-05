import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

/**
 * The bare layout for screens a guest holds: the property name and nothing else.
 * No sidebar, no breadcrumbs and no staff actions.
 * The header has a fixed height and the page fills the rest of the screen. A page scrolls its own body.
 */
export default function GuestLayout({ children }: { children: ReactNode }) {
    const { property } = usePage<{
        property: App.Data.Property.PropertySummaryData | null;
    }>().props;

    return (
        <div className="flex h-svh flex-col overflow-hidden bg-background">
            <header className="flex h-14 shrink-0 items-center border-b px-4">
                <p className="font-heading text-sm font-semibold">
                    {property?.name}
                </p>
            </header>
            <main className="flex min-h-0 flex-1 flex-col">{children}</main>
        </div>
    );
}
