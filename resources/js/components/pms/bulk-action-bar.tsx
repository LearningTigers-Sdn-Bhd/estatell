import { X } from 'lucide-react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

type Props = {
    /** Selected rows. The bar shows only when this is above 0. */
    count: number;
    onClear: () => void;
    /** The bulk actions, for example an export menu. */
    children: ReactNode;
    stackOnMobile?: boolean;
    disabled?: boolean;
    /** The text before the actions. Defaults to "N selected". */
    label?: string;
    /** Esc clears the selection. Turn it off where Esc must not drop work, for example painted rooms. */
    clearOnEscape?: boolean;
    /** The Clear button. Turn it off when the actions already include a way to discard. */
    showClear?: boolean;
};

/**
 * Floats at the bottom of an Index page while rows are selected: the count, the bulk actions and Clear.
 * Esc clears the selection unless a dialog or menu is open.
 */
export function BulkActionBar({
    count,
    onClear,
    children,
    stackOnMobile = false,
    disabled = false,
    label,
    clearOnEscape = true,
    showClear = true,
}: Props) {
    useEffect(() => {
        if (count === 0 || !clearOnEscape) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (
                disabled ||
                event.key !== 'Escape' ||
                event.defaultPrevented ||
                document.querySelector(
                    '[role="dialog"], [role="alertdialog"], [role="menu"]',
                )
            ) {
                return;
            }

            onClear();
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [count, onClear, disabled, clearOnEscape]);

    if (count === 0) {
        return null;
    }

    return (
        <div className="pointer-events-none sticky bottom-4 z-20 mt-auto flex justify-center">
            <div
                role="toolbar"
                aria-label="Bulk actions"
                aria-busy={disabled}
                className={cn(
                    'pointer-events-auto flex w-full animate-in items-center gap-2 rounded-xl border bg-popover py-2 pr-2 pl-4 text-popover-foreground shadow-lg duration-200 fade-in-0 slide-in-from-bottom-4 sm:w-auto',
                    stackOnMobile && 'flex-wrap sm:flex-nowrap',
                )}
            >
                <span
                    className={cn(
                        'mr-2 flex items-center gap-2 text-sm font-medium tabular-nums',
                        stackOnMobile && 'flex-1 sm:flex-none',
                    )}
                >
                    {disabled && <Spinner />}
                    {label ?? `${count} selected`}
                </span>
                <div
                    className={cn(
                        'ml-auto flex items-center gap-2',
                        stackOnMobile &&
                            'order-3 w-full flex-wrap justify-end sm:order-none sm:w-auto sm:flex-nowrap',
                    )}
                >
                    {children}
                </div>
                {showClear && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Clear the selection"
                        onClick={onClear}
                        disabled={disabled}
                        focusableWhenDisabled
                        className={
                            stackOnMobile ? 'order-2 sm:order-none' : undefined
                        }
                    >
                        <X />
                    </Button>
                )}
            </div>
        </div>
    );
}
