import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { setUrlDefaults } from '@/wayfinder';

/**
 * The register has no sidebar: in Terminal Mode every back-office link answers 403, and the board needs the whole screen.
 */
export default function PosLayout({ children }: { children: ReactNode }) {
    const { property } = usePage().props;

    setUrlDefaults({ property: property?.code });

    return (
        <div className="flex min-h-svh flex-col bg-background text-foreground">
            {children}
        </div>
    );
}
