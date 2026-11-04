import { PhotoGrid } from '@/components/pms/photo-grid';
import { destroy, reorder, store } from '@/routes/settings/room-types/photos';

/**
 * The photo grid of a room type.
 */
export function RoomTypePhotos({
    roomTypeId,
    photos,
    photoLimit,
    photoMaxKb,
    readOnly,
}: {
    roomTypeId: number;
    photos: App.Data.Rooms.RoomTypePhotoData[];
    photoLimit: number;
    photoMaxKb: number;
    readOnly: boolean;
}) {
    return (
        <PhotoGrid
            title="Room photos"
            description="Photos of this room type, such as the bed, bathroom or view."
            photos={photos}
            photoLimit={photoLimit}
            photoMaxKb={photoMaxKb}
            readOnly={readOnly}
            uploadUrl={store.url({ roomType: roomTypeId })}
            reorderUrl={reorder.url({ roomType: roomTypeId })}
            removeForm={(photoId) =>
                destroy.form({ roomType: roomTypeId, photo: photoId })
            }
        />
    );
}
