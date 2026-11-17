import { BedDouble, ChevronDown, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import {
    BedsSheet,
    GenerateAllBedsSheet,
} from '@/components/pms/rooms/beds-sheet';
import { GenerateRoomsSheet } from '@/components/pms/rooms/generate-rooms-sheet';
import { SettingsSection } from '@/components/pms/settings-section';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { update } from '@/routes/settings/room-types/rooms';

type RoomType = App.Data.Rooms.RoomTypeData;
type Choice = App.Data.Rooms.RoomTypeRoomChoiceData;
type Room = App.Data.Rooms.RoomData;
type Bed = App.Data.Rooms.BedData;

type Row = {
    id: number;
    number: string;
    /** The room type the room comes from, for a room picked from another type. */
    from: string | null;
};

/**
 * The form's values as hidden inputs, always in room id order so a save never looks like a change.
 * Picking, undoing and regrouping change inputs React renders, which fire no DOM event, so this tells the form to re-check.
 */
function RoomInputs({
    rows,
    groups,
}: {
    rows: Row[];
    groups: Record<number, string>;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const values = [...rows]
        .sort((a, b) => a.id - b.id)
        .map((row) => ({ id: row.id, group: groups[row.id] ?? '' }));
    const signature = JSON.stringify(values);

    useEffect(() => {
        ref.current?.dispatchEvent(new Event('input', { bubbles: true }));
    }, [signature]);

    return (
        <div ref={ref} hidden>
            {values.map((value, position) => (
                <span key={value.id}>
                    <input
                        type="hidden"
                        name={`rooms[${position}][room_id]`}
                        value={value.id}
                    />
                    <input
                        type="hidden"
                        name={`rooms[${position}][room_group_id]`}
                        value={value.group}
                    />
                </span>
            ))}
        </div>
    );
}

/**
 * A header or row button. With a `disabledReason` it is disabled and the reason shows as a tooltip.
 */
function ReasonButton({
    disabledReason,
    onClick,
    children,
}: {
    disabledReason: string | null;
    onClick: () => void;
    children: ReactNode;
}) {
    if (disabledReason === null) {
        return (
            <Button type="button" variant="outline" size="sm" onClick={onClick}>
                {children}
            </Button>
        );
    }

    return (
        <Tooltip>
            <TooltipTrigger
                render={
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled
                        focusableWhenDisabled
                    />
                }
            >
                {children}
            </TooltipTrigger>
            <TooltipContent>{disabledReason}</TooltipContent>
        </Tooltip>
    );
}

function AddRoomsPicker({
    choices,
    onPick,
}: {
    choices: Choice[];
    onPick: (choice: Choice) => void;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger
                render={<Button type="button" variant="outline" size="sm" />}
            >
                <Plus data-icon="inline-start" />
                Add rooms
                <ChevronDown data-icon="inline-end" />
            </PopoverTrigger>
            <PopoverContent align="end" className="w-72 p-0">
                <Command>
                    <CommandInput placeholder="e.g. 305 or Standard Twin" />
                    <CommandList>
                        <CommandEmpty>
                            No rooms in other room types.
                        </CommandEmpty>
                        {choices.map((choice) => (
                            <CommandItem
                                key={choice.id}
                                value={`${choice.number} ${choice.roomTypeName}`}
                                onSelect={() => {
                                    onPick(choice);
                                    setOpen(false);
                                }}
                            >
                                {choice.number} · {choice.roomTypeName}
                            </CommandItem>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

/**
 * The Rooms tab: a group for each room of the type, and rooms picked from other types. Nothing moves until Save.
 * Rooms never leave a type here; they move when they are added on another type's page.
 * `beds` is set for a Bed room type: each saved room shows its bed count and a Beds button, and Add beds fills
 * the rooms without beds. Bed changes save at once, so their buttons wait until the rooms form is saved.
 */
export function RoomTypeRoomsForm({
    roomType,
    rooms,
    otherRooms,
    roomGroups,
    generateLimit,
    beds,
    bedPrefixes,
    bedLimit,
    readOnly,
}: {
    roomType: RoomType;
    rooms: Room[];
    otherRooms: Choice[];
    roomGroups: App.Data.Rooms.RoomGroupData[];
    generateLimit: number;
    beds: Bed[] | null;
    bedPrefixes: Record<number, string>;
    bedLimit: number;
    readOnly: boolean;
}) {
    const [added, setAdded] = useState<Choice[]>([]);
    const [groups, setGroups] = useState<Record<number, string>>(() =>
        Object.fromEntries(
            rooms.map((room) => [room.id, String(room.roomGroupId ?? '')]),
        ),
    );
    const [generating, setGenerating] = useState(false);
    const [bedsRoom, setBedsRoom] = useState<Room | null>(null);
    const [addingBeds, setAddingBeds] = useState(false);

    const rows: Row[] = [
        ...rooms.map((room) => ({
            id: room.id,
            number: room.number,
            from: null,
        })),
        ...added.map((choice) => ({
            id: choice.id,
            number: choice.number,
            from: choice.roomTypeName,
        })),
    ];
    const addedIds = new Set(added.map((choice) => choice.id));
    const groupOptions = roomGroups.map((group) => ({
        value: String(group.id),
        label: group.name,
    }));

    const hasChanges =
        added.length > 0 ||
        rooms.some(
            (room) =>
                (groups[room.id] ?? '') !== String(room.roomGroupId ?? ''),
        );
    const savedFirst = (dirty: boolean) =>
        dirty ? 'Save or discard your changes first.' : null;
    const bedCount = (roomId: number) =>
        (beds ?? []).filter((bed) => bed.roomId === roomId && !bed.archived)
            .length;
    const roomsWithoutBeds = rooms
        .filter((room) => bedCount(room.id) === 0)
        .map((room) => room.id);

    const pick = (choice: Choice) => {
        setAdded((previous) => [...previous, choice]);
        setGroups((previous) => ({
            ...previous,
            [choice.id]: String(choice.roomGroupId ?? ''),
        }));
    };

    const undo = (id: number) =>
        setAdded((previous) => previous.filter((choice) => choice.id !== id));

    return (
        <>
            <SettingsSection
                form={update.form({ roomType: roomType.id })}
                title="Rooms"
                description={
                    beds === null
                        ? 'Rooms of this type and their group.'
                        : 'Rooms of this type, their group and beds.'
                }
                submitLabel="Save rooms"
                contentClassName="flex flex-col gap-4"
                readOnly={readOnly}
                guardUnsavedChanges
                actions={
                    readOnly
                        ? undefined
                        : ({ isDirty }) => (
                              <>
                                  {beds !== null && rooms.length > 0 && (
                                      <ReasonButton
                                          disabledReason={
                                              savedFirst(isDirty) ??
                                              (roomsWithoutBeds.length === 0
                                                  ? 'Every room already has beds.'
                                                  : null)
                                          }
                                          onClick={() => setAddingBeds(true)}
                                      >
                                          <BedDouble data-icon="inline-start" />
                                          Add beds
                                      </ReasonButton>
                                  )}
                                  <ReasonButton
                                      disabledReason={savedFirst(isDirty)}
                                      onClick={() => setGenerating(true)}
                                  >
                                      Generate rooms
                                  </ReasonButton>
                                  <AddRoomsPicker
                                      choices={otherRooms.filter(
                                          (choice) => !addedIds.has(choice.id),
                                      )}
                                      onPick={pick}
                                  />
                              </>
                          )
                }
            >
                {(errors) => (
                    <>
                        <RoomInputs rows={rows} groups={groups} />
                        {Object.values(errors).length > 0 && (
                            <p className="text-sm text-destructive">
                                {Object.values(errors)[0]}
                            </p>
                        )}
                        {rows.length === 0 ? (
                            <EmptyState
                                icon={BedDouble}
                                title="No rooms yet."
                                description="Generate or add rooms."
                            />
                        ) : (
                            <div className="overflow-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Room</TableHead>
                                            <TableHead>Group</TableHead>
                                            {beds !== null && (
                                                <TableHead>Beds</TableHead>
                                            )}
                                            <TableHead>
                                                <span className="sr-only">
                                                    Moved from
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {rows.map((row) => (
                                            <TableRow key={row.id}>
                                                <TableCell className="font-medium">
                                                    {row.number}
                                                </TableCell>
                                                <TableCell>
                                                    <EnumSelect
                                                        options={groupOptions}
                                                        allLabel="Ungrouped"
                                                        value={
                                                            groups[row.id] ?? ''
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            setGroups(
                                                                (previous) => ({
                                                                    ...previous,
                                                                    [row.id]:
                                                                        value,
                                                                }),
                                                            )
                                                        }
                                                        disabled={readOnly}
                                                        aria-label={`Group of room ${row.number}`}
                                                        className="w-48"
                                                    />
                                                </TableCell>
                                                {beds !== null && (
                                                    <TableCell>
                                                        {row.from === null ? (
                                                            <div className="flex items-center gap-3">
                                                                <span className="w-6 tabular-nums">
                                                                    {bedCount(
                                                                        row.id,
                                                                    )}
                                                                </span>
                                                                {!readOnly && (
                                                                    <ReasonButton
                                                                        disabledReason={savedFirst(
                                                                            hasChanges,
                                                                        )}
                                                                        onClick={() =>
                                                                            setBedsRoom(
                                                                                rooms.find(
                                                                                    (
                                                                                        room,
                                                                                    ) =>
                                                                                        room.id ===
                                                                                        row.id,
                                                                                ) ??
                                                                                    null,
                                                                            )
                                                                        }
                                                                    >
                                                                        Beds
                                                                    </ReasonButton>
                                                                )}
                                                            </div>
                                                        ) : (
                                                            '—'
                                                        )}
                                                    </TableCell>
                                                )}
                                                <TableCell>
                                                    {row.from !== null && (
                                                        <div className="flex items-center justify-end gap-2 text-sm text-muted-foreground">
                                                            From {row.from}
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() =>
                                                                    undo(row.id)
                                                                }
                                                            >
                                                                Undo
                                                            </Button>
                                                        </div>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </>
                )}
            </SettingsSection>
            {!readOnly && (
                <GenerateRoomsSheet
                    open={generating}
                    onOpenChange={setGenerating}
                    roomTypes={[]}
                    roomGroups={groupOptions}
                    limit={generateLimit}
                    roomType={{ id: roomType.id, name: roomType.name }}
                />
            )}
            {!readOnly && beds !== null && (
                <>
                    <BedsSheet
                        room={bedsRoom}
                        beds={beds}
                        prefixes={bedPrefixes}
                        limit={bedLimit}
                        onClose={() => setBedsRoom(null)}
                    />
                    <GenerateAllBedsSheet
                        open={addingBeds}
                        onOpenChange={setAddingBeds}
                        limit={bedLimit}
                        roomIds={roomsWithoutBeds}
                        title={`Add beds to ${roomType.name}`}
                        description="Every room of this type without beds gets the same number of beds. Each room gets its own letter: A1, A2 ..., then B1, B2 ..., and so on. Rooms that already have beds are left alone."
                    />
                </>
            )}
        </>
    );
}
