import { PhotoGrid } from '@/components/pms/photo-grid';
import { destroy, reorder, store } from '@/routes/settings/photos';

/**
 * The property album on the Details page. Each action saves at once, so it has no form or Save button. See
 * docs/02-property/01-properties.md#photos.
 */
export function PropertyPhotos({
    photos,
    photoLimit,
    photoMaxKb,
}: {
    photos: App.Data.Property.PropertyPhotoData[];
    photoLimit: number;
    photoMaxKb: number;
}) {
    return (
        <PhotoGrid
            title="Property photos"
            description="Your property's own photos, such as the beach, lobby or pool."
            photos={photos}
            photoLimit={photoLimit}
            photoMaxKb={photoMaxKb}
            readOnly={false}
            uploadUrl={store.url()}
            reorderUrl={reorder.url()}
            removeForm={(photoId) => destroy.form({ photo: photoId })}
        />
    );
}
