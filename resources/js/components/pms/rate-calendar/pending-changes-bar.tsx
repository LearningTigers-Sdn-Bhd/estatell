import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

/**
 * Floats at the bottom of the calendar while edits are staged: the count, the server's errors, Discard and Save changes.
 */
export function PendingChangesBar({
    count,
    errors,
    processing,
    onSave,
    onDiscard,
}: {
    count: number;
    errors: { label: string | null; message: string }[];
    processing: boolean;
    onSave: () => void;
    onDiscard: () => void;
}) {
    if (count === 0) {
        return null;
    }

    return (
        <div className="pointer-events-none absolute inset-x-4 bottom-4 z-20 flex justify-center">
            <div
                role="region"
                aria-label="Unsaved rate changes"
                aria-busy={processing}
                className="pointer-events-auto flex w-full animate-in flex-col gap-2 rounded-xl border bg-popover py-2 pr-2 pl-4 text-popover-foreground shadow-lg duration-200 fade-in-0 slide-in-from-bottom-4 sm:w-auto sm:max-w-2xl"
            >
                {errors.length > 0 && (
                    <ul
                        role="alert"
                        className="flex flex-col gap-1 pt-1 text-sm text-destructive"
                    >
                        {errors.map(({ label, message }) => (
                            <li key={`${label}-${message}`}>
                                {label && (
                                    <span className="font-medium">
                                        {label}:{' '}
                                    </span>
                                )}
                                {message}
                            </li>
                        ))}
                    </ul>
                )}
                <div className="flex items-center gap-2">
                    <span className="mr-2 text-sm font-medium tabular-nums">
                        {count} unsaved {count === 1 ? 'change' : 'changes'}
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
                        <Button
                            type="button"
                            onClick={onSave}
                            disabled={processing}
                            focusableWhenDisabled
                        >
                            {processing && <Spinner data-icon="inline-start" />}
                            Save changes
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
