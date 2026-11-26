import { router } from '@inertiajs/react';
import { holdFlashToasts, toastProgress } from '@/lib/toast-progress';
import type { ToastProgress } from '@/lib/toast-progress';
import type { FlashToast } from '@/types/ui';

export type UploadWithToastOptions = {
    url: string;
    files: File[];
    /** The request field. Sent as an array when `asArray` is on (`photos[]`), otherwise as one file (`image`). */
    field: string;
    asArray?: boolean;
    /** One request per file, one after another. Keeps each request small and saves the files before a bad one. */
    oneByOne?: boolean;
    /** What the files are, for the toast: `['photo', 'photos']`. */
    noun: [string, string];
};

export type UploadResult = { saved: number; total: number; error?: string };

type RequestResult = { ok: boolean; error?: string; toast?: FlashToast };

const FAILED = 'Upload failed. Check your connection and try again.';

function send(
    url: string,
    data: Record<string, File | File[]>,
    onPercent: (percent: number) => void,
): Promise<RequestResult> {
    return new Promise((resolve) => {
        let succeeded = false;
        let error: string | undefined;
        let flashToast: FlashToast | undefined;

        router.post(url, data, {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onProgress: (progress) => {
                if (progress?.percentage !== undefined) {
                    onPercent(progress.percentage);
                }
            },
            onFlash: (flash) => {
                flashToast = (flash as { toast?: FlashToast }).toast;
            },
            onSuccess: () => {
                succeeded = true;
            },
            onError: (errors) => {
                error = Object.values(errors)[0];
            },
            onCancel: () => resolve({ ok: false, error: FAILED }),
            onFinish: () => {
                const refused =
                    flashToast?.type === 'error'
                        ? flashToast.message
                        : undefined;

                resolve(
                    succeeded && !refused
                        ? { ok: true, toast: flashToast }
                        : { ok: false, error: error ?? refused ?? FAILED },
                );
            },
        });
    });
}

function count(n: number, [one, many]: [string, string]): string {
    return `${n} ${n === 1 ? one : many}`;
}

/**
 * Uploads files with one progress toast: "Uploading 3 of 8 · 45%", then "Processing…", then the result. Server flash
 * toasts are held while it runs, so the user sees one toast, not one per request. Stops at the first refused file.
 */
export async function uploadWithToast({
    url,
    files,
    field,
    asArray = false,
    oneByOne = false,
    noun,
}: UploadWithToastOptions): Promise<UploadResult> {
    const total = files.length;
    const batches = oneByOne ? files.map((file) => [file]) : [files];
    const progress = toastProgress(`Uploading ${count(total, noun)}`);
    const release = holdFlashToasts();
    let saved = 0;
    let lastToast: FlashToast | undefined;

    try {
        for (const batch of batches) {
            const step =
                total > 1 && oneByOne ? `${saved + 1} of ${total} · ` : '';

            // The bar covers the whole upload; the text shows the current file.
            const overall = (percent: number) =>
                ((saved + (percent / 100) * batch.length) / total) * 100;

            progress.progress(`${step}0%`, overall(0));

            const result = await send(
                url,
                { [field]: asArray ? batch : batch[0] },
                (percent) => {
                    progress.progress(
                        percent >= 100
                            ? `${step}Processing…`
                            : `${step}${Math.round(percent)}%`,
                        overall(percent),
                    );
                },
            );

            if (!result.ok) {
                progress.fail(
                    result.error ?? FAILED,
                    saved > 0
                        ? `${saved} of ${total} ${noun[1]} were uploaded.`
                        : undefined,
                );

                return { saved, total, error: result.error };
            }

            saved += batch.length;
            lastToast = result.toast;
        }

        progress.succeed(
            lastToast?.message ?? `${count(total, noun)} uploaded.`,
        );

        return { saved, total };
    } finally {
        release();
    }
}

/** Smaller uploads finish before a toast would be worth reading. */
export const UPLOAD_TOAST_MIN_BYTES = 512 * 1024;

export type UploadToastCallbacks = {
    onProgress: (
        progress: { percentage?: number; total?: number } | undefined,
    ) => void;
    onFinish: () => void;
    onCancel: () => void;
};

/**
 * Visit callbacks that show a progress toast for a form that sends files with its other fields. Merge them into the
 * form's own options. The toast opens only for uploads of 512 KB or more, and closes when the request ends, so the
 * server's flash toast shows the result. Reusable: create it once per form.
 */
export function uploadToastCallbacks(title: string): UploadToastCallbacks {
    let progress: ToastProgress | null = null;

    const end = () => {
        progress?.close();
        progress = null;
    };

    return {
        onProgress: (event) => {
            const percent = event?.percentage;

            if (percent === undefined) {
                return;
            }

            if (progress === null) {
                if (
                    (event?.total ?? 0) < UPLOAD_TOAST_MIN_BYTES ||
                    percent >= 100
                ) {
                    return;
                }

                progress = toastProgress(title);
            }

            if (percent >= 100) {
                progress.processing('Processing…');
            } else {
                progress.progress(`${Math.round(percent)}%`, percent);
            }
        },
        onFinish: end,
        onCancel: end,
    };
}
