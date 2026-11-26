import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

/**
 * Discard and Save in a bar that floats at the bottom of the window. Render it inside the form, only while the form
 * has unsaved changes. `saveButton` is the form's submit button.
 */
export function SaveBar({
    name,
    processing,
    onDiscard,
    saveButton,
}: {
    /** What the changes belong to, for the bar's accessible name. */
    name: string;
    processing: boolean;
    onDiscard: () => void;
    saveButton: ReactNode;
}) {
    return (
        <div className="pointer-events-none fixed inset-x-4 bottom-4 z-50 flex justify-center">
            <div
                role="region"
                aria-label={`Unsaved changes to ${name}`}
                aria-busy={processing}
                className="pointer-events-auto flex w-full animate-in items-center gap-2 rounded-xl border bg-popover py-2 pr-2 pl-4 text-popover-foreground shadow-lg duration-200 fade-in-0 slide-in-from-bottom-4 sm:w-auto"
            >
                <span className="mr-2 text-sm font-medium">
                    Unsaved changes
                </span>
                <div className="ml-auto flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onDiscard}
                        disabled={processing}
                        focusableWhenDisabled
                    >
                        Discard
                    </Button>
                    {saveButton}
                </div>
            </div>
        </div>
    );
}
