import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The footer of a full-page form: Cancel and the primary button, right aligned. By default it sticks to the bottom of
 * the window while the form scrolls. With `fixed` it is a plain footer row for a page that uses the `fixed` layout
 * variant, below the scroll region, so it is always visible.
 */
export function FormActions({
    children,
    fixed = false,
}: {
    children: ReactNode;
    fixed?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-center justify-end gap-2 border-t bg-background px-4 py-3',
                fixed ? 'shrink-0' : 'sticky bottom-0 z-10 -mx-4',
            )}
        >
            {children}
        </div>
    );
}
