import { Link, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { photos as albumPage } from '@/routes/settings';
import { copy } from '@/routes/settings/booking-website/design/images';

/**
 * Pick an album photo for one image slot. The server copies it, so the slot keeps its own image.
 */
export function AlbumPhotoDialog({
    open,
    onOpenChange,
    slot,
    photos,
    canManageAlbum,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    slot: App.Data.BookingEngine.SiteImageSlotData;
    photos: App.Data.Property.PropertyPhotoData[];
    canManageAlbum: boolean;
}) {
    const [selected, setSelected] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);

    const close = (next: boolean) => {
        if (!next) {
            setSelected(null);
        }

        onOpenChange(next);
    };

    const save = () => {
        if (selected === null) {
            return;
        }

        router.post(
            copy.url({ slot: slot.key }),
            { photo: selected },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onSuccess: () => close(false),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Choose the {slot.label}</DialogTitle>
                    <DialogDescription>
                        {photos.length === 0
                            ? 'Your property album has no photos yet.'
                            : 'The photo is copied to your website. Later album changes will not change it.'}
                    </DialogDescription>
                </DialogHeader>
                {photos.length > 0 && (
                    <ul className="grid max-h-96 grid-cols-2 gap-3 overflow-y-auto md:grid-cols-3">
                        {photos.map((photo) => (
                            <li key={photo.id}>
                                <button
                                    type="button"
                                    aria-pressed={selected === photo.id}
                                    aria-label={`Choose ${photo.name}`}
                                    className={`relative block aspect-4/3 w-full overflow-hidden rounded-md border bg-muted outline-none focus-visible:ring-2 focus-visible:ring-ring ${
                                        selected === photo.id
                                            ? 'ring-2 ring-primary'
                                            : ''
                                    }`}
                                    onClick={() => setSelected(photo.id)}
                                >
                                    <img
                                        src={photo.thumbUrl}
                                        alt={photo.name}
                                        className="size-full object-cover"
                                    />
                                    {selected === photo.id && (
                                        <span className="absolute top-2 right-2 flex size-6 items-center justify-center rounded-full bg-primary text-primary-foreground">
                                            <Check
                                                aria-hidden
                                                className="size-4"
                                            />
                                        </span>
                                    )}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => close(false)}
                    >
                        Cancel
                    </Button>
                    {photos.length > 0 ? (
                        <Button
                            type="button"
                            disabled={selected === null || saving}
                            focusableWhenDisabled
                            onClick={save}
                        >
                            {saving && <Spinner data-icon="inline-start" />}
                            Use photo
                        </Button>
                    ) : (
                        canManageAlbum && (
                            <Link
                                href={albumPage()}
                                className={buttonVariants()}
                            >
                                Go to Photos
                            </Link>
                        )
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
