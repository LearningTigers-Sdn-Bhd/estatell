import { router } from '@inertiajs/react';
import { ImageOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import {
    destroy as removeImage,
    store as storeImage,
} from '@/routes/settings/pos/items/image';

const MAX_SIDE = 1200;

/**
 * A smaller WebP copy of a large photo, so a phone picture does not hit the server's upload limit. A small photo, a file
 * the browser cannot read, or a copy that is not smaller goes up as it is.
 */
async function shrink(file: File): Promise<File> {
    try {
        const photo = await createImageBitmap(file);
        const scale = Math.min(
            1,
            MAX_SIDE / Math.max(photo.width, photo.height),
        );

        if (scale === 1 && file.size <= 1_000_000) {
            return file;
        }

        const canvas = document.createElement('canvas');
        canvas.width = Math.round(photo.width * scale);
        canvas.height = Math.round(photo.height * scale);
        canvas
            .getContext('2d')
            ?.drawImage(photo, 0, 0, canvas.width, canvas.height);

        const blob = await new Promise<Blob | null>((resolve) =>
            canvas.toBlob(resolve, 'image/webp', 0.85),
        );

        return blob && blob.type === 'image/webp' && blob.size < file.size
            ? new File([blob], 'photo.webp', { type: blob.type })
            : file;
    } catch {
        return file;
    }
}

/**
 * The photo of a menu item, shown on the register and the guest QR menu. A saved item uploads as soon as a file is
 * picked, apart from the item's Save button. A new item has no id yet, so its file goes with the item form (`onPick`).
 */
export function PosItemImageField({
    itemId,
    imageUrl,
    onPick,
    formError,
}: {
    itemId: number | null;
    imageUrl: string | null;
    /** New item only: keeps the picked file in the item form. */
    onPick?: (file: File | null) => void;
    formError?: string;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [uploadError, setError] = useState<string>();
    const [preview, setPreview] = useState<string | null>(null);
    const error = uploadError ?? formError;
    const shown = itemId === null ? preview : imageUrl;

    // A preview holds the file in memory until its URL is released.
    useEffect(() => {
        return () => {
            if (preview) {
                URL.revokeObjectURL(preview);
            }
        };
    }, [preview]);

    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            setError(undefined);
            setBusy(true);
        },
        onError: (errors: Record<string, string>) => setError(errors.image),
        onFinish: () => {
            setBusy(false);

            if (input.current) {
                input.current.value = '';
            }
        },
    };

    const pick = async (picked: File) => {
        const file = await shrink(picked);

        if (itemId === null) {
            setPreview(URL.createObjectURL(file));
            onPick?.(file);

            return;
        }

        router.post(
            storeImage.url({ item: itemId }),
            { image: file },
            { ...options, forceFormData: true },
        );
    };

    const remove = () => {
        if (itemId === null) {
            setPreview(null);
            onPick?.(null);

            if (input.current) {
                input.current.value = '';
            }

            return;
        }

        router.delete(removeImage.url({ item: itemId }), options);
    };

    return (
        <Field data-invalid={!!error}>
            <FieldLabel htmlFor="item-image">Photo</FieldLabel>
            <div className="flex items-center gap-3">
                <div className="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted/40">
                    {shown ? (
                        <img
                            src={shown}
                            alt="Item photo"
                            className="size-full object-cover"
                        />
                    ) : (
                        <ImageOff
                            aria-hidden
                            className="size-5 text-muted-foreground"
                        />
                    )}
                </div>
                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            focusableWhenDisabled
                            onClick={() => input.current?.click()}
                        >
                            {busy && <Spinner data-icon="inline-start" />}
                            {shown ? 'Replace photo' : 'Upload photo'}
                        </Button>
                        {shown && (
                            <Button
                                type="button"
                                variant="ghost"
                                disabled={busy}
                                focusableWhenDisabled
                                onClick={remove}
                            >
                                Remove
                            </Button>
                        )}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        PNG, JPEG or WebP. Large photos are shrunk. Cropped to a
                        square.
                    </p>
                </div>
            </div>
            <input
                ref={input}
                id="item-image"
                type="file"
                accept="image/png,image/jpeg,image/webp"
                className="sr-only"
                tabIndex={-1}
                onChange={(event) => {
                    const file = event.target.files?.[0];

                    if (file) {
                        void pick(file);
                    }
                }}
            />
            <FieldError>{error}</FieldError>
        </Field>
    );
}
