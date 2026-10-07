import { X } from 'lucide-react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

type Props = {
    /** Selected rows. The bar shows only when this is above 0. */
    count: number;
    onClear: () => void;
    /** The bulk actions, for example an export menu. */
    children: ReactNode;
};

/**
 * Floats at the bottom of an Index page while rows are selected: the count, the bulk actions and Clear.
 * Esc clears the selection unless a dialog or menu is open.
 */
export function BulkActionBar({ count, onClear, children }: Props) {
    useEffect(() => {
        if (count === 0) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (
                event.key !== 'Escape' ||
                event.defaultPrevented ||
                document.querySelector('[role="dialog"], [role="menu"]')
            ) {
                return;
            }

            onClear();
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [count, onClear]);

    if (count === 0) {
        return null;
    }

    return (
        <div className="pointer-events-none sticky bottom-4 z-20 mt-auto flex justify-center">
            <div
                role="toolbar"
                aria-label="Bulk actions"
                className="pointer-events-auto flex w-full animate-in items-center gap-2 rounded-xl border bg-popover py-2 pr-2 pl-4 text-popover-foreground shadow-lg duration-200 fade-in-0 slide-in-from-bottom-4 sm:w-auto"
            >
                <span className="mr-2 text-sm font-medium tabular-nums">
                    {count} selected
                </span>
                <div className="ml-auto flex items-center gap-2">
                    {children}
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Clear the selection"
                        onClick={onClear}
                    >
                        <X />
                    </Button>
                </div>
            </div>
        </div>
    );
}
