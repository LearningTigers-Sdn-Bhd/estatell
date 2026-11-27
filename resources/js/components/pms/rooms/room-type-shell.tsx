import { router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    Bed,
    FileText,
    Images,
    MoreHorizontal,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { ArchiveRoomTypeDialog } from '@/components/pms/rooms/archive-room-type-dialog';
import { TabLinks } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
import { withTabCrumb } from '@/components/pms/tab-links';
import { Workspace } from '@/components/pms/workspace';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { edit, index, restore } from '@/routes/settings/room-types';
import { edit as editAmenities } from '@/routes/settings/room-types/amenities';
import { edit as editPhotos } from '@/routes/settings/room-types/photos';
import { edit as editRooms } from '@/routes/settings/room-types/rooms';

type RoomType = App.Data.Rooms.RoomTypeData;

export type RoomTypeTab = 'details' | 'rooms' | 'photos' | 'amenities';

type RoomTypeSwitch = App.Data.Rooms.RoomTypeSwitchData;

/**
 * The tabs of the room type page. The tab bar and the breadcrumbs use the same list.
 */
export function roomTypeTabs(
    roomTypeId: number,
    roomCount?: number,
): TabLink[] {
    const key = { roomType: roomTypeId };

    return [
        {
            value: 'details',
            label: 'Details',
            icon: FileText,
            href: edit.url(key),
        },
        {
            value: 'rooms',
            label: roomCount === undefined ? 'Rooms' : `Rooms (${roomCount})`,
            title: 'Rooms',
            icon: Bed,
            href: editRooms.url(key),
        },
        {
            value: 'photos',
            label: 'Photos',
            icon: Images,
            href: editPhotos.url(key),
        },
        {
            value: 'amenities',
            label: 'Amenities',
            icon: Sparkles,
            href: editAmenities.url(key),
        },
    ];
}

/**
 * Room types › {room type} › {tab}. The room type crumb switches to another room type on the same tab.
 */
export function roomTypeBreadcrumbs(
    props: {
        roomType: RoomType;
        roomCount: number;
        roomTypeSwitch: RoomTypeSwitch[];
    },
    activeTab: RoomTypeTab,
) {
    const { roomType } = props;

    return withTabCrumb(
        [
            { title: 'Room types', href: index() },
            {
                title: roomType.name,
                href: edit({ roomType: roomType.id }),
                siblings: {
                    label: 'Room types',
                    items: props.roomTypeSwitch.map((other) => ({
                        title: other.name,
                        href:
                            roomTypeTabs(other.id).find(
                                (tab) => tab.value === activeTab,
                            )?.href ?? edit.url({ roomType: other.id }),
                        current: other.id === roomType.id,
                    })),
                },
            },
        ],
        roomTypeTabs(roomType.id, props.roomCount),
        activeTab,
    );
}

/**
 * Whether the room type page is read-only: the user may only view, or the room type is archived.
 */
export function isRoomTypeReadOnly(roomType: RoomType, canManage: boolean) {
    return !canManage || roomType.archived;
}

/**
 * The page layout of every room type tab: the fixed-height shell with the breadcrumbs.
 */
export function roomTypeLayout(
    props: Parameters<typeof roomTypeBreadcrumbs>[0],
    activeTab: RoomTypeTab,
) {
    return {
        variant: 'fixed' as const,
        breadcrumbs: roomTypeBreadcrumbs(props, activeTab),
    };
}

/** The heading inset of a section inside the Workspace panel, next to its icon box. */
export const roomTypeHeadingClassName = 'ps-2 pt-2';

/**
 * Archive (after a confirm) or Restore, in a More actions menu.
 */
function RoomTypeActions({ roomType }: { roomType: RoomType }) {
    const [archiving, setArchiving] = useState(false);
    const [restoring, setRestoring] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger render={<Button variant="outline" />}>
                    <MoreHorizontal data-icon="inline-start" />
                    More actions
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    {roomType.archived ? (
                        <DropdownMenuItem
                            disabled={restoring}
                            onClick={() =>
                                router.post(
                                    restore.url({ roomType: roomType.id }),
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setRestoring(true),
                                        onFinish: () => setRestoring(false),
                                    },
                                )
                            }
                        >
                            <ArchiveRestore />
                            Restore
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem
                            variant="destructive"
                            onClick={() => setArchiving(true)}
                        >
                            <Archive />
                            Archive
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
            <ArchiveRoomTypeDialog
                roomType={roomType}
                open={archiving}
                onOpenChange={setArchiving}
            />
        </>
    );
}

/**
 * Header and tab links of the room type page. Each tab is its own page and URL, and loads only its own data.
 * The tab content sits in a fixed-height `Workspace` like Settings › Property, with no rail: the panel scrolls on its
 * own. More actions (Archive or Restore) shows only for staff who can manage rooms.
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
    const tabs = roomTypeTabs(roomType.id, roomCount);

    return (
        <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 lg:overflow-hidden">
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
                        <RoomTypeActions roomType={roomType} />
                    ) : undefined
                }
            />
            <TabLinks
                tabs={tabs}
                activeTab={activeTab}
                triggerClassName="md:min-w-0"
                panelWidth="full"
                className="flex flex-col gap-4 lg:min-h-0 lg:flex-1"
                panelClassName="flex flex-col lg:min-h-0 lg:flex-1"
            >
                <Workspace hideAside>{children}</Workspace>
            </TabLinks>
        </div>
    );
}
