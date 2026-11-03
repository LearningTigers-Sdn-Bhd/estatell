import { Head } from '@inertiajs/react';
import { AmenityPicker } from '@/components/pms/amenities/amenity-picker';
import {
    RoomTypeShell,
    isRoomTypeReadOnly,
    roomTypeBreadcrumbs,
} from '@/components/pms/rooms/room-type-shell';
import { update } from '@/routes/settings/room-types/amenities';

type RoomType = App.Data.Rooms.RoomTypeData;

export default function RoomTypeAmenitiesPage({
    roomType,
    roomCount,
    canManage,
    amenities,
}: {
    roomType: RoomType;
    roomCount: number;
    canManage: boolean;
    amenities: App.Data.BookingEngine.AmenityPickerData;
}) {
    return (
        <>
            <Head title={`${roomType.name} amenities`} />
            <RoomTypeShell
                roomType={roomType}
                roomCount={roomCount}
                canManage={canManage}
                activeTab="amenities"
            >
                <AmenityPicker
                    picker={amenities}
                    form={update.form({ roomType: roomType.id })}
                    title="Amenities"
                    description="Tick what guests find in this room type."
                    readOnly={isRoomTypeReadOnly(roomType, canManage)}
                />
            </RoomTypeShell>
        </>
    );
}

RoomTypeAmenitiesPage.layout = (props: { roomType: RoomType }) => ({
    breadcrumbs: roomTypeBreadcrumbs(props.roomType),
});
