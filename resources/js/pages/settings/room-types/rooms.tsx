import { Head } from '@inertiajs/react';
import { RoomTypeRoomsForm } from '@/components/pms/rooms/room-type-rooms-form';
import {
    RoomTypeShell,
    isRoomTypeReadOnly,
    roomTypeBreadcrumbs,
} from '@/components/pms/rooms/room-type-shell';

type RoomType = App.Data.Rooms.RoomTypeData;

export default function RoomTypeRooms({
    roomType,
    roomCount,
    canManage,
    rooms,
    otherRooms,
    roomGroups,
    generateLimit,
}: {
    roomType: RoomType;
    roomCount: number;
    canManage: boolean;
    rooms: App.Data.Rooms.RoomData[];
    otherRooms: App.Data.Rooms.RoomTypeRoomChoiceData[];
    roomGroups: App.Data.Rooms.RoomGroupData[];
    generateLimit: number;
}) {
    // A new key remounts the form with the saved rooms, so unsaved picks never outlive a save.
    const signature = rooms
        .map((room) => `${room.id}:${room.roomGroupId ?? ''}`)
        .join(',');

    return (
        <>
            <Head title={`${roomType.name} rooms`} />
            <RoomTypeShell
                roomType={roomType}
                roomCount={roomCount}
                canManage={canManage}
                activeTab="rooms"
            >
                <RoomTypeRoomsForm
                    key={signature}
                    roomType={roomType}
                    rooms={rooms}
                    otherRooms={otherRooms}
                    roomGroups={roomGroups}
                    generateLimit={generateLimit}
                    readOnly={isRoomTypeReadOnly(roomType, canManage)}
                />
            </RoomTypeShell>
        </>
    );
}

RoomTypeRooms.layout = (props: { roomType: RoomType }) => ({
    breadcrumbs: roomTypeBreadcrumbs(props.roomType),
});
