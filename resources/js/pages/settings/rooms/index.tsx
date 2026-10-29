import { Head, router, useForm } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    BedDouble,
    ChevronDown,
    ChevronRight,
    DoorOpen,
    Ellipsis,
    FolderPlus,
    Plus,
    Wand2,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import { BulkActionBar } from '@/components/pms/bulk-action-bar';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import {
    BedsSheet,
    GenerateAllBedsSheet,
} from '@/components/pms/rooms/beds-sheet';
import { GenerateRoomsSheet } from '@/components/pms/rooms/generate-rooms-sheet';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import {
    archive,
    archiveMany,
    changeType,
    index,
    restore,
    store,
    update,
} from '@/routes/settings/rooms';
import {
    destroy,
    reorder,
    store as storeGroup,
    update as updateGroup,
} from '@/routes/settings/room-groups';

type Room = App.Data.Rooms.RoomData;
type RoomGroup = App.Data.Rooms.RoomGroupData;
type RoomType = App.Data.Rooms.RoomTypeData;
type Bed = App.Data.Rooms.BedData;

type Props = {
    rooms: Room[];
    roomGroups: RoomGroup[];
    roomTypes: RoomType[];
    beds: Bed[];
    bedPrefixes: Record<number, string>;
    generateLimit: number;
    bedLimit: number;
    canManage: boolean;
};

/**
 * Create or edit a room. Remounted (by key) for each room, so its state starts from that row.
 */
function RoomFormDialog({
    room,
    roomTypes,
    roomGroups,
    onClose,
}: {
    room: Room | null;
    roomTypes: RoomType[];
    roomGroups: RoomGroup[];
    onClose: () => void;
}) {
    const form = useForm({
        number: room?.number ?? '',
        room_type_id: room ? String(room.roomTypeId) : '',
        room_group_id: room?.roomGroupId ? String(room.roomGroupId) : '',
    });

    // A new room needs an active room type. An existing room may keep its archived one.
    const typeOptions = roomTypes
        .filter((type) => !type.archived || type.id === room?.roomTypeId)
        .map((type) => ({ value: String(type.id), label: type.name }));
    const groupOptions = roomGroups.map((group) => ({
        value: String(group.id),
        label: group.name,
    }));

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onClose };

        if (room) {
            form.put(update.url({ room: room.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <FieldGroup>
                <Field>
                    <FieldLabel htmlFor="room-number">Room number</FieldLabel>
                    <Input
                        id="room-number"
                        maxLength={30}
                        value={form.data.number}
                        onChange={(event) =>
                            form.setData('number', event.target.value)
                        }
                        aria-invalid={!!form.errors.number}
                        autoFocus
                    />
                    <FieldError>{form.errors.number}</FieldError>
                </Field>
                <Field>
                    <FieldLabel htmlFor="room-type">Room type</FieldLabel>
                    <EnumSelect
                        id="room-type"
                        options={typeOptions}
                        value={form.data.room_type_id}
                        onValueChange={(value) =>
                            form.setData('room_type_id', value)
                        }
                        aria-invalid={!!form.errors.room_type_id}
                    />
                    <FieldError>{form.errors.room_type_id}</FieldError>
                </Field>
                <Field>
                    <FieldLabel htmlFor="room-group">Room group</FieldLabel>
                    <EnumSelect
                        id="room-group"
                        options={groupOptions}
                        allLabel="No group"
                        value={form.data.room_group_id}
                        onValueChange={(value) =>
                            form.setData('room_group_id', value)
                        }
                        aria-invalid={!!form.errors.room_group_id}
                    />
                    <FieldError>{form.errors.room_group_id}</FieldError>
                </Field>
            </FieldGroup>
            <DialogFooter>
                <DialogClose render={<Button variant="outline" />}>
                    Cancel
                </DialogClose>
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    {room ? 'Save changes' : 'Create room'}
                </Button>
            </DialogFooter>
        </form>
    );
}

/**
 * Create or rename a room group. Remounted (by key) for each group.
 */
function RoomGroupFormDialog({
    group,
    onClose,
}: {
    group: RoomGroup | null;
    onClose: () => void;
}) {
    const form = useForm({ name: group?.name ?? '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onClose };

        if (group) {
            form.put(updateGroup.url({ roomGroup: group.id }), options);
        } else {
            form.post(storeGroup.url(), options);
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <FieldGroup>
                <Field>
                    <FieldLabel htmlFor="room-group-name">Name</FieldLabel>
                    <Input
                        id="room-group-name"
                        maxLength={100}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        aria-invalid={!!form.errors.name}
                        autoFocus
                    />
                    <FieldError>{form.errors.name}</FieldError>
                </Field>
            </FieldGroup>
            <DialogFooter>
                <DialogClose render={<Button variant="outline" />}>
                    Cancel
                </DialogClose>
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    {group ? 'Save changes' : 'Create group'}
                </Button>
            </DialogFooter>
        </form>
    );
}

/**
 * Confirms a destructive room or group action, then posts (or deletes) to the given URL.
 */
/**
 * Asks before an archive or delete. Pass `open` and `onOpenChange` to open it from elsewhere (a menu item); it then
 * renders no trigger of its own.
 */
function ConfirmAction({
    label,
    title,
    description,
    method,
    url,
    data = {},
    open: controlledOpen,
    onOpenChange,
    onSuccess,
}: {
    label: string;
    title: string;
    description: string;
    method: 'post' | 'delete';
    url: string;
    data?: Record<string, number[]>;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    onSuccess?: () => void;
}) {
    const [ownOpen, setOwnOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const controlled = controlledOpen !== undefined;
    const open = controlled ? controlledOpen : ownOpen;
    const setOpen = (next: boolean) =>
        controlled ? onOpenChange?.(next) : setOwnOpen(next);

    return (
        <AlertDialog open={open} onOpenChange={setOpen}>
            {!controlled && (
                <AlertDialogTrigger
                    render={<Button variant="ghost" size="sm" />}
                >
                    {label}
                </AlertDialogTrigger>
            )}
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {description}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <Button
                        variant="destructive"
                        disabled={processing}
                        focusableWhenDisabled
                        onClick={() =>
                            router[method](url, data, {
                                preserveScroll: true,
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                                onSuccess: () => {
                                    setOpen(false);
                                    onSuccess?.();
                                },
                            })
                        }
                    >
                        {processing && <Spinner data-icon="inline-start" />}
                        {label}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

type RoomSection = {
    key: string;
    /** Null for the rooms of a property without groups: they get no group row. */
    title: string | null;
    rooms: Room[];
    group?: RoomGroup;
    position?: number;
    archived?: boolean;
};

type RoomSelection = {
    selected: number[];
    /** Active rooms in open groups that match the filters. The header tick box ticks these. */
    tickable: number[];
    onTick: (roomId: number, checked: boolean) => void;
    onTickAll: (checked: boolean) => void;
};

/**
 * Rooms in one table. Each group, then Ungrouped, then Archived, starts with a full-width group row that collapses it.
 */
function RoomTable({
    sections,
    groupCount,
    collapsed,
    emptyMessage,
    typeName,
    sellsBeds,
    bedCount,
    onToggle,
    onMove,
    onRename,
    onDelete,
    onEdit,
    onBeds,
    selection,
}: {
    sections: RoomSection[];
    groupCount: number;
    collapsed: Set<string>;
    emptyMessage: string | null;
    typeName: (id: number) => string;
    sellsBeds: (id: number) => boolean;
    bedCount: (roomId: number) => number;
    onToggle: (key: string) => void;
    onMove: (from: number, to: number) => void;
    onRename: (group: RoomGroup) => void;
    onDelete: (group: RoomGroup) => void;
    onEdit: (room: Room) => void;
    onBeds: (room: Room) => void;
    /** Null without `rooms.manage`: no tick boxes. */
    selection: RoomSelection | null;
}) {
    const columns = selection === null ? 4 : 5;
    const allTicked =
        selection !== null &&
        selection.tickable.length > 0 &&
        selection.selected.length === selection.tickable.length;

    return (
        <div className="overflow-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        {selection !== null && (
                            <TableHead className="w-10">
                                <Checkbox
                                    aria-label="Select all rooms shown"
                                    checked={allTicked}
                                    indeterminate={
                                        selection.selected.length > 0 &&
                                        !allTicked
                                    }
                                    disabled={selection.tickable.length === 0}
                                    onCheckedChange={selection.onTickAll}
                                />
                            </TableHead>
                        )}
                        <TableHead>Room</TableHead>
                        <TableHead>Room type</TableHead>
                        <TableHead>Beds</TableHead>
                        <TableHead>
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {sections.length === 0 && emptyMessage !== null && (
                        <TableRow>
                            <TableCell
                                colSpan={columns}
                                className="h-24 text-center text-muted-foreground"
                            >
                                {emptyMessage}
                            </TableCell>
                        </TableRow>
                    )}
                    {sections.map((section) => {
                        const isCollapsed =
                            section.title !== null &&
                            collapsed.has(section.key);
                        const { group, position } = section;

                        return (
                            <Fragment key={section.key}>
                                {section.title !== null && (
                                    <TableRow className="bg-muted/50 hover:bg-muted/50">
                                        <TableCell colSpan={columns}>
                                            <div className="flex items-center justify-between gap-2">
                                                <button
                                                    type="button"
                                                    className="flex items-center gap-2 text-left font-medium"
                                                    aria-expanded={!isCollapsed}
                                                    onClick={() =>
                                                        onToggle(section.key)
                                                    }
                                                >
                                                    {isCollapsed ? (
                                                        <ChevronRight />
                                                    ) : (
                                                        <ChevronDown />
                                                    )}
                                                    {section.title}
                                                    <span className="font-normal text-muted-foreground">
                                                        · {section.rooms.length}
                                                    </span>
                                                </button>
                                                {group !== undefined &&
                                                    position !== undefined && (
                                                        <div className="flex items-center gap-1">
                                                            <Button
                                                                variant="ghost"
                                                                size="icon-sm"
                                                                aria-label={`Move ${group.name} up`}
                                                                disabled={
                                                                    position ===
                                                                    0
                                                                }
                                                                onClick={() =>
                                                                    onMove(
                                                                        position,
                                                                        position -
                                                                            1,
                                                                    )
                                                                }
                                                            >
                                                                <ArrowUp />
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon-sm"
                                                                aria-label={`Move ${group.name} down`}
                                                                disabled={
                                                                    position ===
                                                                    groupCount -
                                                                        1
                                                                }
                                                                onClick={() =>
                                                                    onMove(
                                                                        position,
                                                                        position +
                                                                            1,
                                                                    )
                                                                }
                                                            >
                                                                <ArrowDown />
                                                            </Button>
                                                            <div className="hidden gap-1 sm:flex">
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        onRename(
                                                                            group,
                                                                        )
                                                                    }
                                                                >
                                                                    Rename
                                                                </Button>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        onDelete(
                                                                            group,
                                                                        )
                                                                    }
                                                                >
                                                                    Delete
                                                                </Button>
                                                            </div>
                                                            <DropdownMenu>
                                                                <DropdownMenuTrigger
                                                                    render={
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="icon-sm"
                                                                            className="sm:hidden"
                                                                            aria-label={`More for ${group.name}`}
                                                                        />
                                                                    }
                                                                >
                                                                    <Ellipsis />
                                                                </DropdownMenuTrigger>
                                                                <DropdownMenuContent align="end">
                                                                    <DropdownMenuItem
                                                                        onClick={() =>
                                                                            onRename(
                                                                                group,
                                                                            )
                                                                        }
                                                                    >
                                                                        Rename
                                                                    </DropdownMenuItem>
                                                                    <DropdownMenuItem
                                                                        variant="destructive"
                                                                        onClick={() =>
                                                                            onDelete(
                                                                                group,
                                                                            )
                                                                        }
                                                                    >
                                                                        Delete
                                                                    </DropdownMenuItem>
                                                                </DropdownMenuContent>
                                                            </DropdownMenu>
                                                        </div>
                                                    )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                )}
                                {!isCollapsed && section.rooms.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={columns}
                                            className="pl-9 text-muted-foreground"
                                        >
                                            No rooms in this group.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {!isCollapsed &&
                                    section.rooms.map((room) => (
                                        <TableRow
                                            key={room.id}
                                            data-state={
                                                selection?.selected.includes(
                                                    room.id,
                                                )
                                                    ? 'selected'
                                                    : undefined
                                            }
                                        >
                                            {selection !== null && (
                                                <TableCell>
                                                    {!section.archived && (
                                                        <Checkbox
                                                            aria-label={`Select room ${room.number}`}
                                                            checked={selection.selected.includes(
                                                                room.id,
                                                            )}
                                                            onCheckedChange={(
                                                                checked,
                                                            ) =>
                                                                selection.onTick(
                                                                    room.id,
                                                                    checked,
                                                                )
                                                            }
                                                        />
                                                    )}
                                                </TableCell>
                                            )}
                                            <TableCell
                                                className={cn(
                                                    'font-medium',
                                                    section.title !== null &&
                                                        'pl-9',
                                                )}
                                            >
                                                {room.number}
                                                {section.archived && (
                                                    <>
                                                        {' '}
                                                        <Badge variant="secondary">
                                                            Archived
                                                        </Badge>
                                                    </>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {typeName(room.roomTypeId)}
                                            </TableCell>
                                            <TableCell>
                                                {!section.archived &&
                                                sellsBeds(room.roomTypeId)
                                                    ? bedCount(room.id)
                                                    : '—'}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex justify-end gap-1">
                                                    {section.archived ? (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                router.post(
                                                                    restore.url(
                                                                        {
                                                                            room: room.id,
                                                                        },
                                                                    ),
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Restore
                                                        </Button>
                                                    ) : (
                                                        <>
                                                            {sellsBeds(
                                                                room.roomTypeId,
                                                            ) && (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        onBeds(
                                                                            room,
                                                                        )
                                                                    }
                                                                >
                                                                    Beds
                                                                </Button>
                                                            )}
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() =>
                                                                    onEdit(room)
                                                                }
                                                            >
                                                                Edit
                                                            </Button>
                                                            <ConfirmAction
                                                                label="Archive"
                                                                title={`Archive room ${room.number}?`}
                                                                description="It leaves the active list and cannot be used for new work. You can restore it later."
                                                                method="post"
                                                                url={archive.url(
                                                                    {
                                                                        room: room.id,
                                                                    },
                                                                )}
                                                            />
                                                        </>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                            </Fragment>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}

/**
 * Change type and Archive for the ticked rooms. Ticks clear when the action finishes; the toast lists refused rooms.
 */
function RoomBulkActions({
    selected,
    roomTypes,
    onClear,
}: {
    selected: number[];
    roomTypes: RoomType[];
    onClear: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [archiving, setArchiving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const count = `${selected.length} ${selected.length === 1 ? 'room' : 'rooms'}`;

    const changeTo = (roomTypeId: number) =>
        router.post(
            changeType.url(),
            { room_ids: selected, room_type_id: roomTypeId },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setError(null);
                },
                onFinish: () => setProcessing(false),
                onSuccess: onClear,
                onError: (errors) => setError(Object.values(errors)[0] ?? null),
            },
        );

    return (
        <>
            <FieldError>{error}</FieldError>
            <BulkActionBar
                count={selected.length}
                onClear={onClear}
                stackOnMobile
                disabled={processing}
            >
                <DropdownMenu>
                    <DropdownMenuTrigger
                        render={
                            <Button
                                variant="outline"
                                disabled={processing}
                                focusableWhenDisabled
                            />
                        }
                    >
                        Change type
                        <ChevronDown data-icon="inline-end" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent side="top" align="end">
                        <DropdownMenuGroup>
                            {roomTypes.map((type) => (
                                <DropdownMenuItem
                                    key={type.id}
                                    onClick={() => changeTo(type.id)}
                                >
                                    {type.name}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
                <Button
                    variant="outline"
                    disabled={processing}
                    focusableWhenDisabled
                    onClick={() => setArchiving(true)}
                >
                    Archive
                </Button>
            </BulkActionBar>
            <ConfirmAction
                label="Archive"
                title={`Archive ${count}?`}
                description="They leave the active list and cannot be used for new work. You can restore them later."
                method="post"
                url={archiveMany.url()}
                data={{ room_ids: selected }}
                open={archiving}
                onOpenChange={setArchiving}
                onSuccess={onClear}
            />
        </>
    );
}

export default function RoomsIndex({
    rooms,
    roomGroups,
    roomTypes,
    beds,
    bedPrefixes,
    generateLimit,
    bedLimit,
    canManage,
}: Props) {
    // `undefined` = closed, `null` = creating, a value = editing.
    const [editingRoom, setEditingRoom] = useState<Room | null | undefined>(
        undefined,
    );
    const [editingGroup, setEditingGroup] = useState<
        RoomGroup | null | undefined
    >(undefined);
    const [generating, setGenerating] = useState(false);
    const [generatingBeds, setGeneratingBeds] = useState(false);
    const [bedsRoom, setBedsRoom] = useState<Room | null>(null);
    const [typeFilter, setTypeFilter] = useState('');
    // '' = all groups, 'none' = ungrouped rooms, otherwise a group id.
    const [groupFilter, setGroupFilter] = useState('');
    // 'active' (default), 'archived', or '' for both.
    const [statusFilter, setStatusFilter] = useState('active');
    // Group rows that are closed. Archived starts closed; nothing is remembered across reloads.
    const [collapsed, setCollapsed] = useState<Set<string>>(
        () => new Set(['archived']),
    );
    const [deletingGroup, setDeletingGroup] = useState<RoomGroup | null>(null);
    const [ticked, setTicked] = useState<number[]>([]);

    const activeTypes = roomTypes.filter((type) => !type.archived);
    const typeName = (id: number) =>
        roomTypes.find((type) => type.id === id)?.name ?? '';
    const sellsBeds = (id: number) =>
        roomTypes.find((type) => type.id === id)?.sellUnit === 'bed';
    const bedCount = (roomId: number) =>
        beds.filter((bed) => bed.roomId === roomId && !bed.archived).length;
    const hasDormRooms = rooms.some(
        (room) => !room.archived && sellsBeds(room.roomTypeId),
    );
    const typeOptions = activeTypes.map((type) => ({
        value: String(type.id),
        label: type.name,
    }));
    const groupOptions = roomGroups.map((group) => ({
        value: String(group.id),
        label: group.name,
    }));

    const statusOptions = [
        { value: 'active', label: 'Active' },
        { value: 'archived', label: 'Archived' },
    ];
    const groupFilterOptions = [
        ...groupOptions,
        { value: 'none', label: 'Ungrouped' },
    ];

    const matchesFilters = (room: Room) =>
        (typeFilter === '' || String(room.roomTypeId) === typeFilter) &&
        (groupFilter === '' ||
            (groupFilter === 'none'
                ? room.roomGroupId === null
                : String(room.roomGroupId) === groupFilter));
    const showActive = statusFilter !== 'archived';
    const showArchived = statusFilter !== 'active';

    const activeRooms = rooms.filter(
        (room) => !room.archived && matchesFilters(room),
    );
    const archivedRooms = rooms.filter(
        (room) => room.archived && matchesFilters(room),
    );
    const ungrouped = activeRooms.filter((room) => room.roomGroupId === null);

    const move = (from: number, to: number) => {
        const order = roomGroups.map((group) => group.id);
        order.splice(to, 0, ...order.splice(from, 1));

        router.put(reorder.url(), { order }, { preserveScroll: true });
    };

    const noTypes = activeTypes.length === 0;
    const noRooms = rooms.filter((room) => !room.archived).length === 0;
    const showList = !noTypes && !(noRooms && roomGroups.length === 0);

    const sections: RoomSection[] = [];

    if (showActive) {
        roomGroups.forEach((group, position) => {
            const groupRooms = activeRooms.filter(
                (room) => room.roomGroupId === group.id,
            );

            if (
                (groupFilter !== '' && groupFilter !== String(group.id)) ||
                (typeFilter !== '' && groupRooms.length === 0)
            ) {
                return;
            }

            sections.push({
                key: `group-${group.id}`,
                title: group.name,
                rooms: groupRooms,
                group,
                position,
            });
        });

        if (ungrouped.length > 0) {
            sections.push({
                key: 'ungrouped',
                title: roomGroups.length > 0 ? 'Ungrouped' : null,
                rooms: ungrouped,
            });
        }
    }

    if (showArchived && archivedRooms.length > 0) {
        sections.push({
            key: 'archived',
            title: 'Archived',
            rooms: archivedRooms,
            archived: true,
        });
    }

    // Without the list (no room types, or no rooms yet), only archived rooms can still be shown.
    const tableSections = showList
        ? sections
        : sections.filter((section) => section.archived);

    const tickable = canManage
        ? tableSections
              .filter(
                  (section) =>
                      !section.archived &&
                      !(section.title !== null && collapsed.has(section.key)),
              )
              .flatMap((section) => section.rooms.map((room) => room.id))
        : [];
    const selected = ticked.filter((id) => tickable.includes(id));

    // A room hidden by a collapsed group or a filter, or archived, is unticked (adjusting state while rendering).
    if (selected.length !== ticked.length) {
        setTicked(selected);
    }

    const selection: RoomSelection | null = canManage
        ? {
              selected,
              tickable,
              onTick: (roomId, checked) =>
                  setTicked((current) =>
                      checked
                          ? [...current, roomId]
                          : current.filter((id) => id !== roomId),
                  ),
              onTickAll: (checked) => setTicked(checked ? tickable : []),
          }
        : null;

    const toggle = (key: string) =>
        setCollapsed((current) => {
            const next = new Set(current);

            if (!next.delete(key)) {
                next.add(key);
            }

            return next;
        });

    const changeStatusFilter = (value: string) => {
        setStatusFilter(value);

        if (value === 'archived') {
            setCollapsed((current) => {
                const next = new Set(current);
                next.delete('archived');

                return next;
            });
        }
    };

    return (
        <>
            <Head title="Rooms" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Rooms"
                    description="The rooms you sell, grouped by floor, wing or block."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                onClick={() => setEditingGroup(null)}
                            >
                                <FolderPlus data-icon="inline-start" />
                                New group
                            </Button>
                            <Button
                                variant="outline"
                                disabled={noTypes}
                                onClick={() => setGenerating(true)}
                            >
                                <Wand2 data-icon="inline-start" />
                                Generate rooms
                            </Button>
                            {hasDormRooms && (
                                <Button
                                    variant="outline"
                                    onClick={() => setGeneratingBeds(true)}
                                >
                                    <BedDouble data-icon="inline-start" />
                                    Add beds
                                </Button>
                            )}
                            <Button
                                disabled={noTypes}
                                onClick={() => setEditingRoom(null)}
                            >
                                <Plus data-icon="inline-start" />
                                New room
                            </Button>
                        </div>
                    }
                />

                {noTypes ? (
                    <EmptyState
                        icon={DoorOpen}
                        title="Add a room type first"
                        description="Every room belongs to a room type. Create one in Room types, then come back."
                    />
                ) : !showList ? (
                    <EmptyState
                        icon={DoorOpen}
                        title="No rooms yet"
                        description="Generate many rooms at once, or add them one by one."
                        action={
                            <Button onClick={() => setGenerating(true)}>
                                <Wand2 data-icon="inline-start" />
                                Generate rooms
                            </Button>
                        }
                    />
                ) : (
                    <div className="flex flex-wrap justify-end gap-2">
                        <EnumSelect
                            aria-label="Filter by status"
                            className="w-40"
                            options={statusOptions}
                            allLabel="All statuses"
                            value={statusFilter}
                            onValueChange={changeStatusFilter}
                        />
                        <EnumSelect
                            aria-label="Filter by room group"
                            className="w-48"
                            options={groupFilterOptions}
                            allLabel="All groups"
                            value={groupFilter}
                            onValueChange={setGroupFilter}
                        />
                        <EnumSelect
                            aria-label="Filter by room type"
                            className="w-48"
                            options={typeOptions}
                            allLabel="All room types"
                            value={typeFilter}
                            onValueChange={setTypeFilter}
                        />
                    </div>
                )}

                {(showList || tableSections.length > 0) && (
                    <RoomTable
                        sections={tableSections}
                        groupCount={roomGroups.length}
                        collapsed={collapsed}
                        emptyMessage={
                            showList ? 'No rooms match these filters.' : null
                        }
                        typeName={typeName}
                        sellsBeds={sellsBeds}
                        bedCount={bedCount}
                        onToggle={toggle}
                        onMove={move}
                        onRename={setEditingGroup}
                        onDelete={setDeletingGroup}
                        onEdit={setEditingRoom}
                        onBeds={setBedsRoom}
                        selection={selection}
                    />
                )}

                {selected.length > 0 && (
                    <RoomBulkActions
                        selected={selected}
                        roomTypes={activeTypes}
                        onClear={() => setTicked([])}
                    />
                )}
            </div>

            <Dialog
                open={editingRoom !== undefined}
                onOpenChange={(open) => !open && setEditingRoom(undefined)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {editingRoom
                                ? `Edit room ${editingRoom.number}`
                                : 'New room'}
                        </DialogTitle>
                        <DialogDescription>
                            The number is the label your team uses for the room.
                        </DialogDescription>
                    </DialogHeader>
                    {editingRoom !== undefined && (
                        <RoomFormDialog
                            key={editingRoom?.id ?? 'new'}
                            room={editingRoom}
                            roomTypes={roomTypes}
                            roomGroups={roomGroups}
                            onClose={() => setEditingRoom(undefined)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={editingGroup !== undefined}
                onOpenChange={(open) => !open && setEditingGroup(undefined)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {editingGroup
                                ? `Rename ${editingGroup.name}`
                                : 'New room group'}
                        </DialogTitle>
                        <DialogDescription>
                            A group can be a floor, a wing or a block.
                        </DialogDescription>
                    </DialogHeader>
                    {editingGroup !== undefined && (
                        <RoomGroupFormDialog
                            key={editingGroup?.id ?? 'new'}
                            group={editingGroup}
                            onClose={() => setEditingGroup(undefined)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <ConfirmAction
                label="Delete"
                title={`Delete ${deletingGroup?.name ?? 'group'}?`}
                description="Only a group without active rooms can be deleted."
                method="delete"
                url={
                    deletingGroup
                        ? destroy.url({ roomGroup: deletingGroup.id })
                        : ''
                }
                open={deletingGroup !== null}
                onOpenChange={(open) => !open && setDeletingGroup(null)}
            />

            <BedsSheet
                room={bedsRoom}
                beds={beds}
                prefixes={bedPrefixes}
                limit={bedLimit}
                onClose={() => setBedsRoom(null)}
            />

            <GenerateAllBedsSheet
                open={generatingBeds}
                onOpenChange={setGeneratingBeds}
                limit={bedLimit}
            />

            <GenerateRoomsSheet
                open={generating}
                onOpenChange={setGenerating}
                roomTypes={typeOptions}
                roomGroups={groupOptions}
                limit={generateLimit}
            />
        </>
    );
}

RoomsIndex.layout = {
    breadcrumbs: [{ title: 'Rooms', href: index() }],
};
