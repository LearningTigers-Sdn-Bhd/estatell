import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, BedDouble, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect, type EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { PageHeader } from '@/components/pms/page-header';
import { PaxCount } from '@/components/pms/pax-count';
import { ArchiveRoomTypeDialog } from '@/components/pms/rooms/archive-room-type-dialog';
import { GuestsIncludedLabel } from '@/components/pms/rooms/room-type-details-form';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    edit,
    index,
    reorder,
    restore,
    store,
} from '@/routes/settings/room-types';

type RoomType = App.Data.Rooms.RoomTypeData;

/**
 * Asks for the name only (and Sold by while beds are on). The other fields send their defaults, and create opens the
 * new room type's Details tab, where staff set the rest.
 */
function NewRoomTypeDialog({
    open,
    onOpenChange,
    sellUnits,
    bedsOn,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    sellUnits: EnumOption[];
    bedsOn: boolean;
}) {
    const form = useForm({
        name: '',
        sell_unit: 'room',
        max_adults: '2',
        max_children: '0',
        base_occupancy: '2',
        smoking_allowed: false,
        pets_allowed: false,
        terms_document_id: '',
    });

    const changeOpen = (next: boolean) => {
        if (!next) {
            form.reset();
            form.clearErrors();
        }

        onOpenChange(next);
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={changeOpen}
            title="New room type"
            description="Name it here. You set guests and terms on the next page."
            errors={form.errors}
            processing={form.processing}
            submitLabel="Create room type"
            onSubmit={() => form.post(store.url())}
        >
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="new-room-type-name">Name</FieldLabel>
                <Input
                    id="new-room-type-name"
                    placeholder="e.g. Deluxe King"
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
            {bedsOn && (
                <Field data-invalid={!!form.errors.sell_unit}>
                    <FieldLabel htmlFor="new-room-type-sell-unit">
                        Sold by
                    </FieldLabel>
                    <EnumSelect
                        id="new-room-type-sell-unit"
                        name="sell_unit"
                        options={sellUnits}
                        value={form.data.sell_unit}
                        onValueChange={(value) =>
                            form.setData('sell_unit', value)
                        }
                        aria-invalid={!!form.errors.sell_unit}
                    />
                    <FieldDescription>
                        A Bed room type sells one bed at a time, for dorms.
                    </FieldDescription>
                    <FieldError>{form.errors.sell_unit}</FieldError>
                </Field>
            )}
        </FormOverlay>
    );
}

function RoomTypeTable({
    roomTypes,
    showSoldBy,
    sellUnitLabel,
    canManage,
    onMove,
}: {
    roomTypes: RoomType[];
    showSoldBy: boolean;
    sellUnitLabel: (value: string) => string;
    canManage: boolean;
    onMove: (from: number, to: number) => void;
}) {
    const [archiving, setArchiving] = useState<RoomType | null>(null);
    const [savingIds, setSavingIds] = useState<Set<number>>(new Set());
    const available = roomTypes.filter((roomType) => !roomType.archived);
    const positions = new Map(
        available.map((roomType, position) => [roomType.id, position]),
    );

    const setSaving = (id: number, saving: boolean) => {
        setSavingIds((current) => {
            const next = new Set(current);

            if (saving) {
                next.add(id);
            } else {
                next.delete(id);
            }

            return next;
        });
    };

    // Off asks first: archiving can queue a channel removal instead. On restores at once; a name clash shows a toast.
    const changeAvailable = (roomType: RoomType, availableNow: boolean) => {
        if (!availableNow) {
            setArchiving(roomType);

            return;
        }

        router.post(
            restore.url({ roomType: roomType.id }),
            {},
            {
                preserveScroll: true,
                onStart: () => setSaving(roomType.id, true),
                onFinish: () => setSaving(roomType.id, false),
            },
        );
    };

    return (
        <div className="overflow-auto rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Available</TableHead>
                        <TableHead>Room type</TableHead>
                        {showSoldBy && <TableHead>Sold by</TableHead>}
                        <TableHead className="text-right">
                            <GuestsIncludedLabel />
                        </TableHead>
                        <TableHead className="text-right">Capacity</TableHead>
                        <TableHead>
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {roomTypes.map((roomType) => {
                        const position = positions.get(roomType.id);

                        return (
                            <TableRow key={roomType.id}>
                                <TableCell>
                                    <Switch
                                        size="sm"
                                        checked={!roomType.archived}
                                        disabled={
                                            !canManage ||
                                            savingIds.has(roomType.id)
                                        }
                                        aria-label={`Make ${roomType.name} ${roomType.archived ? 'available' : 'unavailable'}`}
                                        onCheckedChange={(checked) =>
                                            changeAvailable(roomType, checked)
                                        }
                                    />
                                </TableCell>
                                <TableCell>
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <span className="flex items-center gap-2 font-medium">
                                            {roomType.name}
                                            {roomType.smokingAllowed && (
                                                <Badge variant="outline">
                                                    Smoking
                                                </Badge>
                                            )}
                                            {roomType.petsAllowed && (
                                                <Badge variant="outline">
                                                    Pets
                                                </Badge>
                                            )}
                                        </span>
                                        {roomType.description && (
                                            <span className="max-w-xs truncate text-xs text-muted-foreground">
                                                {roomType.description}
                                            </span>
                                        )}
                                    </div>
                                </TableCell>
                                {showSoldBy && (
                                    <TableCell>
                                        {sellUnitLabel(roomType.sellUnit)}
                                    </TableCell>
                                )}
                                <TableCell className="text-right tabular-nums">
                                    {roomType.baseOccupancy}
                                </TableCell>
                                <TableCell>
                                    <span className="flex justify-end">
                                        <PaxCount
                                            stay={{
                                                adults: roomType.maxAdults,
                                                children: roomType.maxChildren,
                                            }}
                                        />
                                    </span>
                                </TableCell>
                                <TableCell>
                                    <div className="flex justify-end gap-1">
                                        {canManage &&
                                            position !== undefined && (
                                                <>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`Move ${roomType.name} up`}
                                                        disabled={
                                                            position === 0
                                                        }
                                                        onClick={() =>
                                                            onMove(
                                                                position,
                                                                position - 1,
                                                            )
                                                        }
                                                    >
                                                        <ArrowUp />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`Move ${roomType.name} down`}
                                                        disabled={
                                                            position ===
                                                            available.length - 1
                                                        }
                                                        onClick={() =>
                                                            onMove(
                                                                position,
                                                                position + 1,
                                                            )
                                                        }
                                                    >
                                                        <ArrowDown />
                                                    </Button>
                                                </>
                                            )}
                                        <Link
                                            href={edit({
                                                roomType: roomType.id,
                                            })}
                                            className={buttonVariants({
                                                variant: 'ghost',
                                                size: 'sm',
                                            })}
                                        >
                                            {canManage ? 'Edit' : 'View'}
                                        </Link>
                                    </div>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
            {archiving && (
                <ArchiveRoomTypeDialog
                    roomType={archiving}
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setArchiving(null);
                        }
                    }}
                    onProcessingChange={(saving) =>
                        setSaving(archiving.id, saving)
                    }
                />
            )}
        </div>
    );
}

/**
 * Settings › Room types. One table like Charge items: the Available switch archives (after a confirm) or restores,
 * archived room types sort last, and search filters in the browser. Staff with only `rooms.view` see the list with the
 * switch disabled and no New or reorder buttons.
 */
export default function RoomTypesIndex({
    roomTypes,
    sellUnits,
    bedsOn,
    canManage,
}: {
    roomTypes: RoomType[];
    sellUnits: EnumOption[];
    bedsOn: boolean;
    canManage: boolean;
}) {
    const [creating, setCreating] = useState(false);
    const [query, setQuery] = useState('');
    const filtered = useMemo(() => {
        const normalized = query.trim().toLocaleLowerCase();

        if (!normalized) {
            return roomTypes;
        }

        return roomTypes.filter((roomType) =>
            [roomType.name, roomType.description ?? ''].some((value) =>
                value.toLocaleLowerCase().includes(normalized),
            ),
        );
    }, [roomTypes, query]);
    // Sold by shows while beds are on, or while a room type still sells beds.
    const showSoldBy =
        bedsOn || roomTypes.some((roomType) => roomType.sellUnit === 'bed');
    const sellUnitLabel = (value: string) =>
        sellUnits.find((option) => option.value === value)?.label ?? value;

    // Positions are among the shown available rows; the move is applied to the full order.
    const move = (from: number, to: number) => {
        const shown = filtered.filter((roomType) => !roomType.archived);
        const order = roomTypes
            .filter((roomType) => !roomType.archived)
            .map((roomType) => roomType.id);
        const movingId = shown[from]?.id;
        const targetId = shown[to]?.id;

        if (movingId === undefined || targetId === undefined) {
            return;
        }

        order.splice(
            order.indexOf(targetId),
            0,
            ...order.splice(order.indexOf(movingId), 1),
        );

        router.put(reorder.url(), { order }, { preserveScroll: true });
    };

    const newButton = canManage ? (
        <Button onClick={() => setCreating(true)}>
            <Plus data-icon="inline-start" />
            New room type
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Room types" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Room types"
                    description="What you sell: a room or a bed. Prices are set on rates, not here."
                    actions={newButton}
                />

                {roomTypes.length === 0 ? (
                    <EmptyState
                        icon={BedDouble}
                        title="No room types yet"
                        description="Add the first room type to start setting up rooms and rates."
                        action={newButton}
                    />
                ) : (
                    <>
                        <Field>
                            <FieldLabel htmlFor="room-type-search">
                                Search room types
                            </FieldLabel>
                            <Input
                                id="room-type-search"
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Search by name or description"
                            />
                        </Field>

                        {filtered.length > 0 ? (
                            <RoomTypeTable
                                roomTypes={filtered}
                                showSoldBy={showSoldBy}
                                sellUnitLabel={sellUnitLabel}
                                canManage={canManage}
                                onMove={move}
                            />
                        ) : (
                            <EmptyState
                                title="No matching room types"
                                description="Change the search or add a new room type."
                            />
                        )}
                    </>
                )}
            </div>

            {canManage && (
                <NewRoomTypeDialog
                    open={creating}
                    onOpenChange={setCreating}
                    sellUnits={sellUnits}
                    bedsOn={bedsOn}
                />
            )}
        </>
    );
}

RoomTypesIndex.layout = {
    breadcrumbs: [{ title: 'Room types', href: index() }],
};
