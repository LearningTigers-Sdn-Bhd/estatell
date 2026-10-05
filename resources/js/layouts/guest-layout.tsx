import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

/**
 * The bare layout for screens a guest holds: the property name and nothing else.
 * No sidebar, no breadcrumbs and no staff actions.
 */
export default function GuestLayout({ children }: { children: ReactNode }) {
    const { property } = usePage<{
        property: App.Data.Property.PropertySummaryData | null;
    }>().props;

    return (
        <div className="flex min-h-svh flex-col bg-background">
            <header className="border-b px-4 py-3">
                <p className="font-heading text-sm font-semibold">
                    {property?.name}
                </p>
            </header>
            <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4">
                {children}
            </main>
        </div>
    );
}
