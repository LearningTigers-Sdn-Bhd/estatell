import { Head } from '@inertiajs/react';
import type { EnumOption } from '@/components/pms/enum-select';
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
    bedsOn,
    termsDocuments,
}: {
    roomType: RoomType;
    roomCount: number;
    canManage: boolean;
    sellUnits: EnumOption[];
    bedsOn: boolean;
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
                    bedsOn={bedsOn}
                    termsDocuments={termsDocuments}
                    readOnly={isRoomTypeReadOnly(roomType, canManage)}
                />
            </RoomTypeShell>
        </>
    );
}

RoomTypeEdit.layout = (props: Parameters<typeof roomTypeBreadcrumbs>[0]) => ({
    breadcrumbs: roomTypeBreadcrumbs(props, 'details'),
});
