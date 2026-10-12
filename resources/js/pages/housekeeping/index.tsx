import { Head, Link, router } from '@inertiajs/react';
import {
    Ban,
    BedDouble,
    ChevronDown,
    ChevronRight,
    CircleCheck,
    MoonStar,
    MoreHorizontal,
    Star,
    StarOff,
    Wrench,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { index } from '@/routes/housekeeping';
import {
    assignee,
    dnd,
    maintenance,
    priority,
    remark,
    status,
} from '@/routes/housekeeping/rooms';
import { index as roomBlocks } from '@/routes/front-desk/room-blocks';

type Room = App.Data.Housekeeping.HousekeepingRoomData;
type Staff = App.Data.Housekeeping.HousekeepingStaffData;
type Badge = App.Data.Shared.BadgeData;
type View = 'all' | 'group' | 'type';
type Movement = '' | 'arrivals' | 'departures' | 'stayovers';

type Props = {
    date: string;
    rooms: Room[];
    roomGroups: App.Data.Rooms.RoomGroupData[];
    statuses: Badge[];
    staff: Staff[];
    can: { update: boolean; block: boolean };
};

const OUT_OF_SERVICE = 'out_of_service';

function matchesMovement(room: Room, movement: Movement): boolean {
    switch (movement) {
        case 'arrivals':
            return room.arrivalTime !== null;
        case 'departures':
            return room.departureTime !== null;
        case 'stayovers':
            return room.isStayOver;
        default:
            return true;
    }
}

function StayCell({
    date,
    time,
}: {
    date: string | null;
    time: string | null;
}) {
    if (date === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span className="inline-flex items-baseline gap-1.5">
            <DateText date={date} />
            <span className="text-muted-foreground tabular-nums">{time}</span>
        </span>
    );
}

export default function HousekeepingIndex({
    date,
    rooms,
    roomGroups,
    statuses,
    staff,
    can,
}: Props) {
    const [view, setView] = useState<View>('group');
    const [statusFilter, setStatusFilter] = useState('');
    const [assignedFilter, setAssignedFilter] = useState('');
    const [movement, setMovement] = useState<Movement>('');
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());

    const shown = useMemo(
        () =>
            rooms.filter(
                (room) =>
                    (statusFilter === '' ||
                        room.status.value === statusFilter) &&
                    (assignedFilter === '' ||
                        (assignedFilter === 'none'
                            ? room.assignedTo === null
                            : String(room.assignedTo) === assignedFilter)) &&
                    matchesMovement(room, movement),
            ),
        [rooms, statusFilter, assignedFilter, movement],
    );

    const groups = useMemo(() => {
        if (view === 'all') {
            return [{ key: 'all', name: null, rooms: shown }];
        }

        const keyOf = (room: Room) =>
            view === 'group'
                ? `g${room.roomGroupId ?? 0}`
                : `t${room.roomTypeId}`;
        const nameOf = (room: Room) =>
            view === 'group'
                ? (room.roomGroupName ?? 'No room group')
                : room.roomTypeName;
        const order =
            view === 'group'
                ? new Map(
                      roomGroups.map((group, position) => [
                          `g${group.id}`,
                          position,
                      ]),
                  )
                : new Map<string, number>();
        const byKey = new Map<
            string,
            { key: string; name: string; rooms: Room[] }
        >();

        for (const room of shown) {
            const key = keyOf(room);
            const entry = byKey.get(key) ?? {
                key,
                name: nameOf(room),
                rooms: [],
            };
            entry.rooms.push(room);
            byKey.set(key, entry);
        }

        return [...byKey.values()].sort(
            (a, b) => (order.get(a.key) ?? 999) - (order.get(b.key) ?? 999),
        );
    }, [shown, view, roomGroups]);

    const statusOptions = [
        ...statuses.map((item) => ({ value: item.value, label: item.label })),
        { value: OUT_OF_SERVICE, label: 'Out of service' },
    ];
    const staffOptions = [
        { value: 'none', label: 'Unassigned' },
        ...staff.map((member) => ({
            value: String(member.id),
            label: member.name,
        })),
    ];

    function send(room: Room, value: string, badge: Badge) {
        router
            .optimistic<{ rooms: Room[] }>((props) => ({
                rooms: props.rooms.map((item) =>
                    item.id === room.id
                        ? {
                              ...item,
                              status: badge,
                              moves: [],
                              reason: value === 'dirty' ? item.reason : null,
                          }
                        : item,
                ),
            }))
            .put(
                status.url({ room: room.id }),
                { status: value },
                { preserveScroll: true },
            );
    }

    function assign(room: Room, value: string) {
        const next = value === '' || value === 'none' ? null : Number(value);
        const member = staff.find((item) => item.id === next);

        router
            .optimistic<{ rooms: Room[] }>((props) => ({
                rooms: props.rooms.map((item) =>
                    item.id === room.id
                        ? {
                              ...item,
                              assignedTo: next,
                              assignedName: member?.name ?? null,
                          }
                        : item,
                ),
            }))
            .put(
                assignee.url({ room: room.id }),
                { assigned_to: next },
                { preserveScroll: true },
            );
    }

    function togglePriority(room: Room) {
        router
            .optimistic<{ rooms: Room[] }>((props) => ({
                rooms: props.rooms.map((item) =>
                    item.id === room.id
                        ? { ...item, isPriority: !room.isPriority }
                        : item,
                ),
            }))
            .put(
                priority.url({ room: room.id }),
                { priority: !room.isPriority, note: null },
                { preserveScroll: true },
            );
    }

    function toggleDnd(room: Room) {
        router
            .optimistic<{ rooms: Room[] }>((props) => ({
                rooms: props.rooms.map((item) =>
                    item.id === room.id
                        ? { ...item, doNotDisturb: !room.doNotDisturb }
                        : item,
                ),
            }))
            .put(
                dnd.url({ room: room.id }),
                { dnd: !room.doNotDisturb },
                { preserveScroll: true },
            );
    }

    function saveRemark(room: Room, value: string) {
        const next = value.trim() === '' ? null : value.trim();

        if (next === room.remark) {
            return;
        }

        router
            .optimistic<{ rooms: Room[] }>((props) => ({
                rooms: props.rooms.map((item) =>
                    item.id === room.id ? { ...item, remark: next } : item,
                ),
            }))
            .put(
                remark.url({ room: room.id }),
                { remark: next },
                { preserveScroll: true },
            );
    }

    function setMaintenance(room: Room, needsMaintenance: boolean) {
        router
            .optimistic<{ rooms: Room[] }>((props) => ({
                rooms: props.rooms.map((item) =>
                    item.id === room.id
                        ? {
                              ...item,
                              needsMaintenance,
                              canDismissMaintenance:
                                  needsMaintenance && can.block,
                          }
                        : item,
                ),
            }))
            .put(
                maintenance.url({ room: room.id }),
                { needs_maintenance: needsMaintenance },
                { preserveScroll: true },
            );
    }

    function toggleGroup(key: string) {
        setCollapsed((current) => {
            const next = new Set(current);

            if (!next.delete(key)) {
                next.add(key);
            }

            return next;
        });
    }

    const dirtyBadge = statuses.find((item) => item.value === 'dirty');

    return (
        <>
            <Head title="Housekeeping" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Housekeeping"
                    description={
                        <>
                            {rooms.length} rooms on <DateText date={date} />
                        </>
                    }
                />

                {rooms.length === 0 ? (
                    <EmptyState
                        icon={BedDouble}
                        title="No rooms yet"
                        description="Add rooms in Settings and they appear here."
                    />
                ) : (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            <ToggleGroup
                                variant="outline"
                                spacing={0}
                                value={[view]}
                                onValueChange={(value) =>
                                    value[0] && setView(value[0] as View)
                                }
                                aria-label="View"
                            >
                                <ToggleGroupItem value="all">
                                    All
                                </ToggleGroupItem>
                                <ToggleGroupItem value="group">
                                    By room group
                                </ToggleGroupItem>
                                <ToggleGroupItem value="type">
                                    By room type
                                </ToggleGroupItem>
                            </ToggleGroup>
                            <EnumSelect
                                options={statusOptions}
                                allLabel="All statuses"
                                value={statusFilter}
                                onValueChange={setStatusFilter}
                                aria-label="Status"
                            />
                            {can.update && (
                                <EnumSelect
                                    options={staffOptions}
                                    allLabel="Anyone"
                                    value={assignedFilter}
                                    onValueChange={setAssignedFilter}
                                    aria-label="Assigned to"
                                />
                            )}
                            <EnumSelect
                                options={[
                                    { value: 'arrivals', label: 'Arrivals' },
                                    {
                                        value: 'departures',
                                        label: 'Departures',
                                    },
                                    { value: 'stayovers', label: 'Stay-overs' },
                                ]}
                                allLabel="All rooms"
                                value={movement}
                                onValueChange={(value) =>
                                    setMovement(value as Movement)
                                }
                                aria-label="Movement today"
                            />
                        </div>

                        {shown.length === 0 ? (
                            <EmptyState
                                title="No rooms match"
                                description="Change the filters to see more rooms."
                            />
                        ) : (
                            <div className="overflow-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Room</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Assigned</TableHead>
                                            <TableHead>Guest</TableHead>
                                            <TableHead>Arrival</TableHead>
                                            <TableHead>Departure</TableHead>
                                            <TableHead>Notes</TableHead>
                                            <TableHead className="w-10">
                                                <span className="sr-only">
                                                    Row actions
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {groups.map((group) => (
                                            <GroupRows
                                                key={group.key}
                                                name={group.name}
                                                groupKey={group.key}
                                                rooms={group.rooms}
                                                collapsed={collapsed.has(
                                                    group.key,
                                                )}
                                                onToggle={toggleGroup}
                                                dirtyLabel={dirtyBadge?.label}
                                                can={can}
                                                staffOptions={staffOptions}
                                                onMove={(room, move) =>
                                                    send(room, move.value, move)
                                                }
                                                onAssign={assign}
                                                onPriority={togglePriority}
                                                onDnd={toggleDnd}
                                                onRemark={saveRemark}
                                                onMaintenance={setMaintenance}
                                            />
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function GroupRows({
    name,
    groupKey,
    rooms,
    collapsed,
    onToggle,
    dirtyLabel,
    can,
    staffOptions,
    onMove,
    onAssign,
    onPriority,
    onDnd,
    onRemark,
    onMaintenance,
}: {
    name: string | null;
    groupKey: string;
    rooms: Room[];
    collapsed: boolean;
    onToggle: (key: string) => void;
    dirtyLabel?: string;
    can: Props['can'];
    staffOptions: { value: string; label: string }[];
    onMove: (room: Room, move: Badge) => void;
    onAssign: (room: Room, value: string) => void;
    onPriority: (room: Room) => void;
    onDnd: (room: Room) => void;
    onRemark: (room: Room, value: string) => void;
    onMaintenance: (room: Room, needsMaintenance: boolean) => void;
}) {
    const dirty = rooms.filter((room) => room.status.value === 'dirty').length;

    return (
        <>
            {name !== null && (
                <TableRow className="bg-muted/50 hover:bg-muted/50">
                    <TableCell colSpan={8}>
                        <button
                            type="button"
                            className="flex w-full items-center gap-2 text-left font-medium"
                            aria-expanded={!collapsed}
                            onClick={() => onToggle(groupKey)}
                        >
                            {collapsed ? <ChevronRight /> : <ChevronDown />}
                            {name}
                            <span className="text-sm font-normal text-muted-foreground">
                                {rooms.length} rooms
                                {dirtyLabel
                                    ? `, ${dirty} ${dirtyLabel.toLowerCase()}`
                                    : ''}
                            </span>
                        </button>
                    </TableCell>
                </TableRow>
            )}
            {!collapsed &&
                rooms.map((room) => (
                    <TableRow key={room.id}>
                        <TableCell className="font-medium tabular-nums">
                            {room.number}
                            <div className="text-xs font-normal text-muted-foreground">
                                {room.roomTypeName}
                            </div>
                        </TableCell>
                        <TableCell>
                            {room.moves.length > 0 ? (
                                <DropdownMenu>
                                    <DropdownMenuTrigger
                                        render={
                                            <button
                                                type="button"
                                                className="inline-flex items-center gap-1 rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                aria-label={`Change status of room ${room.number}, now ${room.status.label}`}
                                            />
                                        }
                                    >
                                        <StatusBadge
                                            status={room.status}
                                            size="lg"
                                        />
                                        <ChevronDown
                                            aria-hidden
                                            className="size-3.5 text-muted-foreground"
                                        />
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="start">
                                        {room.moves.map((move) => (
                                            <DropdownMenuItem
                                                key={move.value}
                                                aria-label={`Change to ${move.label}`}
                                                onClick={() =>
                                                    onMove(room, move)
                                                }
                                            >
                                                <StatusBadge
                                                    status={move}
                                                    size="lg"
                                                />
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            ) : (
                                <StatusBadge status={room.status} size="lg" />
                            )}
                            {room.outOfService && (
                                <div className="mt-1 text-xs text-muted-foreground">
                                    {room.outOfService.reasonLabel} until{' '}
                                    <DateText date={room.outOfService.endsOn} />
                                </div>
                            )}
                        </TableCell>
                        <TableCell>
                            {can.update ? (
                                <EnumSelect
                                    options={staffOptions}
                                    allLabel="Unassigned"
                                    value={
                                        room.assignedTo === null
                                            ? ''
                                            : String(room.assignedTo)
                                    }
                                    onValueChange={(value) =>
                                        onAssign(room, value)
                                    }
                                    aria-label={`Assign room ${room.number}`}
                                />
                            ) : (
                                (room.assignedName ?? (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                ))
                            )}
                        </TableCell>
                        <TableCell>
                            {room.guest ?? (
                                <span className="text-muted-foreground">—</span>
                            )}
                        </TableCell>
                        <TableCell>
                            <StayCell
                                date={room.arrivalDate}
                                time={room.arrivalTime}
                            />
                        </TableCell>
                        <TableCell>
                            <StayCell
                                date={room.departureDate}
                                time={room.departureTime}
                            />
                        </TableCell>
                        <TableCell>
                            <div className="flex flex-col gap-1 text-sm">
                                <div className="flex flex-wrap items-center gap-2">
                                    {room.isPriority && (
                                        <span className="inline-flex items-center gap-1 text-warning">
                                            <Star
                                                aria-hidden
                                                className="size-3.5"
                                            />
                                            Priority
                                        </span>
                                    )}
                                    {room.doNotDisturb && (
                                        <span className="inline-flex items-center gap-1">
                                            <MoonStar
                                                aria-hidden
                                                className="size-3.5"
                                            />
                                            Do not disturb
                                        </span>
                                    )}
                                    {room.needsMaintenance && (
                                        <StatusBadge
                                            status={{
                                                value: 'needs_maintenance',
                                                label: 'Needs maintenance',
                                                tone: 'warning',
                                            }}
                                            icon={Wrench}
                                        />
                                    )}
                                </div>
                                {can.update ? (
                                    <Input
                                        key={`${room.id}:${room.remark ?? ''}`}
                                        defaultValue={room.remark ?? ''}
                                        maxLength={500}
                                        placeholder="Add remark…"
                                        aria-label={`Remark for room ${room.number}`}
                                        onBlur={(event) =>
                                            onRemark(room, event.target.value)
                                        }
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter') {
                                                event.currentTarget.blur();
                                            }

                                            if (event.key === 'Escape') {
                                                event.currentTarget.value =
                                                    room.remark ?? '';
                                                event.currentTarget.blur();
                                            }
                                        }}
                                    />
                                ) : (
                                    room.remark && <span>{room.remark}</span>
                                )}
                                {room.outOfService?.notes && (
                                    <span className="text-muted-foreground">
                                        {room.outOfService.notes}
                                    </span>
                                )}
                                {room.reason && (
                                    <span className="text-muted-foreground">
                                        {room.reason}
                                    </span>
                                )}
                                {room.bedBlocks.map((block) => (
                                    <span
                                        key={block.bedLabel}
                                        className="text-muted-foreground"
                                    >
                                        Bed {block.bedLabel}:{' '}
                                        {block.reasonLabel} until{' '}
                                        <DateText date={block.endsOn} />
                                    </span>
                                ))}
                            </div>
                        </TableCell>
                        <TableCell>
                            {(can.update || can.block) && (
                                <DropdownMenu>
                                    <DropdownMenuTrigger
                                        render={
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Actions for room ${room.number}`}
                                            />
                                        }
                                    >
                                        <MoreHorizontal />
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        className="min-w-56"
                                    >
                                        {can.update && (
                                            <>
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        onPriority(room)
                                                    }
                                                >
                                                    {room.isPriority ? (
                                                        <StarOff />
                                                    ) : (
                                                        <Star />
                                                    )}
                                                    {room.isPriority
                                                        ? 'Clear priority'
                                                        : 'Set priority'}
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    onClick={() => onDnd(room)}
                                                >
                                                    <MoonStar />
                                                    {room.doNotDisturb
                                                        ? 'Clear do not disturb'
                                                        : 'Do not disturb today'}
                                                </DropdownMenuItem>
                                                {!room.needsMaintenance && (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            onMaintenance(
                                                                room,
                                                                true,
                                                            )
                                                        }
                                                    >
                                                        <Wrench />
                                                        Needs maintenance
                                                    </DropdownMenuItem>
                                                )}
                                            </>
                                        )}
                                        {room.canDismissMaintenance && (
                                            <DropdownMenuItem
                                                onClick={() =>
                                                    onMaintenance(room, false)
                                                }
                                            >
                                                <CircleCheck />
                                                Maintenance fixed
                                            </DropdownMenuItem>
                                        )}
                                        {can.block && (
                                            <>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    render={
                                                        <Link
                                                            href={roomBlocks()}
                                                        />
                                                    }
                                                >
                                                    <Ban />
                                                    Block room…
                                                </DropdownMenuItem>
                                            </>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                        </TableCell>
                    </TableRow>
                ))}
        </>
    );
}

HousekeepingIndex.layout = {
    breadcrumbs: [{ title: 'Housekeeping', href: index() }],
};
