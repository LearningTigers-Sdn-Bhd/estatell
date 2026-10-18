import { router } from '@inertiajs/react';
import { Bed, FileText } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { ArchiveRoomTypeButton } from '@/components/pms/rooms/archive-room-type-button';
import { TabLinks } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit, index, restore } from '@/routes/settings/room-types';
import { edit as editRooms } from '@/routes/settings/room-types/rooms';

type RoomType = App.Data.Rooms.RoomTypeData;

export type RoomTypeTab = 'details' | 'rooms';

export function roomTypeBreadcrumbs(roomType: RoomType) {
    return [
        { title: 'Room types', href: index() },
        { title: roomType.name, href: edit({ roomType: roomType.id }) },
    ];
}

/**
 * Whether the room type page is read-only: the user may only view, or the room type is archived.
 */
export function isRoomTypeReadOnly(roomType: RoomType, canManage: boolean) {
    return !canManage || roomType.archived;
}

/**
 * Header and tab links of the room type page. Each tab is its own page and URL, and loads only its own data.
 * Archive (or Restore) shows only for staff who can manage rooms.
 */
export function RoomTypeShell({
    roomType,
    roomCount,
    canManage,
    activeTab,
    children,
}: {
    roomType: RoomType;
    roomCount: number;
    canManage: boolean;
    activeTab: RoomTypeTab;
    children: ReactNode;
}) {
    const tabs: TabLink[] = [
        {
            value: 'details',
            label: 'Details',
            icon: FileText,
            href: edit.url({ roomType: roomType.id }),
        },
        {
            value: 'rooms',
            label: `Rooms (${roomCount})`,
            icon: Bed,
            href: editRooms.url({ roomType: roomType.id }),
        },
    ];

    return (
        <div className="flex flex-1 flex-col gap-6 p-4">
            <PageHeader
                title={roomType.name}
                back={{ href: index.url(), label: 'Room types' }}
                badge={
                    roomType.archived ? (
                        <Badge variant="secondary">Archived</Badge>
                    ) : undefined
                }
                actions={
                    canManage ? (
                        roomType.archived ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        restore.url({ roomType: roomType.id }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Restore
                            </Button>
                        ) : (
                            <ArchiveRoomTypeButton
                                roomType={roomType}
                                variant="outline"
                            />
                        )
                    ) : undefined
                }
            />
            <TabLinks
                tabs={tabs}
                activeTab={activeTab}
                className="flex flex-col gap-4"
            >
                {children}
            </TabLinks>
        </div>
    );
}
