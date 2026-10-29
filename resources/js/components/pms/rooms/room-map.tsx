import { router } from '@inertiajs/react';
import { Save, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { BulkActionBar } from '@/components/pms/bulk-action-bar';
import { EnumSelect } from '@/components/pms/enum-select';
import { RoomGrid } from '@/components/pms/rooms/room-grid';
import type { RoomGridSection } from '@/components/pms/rooms/room-grid';
import { RoomTypePicker } from '@/components/pms/rooms/room-type-picker';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import {
    roomTypeColorClass,
    roomTypeShortLabel,
} from '@/lib/room-type-display';
import { changeType } from '@/routes/settings/rooms';

type Room = App.Data.Rooms.RoomData;
type RoomGroup = App.Data.Rooms.RoomGroupData;
type RoomType = App.Data.Rooms.RoomTypeData;
type Bed = App.Data.Rooms.BedData;

const mutedClass = 'bg-muted text-muted-foreground';

const plural = (count: number, word: string) =>
    `${count} ${word}${count === 1 ? '' : 's'}`;

/**
 * The Map tab of Settings > Rooms: the active rooms as coloured tiles. Pick a type and click rooms to paint them in
 * the browser; Save sends one change per painted type. Painted state is derived against the saved types in props,
 * so after a save a room whose saved type matches its paint is no longer painted, and a refused room stays painted.
 */
export function RoomMap({
    rooms,
    roomGroups,
    roomTypes,
    beds,
    canManage,
}: {
    rooms: Room[];
    roomGroups: RoomGroup[];
    roomTypes: RoomType[];
    beds: Bed[];
    canManage: boolean;
}) {
    const activeTypes = roomTypes.filter((type) => !type.archived);
    const [selected, setSelected] = useState(activeTypes[0]?.id ?? 0);
    // Room id to the painted room type id.
    const [paint, setPaint] = useState<Record<number, number>>({});
    const [anchor, setAnchor] = useState<{
        section: string;
        index: number;
    } | null>(null);
    // '' = all groups, 'none' = ungrouped rooms, otherwise a group id.
    const [groupFilter, setGroupFilter] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const activeRooms = rooms.filter((room) => !room.archived);
    const paintOf = (room: Room) =>
        paint[room.id] !== undefined && paint[room.id] !== room.roomTypeId
            ? paint[room.id]
            : null;
    const painted = activeRooms.filter((room) => paintOf(room) !== null);
    const typeOf = (room: Room) => paintOf(room) ?? room.roomTypeId;
    const dirty = canManage && painted.length > 0;
    const guard = useUnsavedChanges(dirty);

    const bedsOf = (roomId: number) =>
        beds.filter((bed) => bed.roomId === roomId && !bed.archived).length;

    const groupSections: { key: string; title: string; rooms: Room[] }[] =
        roomGroups.length === 0
            ? [{ key: 'all', title: 'All rooms', rooms: activeRooms }]
            : [
                  ...roomGroups.map((group) => ({
                      key: `group-${group.id}`,
                      title: group.name,
                      rooms: activeRooms.filter(
                          (room) => room.roomGroupId === group.id,
                      ),
                  })),
                  {
                      key: 'ungrouped',
                      title: 'Ungrouped',
                      rooms: activeRooms.filter(
                          (room) => room.roomGroupId === null,
                      ),
                  },
              ];
    const visible = groupSections.filter(
        (section) =>
            section.rooms.length > 0 &&
            (groupFilter === '' ||
                section.key ===
                    (groupFilter === 'none'
                        ? 'ungrouped'
                        : `group-${groupFilter}`)),
    );

    const sections: RoomGridSection[] = visible.map((section) => ({
        key: section.key,
        title: section.title,
        rooms: section.rooms.map((room) => {
            const type = roomTypes.find((item) => item.id === typeOf(room));
            const active = activeTypes.findIndex(
                (item) => item.id === type?.id,
            );

            return {
                id: room.id,
                number: room.number,
                typeName: type?.name ?? null,
                shortLabel: type ? roomTypeShortLabel(type.name) : '',
                colorClass:
                    active === -1 ? mutedClass : roomTypeColorClass(active),
                archivedType: active === -1,
                bedText:
                    type?.sellUnit === 'bed'
                        ? plural(bedsOf(room.id), 'bed')
                        : null,
                painted: paintOf(room) !== null,
            };
        }),
    }));

    const tiles = activeTypes.map((type, index) => {
        const ofType = activeRooms.filter((room) => typeOf(room) === type.id);

        return {
            id: type.id,
            name: type.name,
            shortLabel: roomTypeShortLabel(type.name),
            colorClass: roomTypeColorClass(index),
            soldByBed: type.sellUnit === 'bed',
            rooms: ofType.length,
            beds: ofType.reduce((sum, room) => sum + bedsOf(room.id), 0),
        };
    });

    const groupFilterOptions = [
        ...roomGroups.map((group) => ({
            value: String(group.id),
            label: group.name,
        })),
        { value: 'none', label: 'Ungrouped' },
    ];

    const paintRooms = (targets: Room[]) =>
        setPaint((current) => {
            const next = { ...current };

            targets.forEach((room) => {
                if (room.roomTypeId === selected) {
                    delete next[room.id];
                } else {
                    next[room.id] = selected;
                }
            });

            return next;
        });

    const clickRoom = (sectionKey: string, index: number, shift: boolean) => {
        const section = visible.find((item) => item.key === sectionKey);
        const room = section?.rooms[index];

        if (!section || !room) {
            return;
        }

        if (shift && anchor !== null && anchor.section === sectionKey) {
            paintRooms(
                section.rooms.slice(
                    Math.min(anchor.index, index),
                    Math.max(anchor.index, index) + 1,
                ),
            );
        } else {
            paintRooms([room]);
        }

        setAnchor({ section: sectionKey, index });
    };

    const save = (onSaved?: () => void) => {
        const changes = activeTypes
            .map((type) => ({
                room_type_id: type.id,
                room_ids: painted
                    .filter((room) => paintOf(room) === type.id)
                    .map((room) => room.id),
            }))
            .filter((change) => change.room_ids.length > 0);

        router.post(
            changeType.url(),
            { changes },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => {
                    setSaving(true);
                    setError(null);
                },
                onFinish: () => setSaving(false),
                onSuccess: () => onSaved?.(),
                onError: (errors) => setError(Object.values(errors)[0] ?? null),
            },
        );
    };

    return (
        <div className="flex flex-col gap-4">
            {roomGroups.length > 0 && (
                <div className="flex flex-wrap justify-end gap-2">
                    <EnumSelect
                        aria-label="Filter by room group"
                        className="w-48"
                        options={groupFilterOptions}
                        allLabel="All groups"
                        value={groupFilter}
                        onValueChange={setGroupFilter}
                    />
                </div>
            )}
            <div className="flex flex-col gap-3">
                <p className="text-sm text-muted-foreground">
                    {canManage
                        ? 'Pick a type, then click rooms. Shift-click paints every room between.'
                        : 'The room types and the rooms of each group.'}
                </p>
                <RoomTypePicker
                    tiles={tiles}
                    value={selected}
                    onValueChange={setSelected}
                    disabled={!canManage || saving}
                />
            </div>
            <FieldError>{error}</FieldError>
            {sections.length > 0 ? (
                <RoomGrid
                    sections={sections}
                    disabled={!canManage || saving}
                    onRoomClick={clickRoom}
                />
            ) : (
                <p className="text-sm text-muted-foreground">
                    No rooms in this group.
                </p>
            )}
            {canManage && (
                <BulkActionBar
                    count={painted.length}
                    label={`${plural(painted.length, 'room')} changed`}
                    onClear={() => setPaint({})}
                    clearOnEscape={false}
                    showClear={false}
                    disabled={saving}
                >
                    <Button
                        variant="outline"
                        disabled={saving}
                        focusableWhenDisabled
                        onClick={() => setPaint({})}
                    >
                        <Undo2 data-icon="inline-start" />
                        Discard
                    </Button>
                    <Button
                        disabled={saving}
                        focusableWhenDisabled
                        onClick={() => save()}
                    >
                        {saving ? (
                            <Spinner data-icon="inline-start" />
                        ) : (
                            <Save data-icon="inline-start" />
                        )}
                        Save
                    </Button>
                </BulkActionBar>
            )}
            <UnsavedChangesDialog
                guard={guard}
                name="room types"
                saving={saving}
                onSave={() => save(guard.proceed)}
            />
        </div>
    );
}
