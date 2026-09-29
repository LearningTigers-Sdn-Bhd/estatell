import { Form, Head, useForm } from '@inertiajs/react';
import { Ban, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import {
    destroy,
    end,
    index,
    store,
    update,
} from '@/routes/front-desk/room-blocks';

type Block = App.Data.Rooms.RoomBlockData;
type Room = App.Data.Rooms.RoomBlockRoomData;
type Group = App.Data.Rooms.RoomGroupData;
type Option = { value: string; label: string };
type Tab = 'current' | 'past';

type FormData = {
    room_id: string;
    bed_id: string;
    reason: App.Enums.RoomBlockReason;
    starts_on: string;
    ends_on: string;
    notes: string;
};

function nights(block: Block): number {
    const [fromYear, fromMonth, fromDay] = block.startsOn
        .split('-')
        .map(Number);
    const [toYear, toMonth, toDay] = block.endsOn.split('-').map(Number);

    return (
        Math.round(
            (Date.UTC(toYear, toMonth - 1, toDay) -
                Date.UTC(fromYear, fromMonth - 1, fromDay)) /
                86_400_000,
        ) + 1
    );
}

function isPast(block: Block, today: string): boolean {
    return block.completedOn !== null || block.endsOn < today;
}

function BlockForm({
    block,
    rooms,
    reasons,
    today,
    maxNights,
    onDone,
}: {
    block: Block | null;
    rooms: Room[];
    reasons: Option[];
    today: string;
    maxNights: number;
    onDone: () => void;
}) {
    const form = useForm<FormData>({
        room_id: block ? String(block.roomId) : '',
        bed_id: block?.bedId ? String(block.bedId) : '',
        reason: block?.reason ?? 'maintenance',
        starts_on: block?.startsOn ?? '',
        ends_on: block?.endsOn ?? '',
        notes: block?.notes ?? '',
    });
    const started = block !== null && block.startsOn <= today;
    const room = rooms.find((item) => String(item.id) === form.data.room_id);
    const roomOptions = rooms.map((item) => ({
        value: String(item.id),
        label: `${item.number} · ${item.roomTypeName}`,
    }));
    const bedOptions = (room?.beds ?? []).map((bed) => ({
        value: String(bed.id),
        label: `Bed ${bed.label}`,
    }));

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onDone };

        if (block) {
            form.put(update.url({ roomBlock: block.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <FieldGroup className="flex-1 overflow-y-auto px-4">
                {block ? (
                    <Field>
                        <FieldLabel>Room</FieldLabel>
                        <p className="text-sm">
                            {block.roomNumber} · {block.roomTypeName}
                            {block.bedLabel && `, bed ${block.bedLabel}`}
                        </p>
                        <FieldDescription>
                            To block another room or bed, cancel or end this
                            block and create a new one.
                        </FieldDescription>
                    </Field>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field data-invalid={!!form.errors.room_id}>
                            <FieldLabel htmlFor="block-room">Room</FieldLabel>
                            <EnumSelect
                                id="block-room"
                                name="room_id"
                                options={roomOptions}
                                value={form.data.room_id}
                                onValueChange={(value) =>
                                    form.setData((data) => ({
                                        ...data,
                                        room_id: value,
                                        bed_id: '',
                                    }))
                                }
                                aria-invalid={!!form.errors.room_id}
                                className="w-full"
                            />
                            <FieldError>{form.errors.room_id}</FieldError>
                        </Field>
                        {room?.sellsBeds && (
                            <Field data-invalid={!!form.errors.bed_id}>
                                <FieldLabel htmlFor="block-bed">Bed</FieldLabel>
                                <EnumSelect
                                    id="block-bed"
                                    name="bed_id"
                                    options={bedOptions}
                                    allLabel="Whole room"
                                    value={form.data.bed_id}
                                    onValueChange={(value) =>
                                        form.setData('bed_id', value)
                                    }
                                    aria-invalid={!!form.errors.bed_id}
                                    className="w-full"
                                />
                                <FieldError>{form.errors.bed_id}</FieldError>
                            </Field>
                        )}
                    </div>
                )}
                <Field data-invalid={!!form.errors.reason}>
                    <FieldLabel htmlFor="block-reason">Reason</FieldLabel>
                    <EnumSelect
                        id="block-reason"
                        name="reason"
                        options={reasons}
                        value={form.data.reason}
                        onValueChange={(value) =>
                            form.setData(
                                'reason',
                                value as App.Enums.RoomBlockReason,
                            )
                        }
                        aria-invalid={!!form.errors.reason}
                        className="w-full sm:max-w-60"
                    />
                    <FieldError>{form.errors.reason}</FieldError>
                </Field>
                <Field
                    data-invalid={
                        !!(form.errors.starts_on || form.errors.ends_on)
                    }
                >
                    <FieldLabel htmlFor="block-nights">Nights</FieldLabel>
                    <DateRangePicker
                        id="block-nights"
                        from={form.data.starts_on}
                        to={form.data.ends_on}
                        onChange={(startsOn, endsOn) =>
                            form.setData((data) => ({
                                ...data,
                                starts_on: startsOn,
                                ends_on: endsOn,
                            }))
                        }
                        min={today}
                        fromLocked={started}
                        maxDays={maxNights}
                        unit="night"
                        placeholder="Pick the first and last night"
                        aria-invalid={
                            !!(form.errors.starts_on || form.errors.ends_on)
                        }
                    />
                    <FieldDescription>
                        {started
                            ? 'This block has started. You can change its last night.'
                            : 'Both nights are blocked. Pick one day for a single night.'}
                    </FieldDescription>
                    <FieldError>
                        {form.errors.starts_on ?? form.errors.ends_on}
                    </FieldError>
                </Field>
                <Field data-invalid={!!form.errors.notes}>
                    <FieldLabel htmlFor="block-notes">Notes</FieldLabel>
                    <Textarea
                        id="block-notes"
                        rows={3}
                        maxLength={500}
                        placeholder="e.g. Air conditioner repair"
                        value={form.data.notes}
                        onChange={(event) =>
                            form.setData('notes', event.target.value)
                        }
                        aria-invalid={!!form.errors.notes}
                    />
                    <FieldDescription>
                        {form.data.reason === 'other'
                            ? 'Required when the reason is Other.'
                            : 'Optional.'}
                    </FieldDescription>
                    <FieldError>{form.errors.notes}</FieldError>
                </Field>
            </FieldGroup>
            <SheetFooter>
                <SheetClose render={<Button type="button" variant="outline" />}>
                    Cancel
                </SheetClose>
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    {block ? 'Save changes' : 'Block room'}
                </Button>
            </SheetFooter>
        </form>
    );
}

function unitName(block: Block): string {
    return block.bedLabel
        ? `room ${block.roomNumber}, bed ${block.bedLabel}`
        : `room ${block.roomNumber}`;
}

function EndBlockDialog({
    block,
    onClose,
}: {
    block: Block | null;
    onClose: () => void;
}) {
    const form = useForm<{ room_status: string }>({ room_status: 'dirty' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (block) {
            form.post(end.url({ roomBlock: block.id }), {
                preserveScroll: true,
                onSuccess: onClose,
            });
        }
    };

    return (
        <AlertDialog
            open={block !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        End the block on {block && unitName(block)}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {block?.bedId
                            ? 'The bed goes back on sale from tonight.'
                            : 'The room goes back on sale from tonight. Tell housekeeping how the room was left.'}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    {block && !block.bedId && (
                        <FieldSet>
                            <FieldLegend variant="label">Room is</FieldLegend>
                            <RadioGroup
                                value={form.data.room_status}
                                onValueChange={(value) =>
                                    form.setData('room_status', String(value))
                                }
                            >
                                {[
                                    {
                                        value: 'dirty',
                                        label: 'Dirty',
                                        description: 'Needs cleaning first.',
                                    },
                                    {
                                        value: 'clean',
                                        label: 'Clean',
                                        description:
                                            'Ready for the next guest.',
                                    },
                                ].map((option) => (
                                    <FieldLabel
                                        key={option.value}
                                        htmlFor={`end-status-${option.value}`}
                                    >
                                        <Field orientation="horizontal">
                                            <RadioGroupItem
                                                id={`end-status-${option.value}`}
                                                value={option.value}
                                            />
                                            <FieldContent>
                                                <span className="text-sm font-medium">
                                                    {option.label}
                                                </span>
                                                <FieldDescription>
                                                    {option.description}
                                                </FieldDescription>
                                            </FieldContent>
                                        </Field>
                                    </FieldLabel>
                                ))}
                            </RadioGroup>
                            <FieldError>{form.errors.room_status}</FieldError>
                        </FieldSet>
                    )}
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep blocked</AlertDialogCancel>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                            focusableWhenDisabled
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            End block
                        </Button>
                    </AlertDialogFooter>
                </form>
            </AlertDialogContent>
        </AlertDialog>
    );
}

function CancelBlockDialog({
    block,
    onClose,
}: {
    block: Block | null;
    onClose: () => void;
}) {
    return (
        <AlertDialog
            open={block !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Cancel the block on {block && unitName(block)}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        The block has not started. Cancelling removes it and
                        puts the nights back on sale.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {block && (
                    <Form
                        {...destroy.form({ roomBlock: block.id })}
                        options={{ preserveScroll: true }}
                        onSuccess={onClose}
                    >
                        {({ processing }) => (
                            <AlertDialogFooter>
                                <AlertDialogCancel>
                                    Keep block
                                </AlertDialogCancel>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Cancel block
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                )}
            </AlertDialogContent>
        </AlertDialog>
    );
}

function BlockTable({
    blocks,
    tab,
    today,
    reasonLabel,
    onEdit,
    onEnd,
    onCancel,
}: {
    blocks: Block[];
    tab: Tab;
    today: string;
    reasonLabel: (reason: string) => string;
    onEdit: (block: Block) => void;
    onEnd: (block: Block) => void;
    onCancel: (block: Block) => void;
}) {
    return (
        <div className="overflow-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Room</TableHead>
                        <TableHead>Reason</TableHead>
                        <TableHead>Nights</TableHead>
                        <TableHead>Notes</TableHead>
                        <TableHead>
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {blocks.map((block) => {
                        const started = block.startsOn <= today;
                        const count = nights(block);

                        return (
                            <TableRow key={block.id}>
                                <TableCell>
                                    <div className="font-medium">
                                        {block.roomNumber}
                                        {block.bedLabel &&
                                            ` · Bed ${block.bedLabel}`}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {block.roomTypeName}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    {reasonLabel(block.reason)}
                                </TableCell>
                                <TableCell>
                                    <div className="flex flex-wrap items-center gap-x-1">
                                        <DateText date={block.startsOn} />
                                        <span aria-hidden>–</span>
                                        <DateText date={block.endsOn} />
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {count}{' '}
                                        {count === 1 ? 'night' : 'nights'}
                                        {tab === 'past' &&
                                            block.completedOn !== null &&
                                            block.completedOn <=
                                                block.endsOn && (
                                                <>
                                                    {' · Ended early on '}
                                                    <DateText
                                                        date={block.completedOn}
                                                    />
                                                </>
                                            )}
                                    </div>
                                </TableCell>
                                <TableCell className="max-w-80 whitespace-normal text-muted-foreground">
                                    {block.notes ?? '—'}
                                </TableCell>
                                <TableCell>
                                    {tab === 'current' && (
                                        <div className="flex items-center justify-end gap-1">
                                            <Badge
                                                variant={
                                                    started
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {started
                                                    ? 'In progress'
                                                    : 'Upcoming'}
                                            </Badge>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => onEdit(block)}
                                            >
                                                Edit
                                            </Button>
                                            {started ? (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => onEnd(block)}
                                                >
                                                    End now
                                                </Button>
                                            ) : (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        onCancel(block)
                                                    }
                                                >
                                                    Cancel
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}

export default function RoomBlocksIndex({
    blocks,
    rooms,
    roomGroups,
    reasons,
    today,
    maxNights,
    pastLimit,
}: {
    blocks: Block[];
    rooms: Room[];
    roomGroups: Group[];
    reasons: Option[];
    today: string;
    maxNights: number;
    pastLimit: number;
}) {
    const [editing, setEditing] = useState<Block | null | undefined>(undefined);
    const [ending, setEnding] = useState<Block | null>(null);
    const [cancelling, setCancelling] = useState<Block | null>(null);
    const [tab, setTab] = useState<Tab>('current');
    const [groupId, setGroupId] = useState('');
    const [reason, setReason] = useState('');

    const reasonLabel = (value: string) =>
        reasons.find((option) => option.value === value)?.label ?? value;

    const current = useMemo(
        () => blocks.filter((block) => !isPast(block, today)),
        [blocks, today],
    );
    const past = useMemo(
        () => blocks.filter((block) => isPast(block, today)),
        [blocks, today],
    );
    const shown = (tab === 'current' ? current : past).filter(
        (block) =>
            (groupId === '' || String(block.roomGroupId) === groupId) &&
            (reason === '' || block.reason === reason),
    );

    const blockRoomButton = (
        <Button onClick={() => setEditing(null)} disabled={rooms.length === 0}>
            <Plus data-icon="inline-start" />
            Block room
        </Button>
    );

    return (
        <>
            <Head title="Room blocks" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Room blocks"
                    description="Take rooms and beds out of sale for maintenance, owner use or repairs."
                    actions={blockRoomButton}
                />

                {blocks.length === 0 ? (
                    <EmptyState
                        icon={Ban}
                        title="No room blocks"
                        description={
                            rooms.length === 0
                                ? 'Add rooms in Settings before you block them.'
                                : 'Block a room when it cannot be sold, for example during repairs.'
                        }
                        action={rooms.length > 0 ? blockRoomButton : undefined}
                    />
                ) : (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            <Tabs
                                value={tab}
                                onValueChange={(value) => setTab(value as Tab)}
                            >
                                <TabsList>
                                    <TabsTrigger value="current">
                                        Current and upcoming ({current.length})
                                    </TabsTrigger>
                                    <TabsTrigger value="past">Past</TabsTrigger>
                                </TabsList>
                            </Tabs>
                            {roomGroups.length > 0 && (
                                <EnumSelect
                                    options={roomGroups.map((group) => ({
                                        value: String(group.id),
                                        label: group.name,
                                    }))}
                                    allLabel="All room groups"
                                    value={groupId}
                                    onValueChange={setGroupId}
                                    aria-label="Room group"
                                />
                            )}
                            <EnumSelect
                                options={reasons}
                                allLabel="All reasons"
                                value={reason}
                                onValueChange={setReason}
                                aria-label="Reason"
                            />
                        </div>

                        {shown.length > 0 ? (
                            <BlockTable
                                blocks={shown}
                                tab={tab}
                                today={today}
                                reasonLabel={reasonLabel}
                                onEdit={setEditing}
                                onEnd={setEnding}
                                onCancel={setCancelling}
                            />
                        ) : (
                            <EmptyState
                                title={
                                    tab === 'current'
                                        ? 'No current or upcoming blocks'
                                        : 'No past blocks'
                                }
                                description="Change the filters or block a room."
                            />
                        )}

                        {tab === 'past' && past.length >= pastLimit && (
                            <p className="text-sm text-muted-foreground">
                                Showing the latest {pastLimit} past blocks.
                            </p>
                        )}
                    </>
                )}
            </div>

            <Sheet
                open={editing !== undefined}
                onOpenChange={(open) => !open && setEditing(undefined)}
            >
                <SheetContent className="data-[side=right]:sm:max-w-xl">
                    <SheetHeader>
                        <SheetTitle>
                            {editing
                                ? `Edit block on ${unitName(editing)}`
                                : 'Block room'}
                        </SheetTitle>
                        <SheetDescription>
                            A blocked room or bed cannot be sold for these
                            nights.
                        </SheetDescription>
                    </SheetHeader>
                    {editing !== undefined && (
                        <BlockForm
                            key={editing?.id ?? 'new'}
                            block={editing}
                            rooms={rooms}
                            reasons={reasons}
                            today={today}
                            maxNights={maxNights}
                            onDone={() => setEditing(undefined)}
                        />
                    )}
                </SheetContent>
            </Sheet>

            <EndBlockDialog
                key={ending?.id ?? 'none'}
                block={ending}
                onClose={() => setEnding(null)}
            />
            <CancelBlockDialog
                block={cancelling}
                onClose={() => setCancelling(null)}
            />
        </>
    );
}

RoomBlocksIndex.layout = {
    breadcrumbs: [{ title: 'Room blocks', href: index() }],
};
