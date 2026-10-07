import { Spinner } from '@/components/ui/spinner';

/**
 * The status line of a form that saves by itself (see `useAutoSave`).
 */
export function AutoSaveStatus({ saving }: { saving: boolean }) {
    return (
        <p
            role="status"
            className="flex items-center gap-2 text-sm text-muted-foreground"
        >
            {saving ? (
                <>
                    <Spinner />
                    Saving…
                </>
            ) : (
                'Changes save automatically.'
            )}
        </p>
    );
}
