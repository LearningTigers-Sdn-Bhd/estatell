import { router } from '@inertiajs/react';
import { ImagePlus, ZoomIn, ZoomOut } from 'lucide-react';
import { useState } from 'react';
import Cropper from 'react-easy-crop';
import type { Area } from 'react-easy-crop';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FieldError } from '@/components/ui/field';
import { Slider } from '@/components/ui/slider';
import { Spinner } from '@/components/ui/spinner';
import { cropImage } from '@/lib/crop-image';
import { store } from '@/routes/settings/logo';

/** An image to crop: an object URL and its file type. */
export type LogoSource = { url: string; type: 'image/png' | 'image/jpeg' };

const MIN_ZOOM = 1;
const MAX_ZOOM = 4;

/**
 * Crops a logo to a square and uploads it. The image always fills the square: it cannot zoom out or move past its
 * edges.
 */
export function LogoCropDialog({
    source,
    open,
    onOpenChange,
    onChooseAnother,
}: {
    source: LogoSource | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onChooseAnother?: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Crop logo</DialogTitle>
                    <DialogDescription>
                        Drag to move. Zoom to fit.
                    </DialogDescription>
                </DialogHeader>
                {source && (
                    <LogoCropper
                        key={source.url}
                        source={source}
                        onChooseAnother={onChooseAnother}
                        onCancel={() => onOpenChange(false)}
                        onSaved={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function LogoCropper({
    source,
    onChooseAnother,
    onCancel,
    onSaved,
}: {
    source: LogoSource;
    onChooseAnother?: () => void;
    onCancel: () => void;
    onSaved: () => void;
}) {
    const [crop, setCrop] = useState({ x: 0, y: 0 });
    const [zoom, setZoom] = useState(1);
    const [area, setArea] = useState<Area>();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string>();

    const save = async () => {
        if (!area) {
            return;
        }

        setError(undefined);
        setSaving(true);

        try {
            const blob = await cropImage(source.url, area, source.type);
            const extension = source.type === 'image/png' ? 'png' : 'jpg';

            router.post(
                store.url(),
                {
                    logo: new File([blob], `logo.${extension}`, {
                        type: source.type,
                    }),
                },
                {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: onSaved,
                    onError: (errors) => setError(errors.logo),
                    onFinish: () => setSaving(false),
                },
            );
        } catch (exception) {
            setError(
                exception instanceof Error
                    ? exception.message
                    : 'The image could not be cropped.',
            );
            setSaving(false);
        }
    };

    return (
        <>
            <div className="relative h-72 overflow-hidden rounded-lg bg-muted">
                <Cropper
                    image={source.url}
                    crop={crop}
                    zoom={zoom}
                    minZoom={MIN_ZOOM}
                    maxZoom={MAX_ZOOM}
                    aspect={1}
                    classes={{ cropAreaClassName: 'rounded-2xl' }}
                    cropperProps={{ 'aria-label': 'Crop area' }}
                    onCropChange={setCrop}
                    onZoomChange={setZoom}
                    onCropComplete={(_, pixels) => setArea(pixels)}
                />
            </div>
            <div className="flex items-center gap-3">
                <ZoomOut className="size-4 shrink-0 text-muted-foreground" />
                <Slider
                    aria-label="Zoom"
                    min={MIN_ZOOM}
                    max={MAX_ZOOM}
                    step={0.01}
                    value={[zoom]}
                    onValueChange={(value) =>
                        setZoom(Array.isArray(value) ? value[0] : value)
                    }
                />
                <ZoomIn className="size-4 shrink-0 text-muted-foreground" />
            </div>
            <FieldError>{error}</FieldError>
            <DialogFooter>
                {onChooseAnother && (
                    <Button
                        type="button"
                        variant="outline"
                        className="sm:mr-auto"
                        onClick={onChooseAnother}
                    >
                        <ImagePlus data-icon="inline-start" />
                        Choose another image
                    </Button>
                )}
                <Button type="button" variant="outline" onClick={onCancel}>
                    Cancel
                </Button>
                <Button
                    type="button"
                    disabled={saving || !area}
                    focusableWhenDisabled
                    onClick={save}
                >
                    {saving && <Spinner data-icon="inline-start" />}
                    Save
                </Button>
            </DialogFooter>
        </>
    );
}
