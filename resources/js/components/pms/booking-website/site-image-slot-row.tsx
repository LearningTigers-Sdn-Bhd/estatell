import { router } from '@inertiajs/react';
import { ImageIcon, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import {
    destroy,
    store,
} from '@/routes/settings/booking-website/design/images';

type Slot = App.Data.BookingEngine.SiteImageSlotData;

/**
 * One image slot of the chosen template. The file uploads as soon as it is picked, and Remove empties the slot at once.
 */
export function SiteImageSlotRow({ slot }: { slot: Slot }) {
    const fileInput = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string>();

    const upload = (file: File) => {
        setError(undefined);
        router.post(
            store.url({ slot: slot.key }),
            { image: file },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onStart: () => setBusy(true),
                onError: (errors) =>
                    setError(Object.values(errors)[0] ?? 'Upload failed.'),
                onFinish: () => {
                    setBusy(false);

                    if (fileInput.current) {
                        fileInput.current.value = '';
                    }
                },
            },
        );
    };

    const remove = () => {
        setError(undefined);
        router.delete(destroy.url({ slot: slot.key }), {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <li className="flex flex-col gap-2 rounded-lg border p-3">
            <div className="flex items-center gap-3">
                <div className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted">
                    {slot.thumbUrl ? (
                        <img
                            src={slot.thumbUrl}
                            alt={slot.fileName ?? slot.label}
                            className="size-full object-cover"
                        />
                    ) : (
                        <ImageIcon
                            aria-hidden
                            className="size-5 text-muted-foreground"
                        />
                    )}
                </div>
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <span className="flex items-center gap-2 text-sm font-medium">
                        {slot.label}
                        {slot.required && (
                            <Badge variant="secondary">Required</Badge>
                        )}
                    </span>
                    <span className="truncate text-xs text-muted-foreground">
                        {slot.fileName ?? 'Empty'}
                    </span>
                </div>
                <input
                    ref={fileInput}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="sr-only"
                    tabIndex={-1}
                    aria-label={`${slot.label} file`}
                    onChange={(event) => {
                        const file = event.target.files?.[0];

                        if (file) {
                            upload(file);
                        }
                    }}
                />
                <div className="flex items-center gap-1">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={busy}
                        focusableWhenDisabled
                        onClick={() => fileInput.current?.click()}
                    >
                        {busy ? (
                            <Spinner data-icon="inline-start" />
                        ) : (
                            <Upload data-icon="inline-start" />
                        )}
                        {slot.url ? 'Replace' : 'Upload'}
                    </Button>
                    {slot.url && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            aria-label={`Remove ${slot.label}`}
                            disabled={busy}
                            focusableWhenDisabled
                            onClick={remove}
                        >
                            <Trash2 />
                        </Button>
                    )}
                </div>
            </div>
            <FieldError>{error}</FieldError>
        </li>
    );
}
