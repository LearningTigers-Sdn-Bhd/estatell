import { Head } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { AmenityPicker } from '@/components/pms/amenities/amenity-picker';
import {
    RoomTypeShell,
    isRoomTypeReadOnly,
    roomTypeLayout,
} from '@/components/pms/rooms/room-type-shell';
import { update } from '@/routes/settings/room-types/amenities';

type RoomType = App.Data.Rooms.RoomTypeData;

export default function RoomTypeAmenitiesPage({
    roomType,
    roomCount,
    canManage,
    amenities,
    page,
    listUrl,
}: {
    roomType: RoomType;
    roomCount: number;
    canManage: boolean;
    amenities: App.Data.BookingEngine.AmenityPickerData;
    page: number;
    listUrl: string;
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
                    saveUrl={update.url({ roomType: roomType.id })}
                    icon={Sparkles}
                    title="Amenities"
                    description="Turn on what guests find in this room type. Each switch saves at once."
                    readOnly={isRoomTypeReadOnly(roomType, canManage)}
                    page={page}
                    listUrl={listUrl}
                />
            </RoomTypeShell>
        </>
    );
}

RoomTypeAmenitiesPage.layout = (props: Parameters<typeof roomTypeLayout>[0]) =>
    roomTypeLayout(props, 'amenities');
