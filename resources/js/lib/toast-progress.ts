import { toast } from '@/components/ui/toast';

/** Custom toast data. `progress` (0–100) draws a bar under the text. */
export type ToastProgressData = { progress?: number };

let heldFlashToasts = 0;

/**
 * Stops server flash toasts from showing until the returned function runs. A long task that sends many requests holds
 * them, so the user sees its one progress toast instead of one toast per request.
 */
export function holdFlashToasts(): () => void {
    heldFlashToasts++;
    let released = false;

    return () => {
        if (!released) {
            released = true;
            heldFlashToasts--;
        }
    };
}

export function flashToastsHeld(): boolean {
    return heldFlashToasts > 0;
}

export type ToastProgress = {
    progress: (description: string, percent: number) => void;
    processing: (description: string) => void;
    succeed: (title: string, description?: string) => void;
    fail: (title: string, description?: string) => void;
    close: () => void;
};

/**
 * One toast that follows a slow task: a spinner and a bar while it runs, then a success or error toast. It stays open
 * until the task ends.
 */
export function toastProgress(title: string): ToastProgress {
    const id = toast.add<ToastProgressData>({
        title,
        type: 'loading',
        timeout: 0,
        data: { progress: 0 },
    });

    const finish = (
        type: 'success' | 'error',
        resultTitle: string,
        description?: string,
    ) => {
        toast.close(id);
        toast.add({
            title: resultTitle,
            description,
            type,
            // A reason under the headline takes longer to read.
            timeout: description ? 15000 : undefined,
        });
    };

    return {
        progress: (description, percent) =>
            toast.update<ToastProgressData>(id, {
                description,
                data: { progress: Math.max(0, Math.min(100, percent)) },
            }),
        processing: (description) =>
            toast.update<ToastProgressData>(id, {
                description,
                data: { progress: 100 },
            }),
        succeed: (resultTitle, description) =>
            finish('success', resultTitle, description),
        fail: (resultTitle, description) =>
            finish('error', resultTitle, description),
        close: () => toast.close(id),
    };
}
