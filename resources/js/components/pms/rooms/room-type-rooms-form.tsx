import { BedDouble, ChevronDown, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
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
 */
export function RoomTypeRoomsForm({
    roomType,
    rooms,
    otherRooms,
    roomGroups,
    generateLimit,
    readOnly,
}: {
    roomType: RoomType;
    rooms: App.Data.Rooms.RoomData[];
    otherRooms: Choice[];
    roomGroups: App.Data.Rooms.RoomGroupData[];
    generateLimit: number;
    readOnly: boolean;
}) {
    const [added, setAdded] = useState<Choice[]>([]);
    const [groups, setGroups] = useState<Record<number, string>>(() =>
        Object.fromEntries(
            rooms.map((room) => [room.id, String(room.roomGroupId ?? '')]),
        ),
    );
    const [generating, setGenerating] = useState(false);

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
                description="Rooms of this type and their group."
                submitLabel="Save rooms"
                contentClassName="flex flex-col gap-4"
                readOnly={readOnly}
                guardUnsavedChanges
                actions={
                    readOnly
                        ? undefined
                        : ({ isDirty }) => (
                              <>
                                  {isDirty ? (
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
                                              Generate rooms
                                          </TooltipTrigger>
                                          <TooltipContent>
                                              Save or discard your changes
                                              first.
                                          </TooltipContent>
                                      </Tooltip>
                                  ) : (
                                      <Button
                                          type="button"
                                          variant="outline"
                                          size="sm"
                                          onClick={() => setGenerating(true)}
                                      >
                                          Generate rooms
                                      </Button>
                                  )}
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
        </>
    );
}
