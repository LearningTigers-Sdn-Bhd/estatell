import {
    closestCenter,
    DndContext,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { DragEndEvent } from '@dnd-kit/core';
import {
    arrayMove,
    rectSortingStrategy,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
} from '@dnd-kit/sortable';
import { Form, router } from '@inertiajs/react';
import { GripVertical, ImageIcon, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { FieldDescription, FieldError } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { destroy, reorder, store } from '@/routes/settings/room-types/photos';

type Photo = App.Data.Rooms.RoomTypePhotoData;

function PhotoTile({
    photo,
    index,
    readOnly,
    onRemove,
}: {
    photo: Photo;
    index: number;
    readOnly: boolean;
    onRemove: (photo: Photo) => void;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: photo.id, disabled: readOnly });

    return (
        <li
            ref={setNodeRef}
            style={{
                transform: transform
                    ? `translate3d(${Math.round(transform.x)}px, ${Math.round(transform.y)}px, 0)`
                    : undefined,
                transition,
            }}
            className={`relative aspect-4/3 overflow-hidden rounded-md border bg-muted ${
                isDragging ? 'z-10 shadow-md' : ''
            }`}
        >
            <img
                src={photo.thumbUrl}
                alt={photo.name}
                className="size-full object-cover"
            />
            {index === 0 && (
                <Badge className="absolute top-2 left-2">Cover</Badge>
            )}
            {!readOnly && (
                <div className="absolute inset-x-2 bottom-2 flex justify-between">
                    <Button
                        ref={setActivatorNodeRef}
                        type="button"
                        variant="secondary"
                        size="icon-sm"
                        className="cursor-grab touch-none"
                        aria-label={`Move ${photo.name}`}
                        {...attributes}
                        {...listeners}
                    >
                        <GripVertical />
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        size="icon-sm"
                        aria-label={`Remove ${photo.name}`}
                        onClick={() => onRemove(photo)}
                    >
                        <Trash2 />
                    </Button>
                </div>
            )}
        </li>
    );
}

/**
 * The photo grid of a room type. Files upload as soon as they are picked; dragging saves the new order at once.
 * The first photo is the cover.
 */
export function RoomTypePhotos({
    roomTypeId,
    photos,
    photoLimit,
    photoMaxKb,
    readOnly,
}: {
    roomTypeId: number;
    photos: Photo[];
    photoLimit: number;
    photoMaxKb: number;
    readOnly: boolean;
}) {
    const fileInput = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string>();
    const [removing, setRemoving] = useState<Photo | null>(null);
    const [order, setOrder] = useState<number[] | null>(null);

    const byId = new Map(photos.map((photo) => [photo.id, photo]));
    const ordered =
        order !== null && order.length === photos.length
            ? order
                  .map((id) => byId.get(id))
                  .filter((photo): photo is Photo => photo !== undefined)
            : photos;
    const full = photos.length >= photoLimit;

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    const upload = (files: File[]) => {
        setError(undefined);
        router.post(
            store.url({ roomType: roomTypeId }),
            { photos: files },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setUploading(true),
                onSuccess: () => setOrder(null),
                onError: (errors) =>
                    setError(Object.values(errors)[0] ?? 'Upload failed.'),
                onFinish: () => {
                    setUploading(false);

                    if (fileInput.current) {
                        fileInput.current.value = '';
                    }
                },
            },
        );
    };

    const handleDragEnd = ({ active, over }: DragEndEvent) => {
        if (!over || active.id === over.id) {
            return;
        }

        const ids = ordered.map((photo) => photo.id);
        const next = arrayMove(
            ids,
            ids.indexOf(Number(active.id)),
            ids.indexOf(Number(over.id)),
        );

        setOrder(next);
        router.put(
            reorder.url({ roomType: roomTypeId }),
            { order: next },
            { preserveScroll: true, onFinish: () => setOrder(null) },
        );
    };

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-3">
                {!readOnly && (
                    <>
                        <input
                            ref={fileInput}
                            type="file"
                            multiple
                            accept="image/jpeg,image/png,image/webp"
                            className="hidden"
                            aria-label="Photo files"
                            onChange={(event) => {
                                const files = Array.from(
                                    event.target.files ?? [],
                                );

                                if (files.length > 0) {
                                    upload(files);
                                }
                            }}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            disabled={uploading || full}
                            focusableWhenDisabled
                            onClick={() => fileInput.current?.click()}
                        >
                            {uploading ? (
                                <Spinner data-icon="inline-start" />
                            ) : (
                                <Upload data-icon="inline-start" />
                            )}
                            Upload photos
                        </Button>
                    </>
                )}
                <FieldDescription>
                    JPEG, PNG or WebP, up to {photoMaxKb / 1024} MB each, and{' '}
                    {photoLimit} photos in all. Drag to reorder; the first photo
                    is the cover.
                </FieldDescription>
            </div>
            <FieldError>{error}</FieldError>

            {ordered.length === 0 ? (
                <EmptyState
                    icon={ImageIcon}
                    title="No photos yet"
                    description="Guests see these photos when they book online."
                />
            ) : (
                <DndContext
                    sensors={sensors}
                    collisionDetection={closestCenter}
                    onDragEnd={handleDragEnd}
                >
                    <SortableContext
                        items={ordered.map((photo) => photo.id)}
                        strategy={rectSortingStrategy}
                    >
                        <ul className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
                            {ordered.map((photo, index) => (
                                <PhotoTile
                                    key={photo.id}
                                    photo={photo}
                                    index={index}
                                    readOnly={readOnly}
                                    onRemove={setRemoving}
                                />
                            ))}
                        </ul>
                    </SortableContext>
                </DndContext>
            )}

            <AlertDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Remove {removing?.name}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            The photo will no longer show to guests. You can
                            upload it again later.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {removing && (
                        <Form
                            {...destroy.form({
                                roomType: roomTypeId,
                                photo: removing.id,
                            })}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setRemoving(null)}
                        >
                            {({ processing }) => (
                                <AlertDialogFooter>
                                    <AlertDialogCancel>
                                        Cancel
                                    </AlertDialogCancel>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                        focusableWhenDisabled
                                    >
                                        {processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        Remove photo
                                    </Button>
                                </AlertDialogFooter>
                            )}
                        </Form>
                    )}
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
