import { Head } from '@inertiajs/react';
import { RoomTypePhotos } from '@/components/pms/rooms/room-type-photos';
import {
    RoomTypeShell,
    isRoomTypeReadOnly,
    roomTypeLayout,
} from '@/components/pms/rooms/room-type-shell';

type RoomType = App.Data.Rooms.RoomTypeData;

export default function RoomTypePhotosPage({
    roomType,
    roomCount,
    canManage,
    photos,
    photoLimit,
    photoMaxKb,
}: {
    roomType: RoomType;
    roomCount: number;
    canManage: boolean;
    photos: App.Data.Rooms.RoomTypePhotoData[];
    photoLimit: number;
    photoMaxKb: number;
}) {
    return (
        <>
            <Head title={`${roomType.name} photos`} />
            <RoomTypeShell
                roomType={roomType}
                roomCount={roomCount}
                canManage={canManage}
                activeTab="photos"
            >
                <RoomTypePhotos
                    roomTypeId={roomType.id}
                    photos={photos}
                    photoLimit={photoLimit}
                    photoMaxKb={photoMaxKb}
                    readOnly={isRoomTypeReadOnly(roomType, canManage)}
                />
            </RoomTypeShell>
        </>
    );
}

RoomTypePhotosPage.layout = (props: Parameters<typeof roomTypeLayout>[0]) =>
    roomTypeLayout(props, 'photos');
