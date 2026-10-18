import { Head } from '@inertiajs/react';
import { RoomTypeDetailsForm } from '@/components/pms/rooms/room-type-details-form';
import {
    RoomTypeShell,
    isRoomTypeReadOnly,
    roomTypeBreadcrumbs,
} from '@/components/pms/rooms/room-type-shell';

type RoomType = App.Data.Rooms.RoomTypeData;

export default function RoomTypeEdit({
    roomType,
    roomCount,
    canManage,
    sellUnits,
    termsDocuments,
}: {
    roomType: RoomType;
    roomCount: number;
    canManage: boolean;
    sellUnits: { value: string; label: string }[];
    termsDocuments: { value: number; label: string }[];
}) {
    return (
        <>
            <Head title={roomType.name} />
            <RoomTypeShell
                roomType={roomType}
                roomCount={roomCount}
                canManage={canManage}
                activeTab="details"
            >
                <RoomTypeDetailsForm
                    roomType={roomType}
                    sellUnits={sellUnits}
                    termsDocuments={termsDocuments}
                    readOnly={isRoomTypeReadOnly(roomType, canManage)}
                />
            </RoomTypeShell>
        </>
    );
}

RoomTypeEdit.layout = (props: { roomType: RoomType }) => ({
    breadcrumbs: roomTypeBreadcrumbs(props.roomType),
});
