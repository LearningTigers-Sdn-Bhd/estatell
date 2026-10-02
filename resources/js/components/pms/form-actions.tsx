import type { ReactNode } from 'react';

/**
 * The sticky footer of a full-page form: Cancel and the primary button, right aligned. It stays at the bottom of the
 * window while the form scrolls.
 */
export function FormActions({ children }: { children: ReactNode }) {
    return (
        <div className="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center justify-end gap-2 border-t bg-background px-4 py-3">
            {children}
        </div>
    );
}
