import { Form, Head, useForm } from '@inertiajs/react';
import { Ban, CalendarClock, History, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { PageHeader } from '@/components/pms/page-header';
import { RoomBlockForm } from '@/components/pms/room-block-form';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
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
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { destroy, end, index } from '@/routes/front-desk/room-blocks';

type Block = App.Data.Rooms.RoomBlockData;
type Room = App.Data.Rooms.RoomBlockRoomData;
type Group = App.Data.Rooms.RoomGroupData;
type Option = { value: string; label: string };
type Tab = 'current' | 'past';

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

/** Current and upcoming, then Past, for the tab bar and the breadcrumbs. */
function roomBlockTabs(currentCount: number): TabLink[] {
    return [
        {
            value: 'current',
            label: `Current and upcoming (${currentCount})`,
            title: 'Current and upcoming',
            icon: CalendarClock,
            href: index(),
        },
        {
            value: 'past',
            label: 'Past',
            icon: History,
            href: index(undefined, { query: { tab: 'past' } }),
        },
    ];
}

function matchesSearch(block: Block, term: string): boolean {
    return [
        block.roomNumber,
        block.bedLabel ?? '',
        block.roomTypeName,
        block.notes ?? '',
    ].some((value) => value.toLowerCase().includes(term));
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
    empty,
}: {
    blocks: Block[];
    tab: Tab;
    today: string;
    reasonLabel: (reason: string) => string;
    onEdit: (block: Block) => void;
    onEnd: (block: Block) => void;
    onCancel: (block: Block) => void;
    /** Shown in one full-width row when there are no blocks. */
    empty: ReactNode;
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
                    {blocks.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={5}>{empty}</TableCell>
                        </TableRow>
                    )}
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
    tab,
}: {
    blocks: Block[];
    rooms: Room[];
    roomGroups: Group[];
    reasons: Option[];
    today: string;
    maxNights: number;
    pastLimit: number;
    tab: Tab;
}) {
    const [editing, setEditing] = useState<Block | null | undefined>(undefined);
    const [ending, setEnding] = useState<Block | null>(null);
    const [cancelling, setCancelling] = useState<Block | null>(null);
    const [search, setSearch] = useState('');
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
    const tabBlocks = tab === 'current' ? current : past;
    const term = search.trim().toLowerCase();
    const filtered = term !== '' || groupId !== '' || reason !== '';
    const shown = tabBlocks.filter(
        (block) =>
            (groupId === '' || String(block.roomGroupId) === groupId) &&
            (reason === '' || block.reason === reason) &&
            (term === '' || matchesSearch(block, term)),
    );
    const clearFilters = () => {
        setSearch('');
        setGroupId('');
        setReason('');
    };

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

                <TabLinks
                    activeTab={tab}
                    panelWidth="full"
                    className="gap-4"
                    triggerClassName="min-w-0 px-0 mr-6"
                    panelClassName="flex flex-col gap-4"
                    tabs={roomBlockTabs(current.length)}
                >
                    <FilterBar
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by room, bed, room type or notes"
                    >
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
                    </FilterBar>

                    <BlockTable
                        blocks={shown}
                        tab={tab}
                        today={today}
                        reasonLabel={reasonLabel}
                        onEdit={setEditing}
                        onEnd={setEnding}
                        onCancel={setCancelling}
                        empty={
                            filtered && tabBlocks.length > 0 ? (
                                <EmptyState
                                    icon={Ban}
                                    title="No blocks match your search"
                                    action={
                                        <Button
                                            variant="outline"
                                            onClick={clearFilters}
                                        >
                                            Clear filters
                                        </Button>
                                    }
                                />
                            ) : tab === 'current' ? (
                                <EmptyState
                                    icon={Ban}
                                    title="No current or upcoming blocks"
                                    description={
                                        rooms.length === 0
                                            ? 'Add rooms in Settings before you block them.'
                                            : 'Block a room when it cannot be sold, for example during repairs.'
                                    }
                                    action={
                                        rooms.length > 0
                                            ? blockRoomButton
                                            : undefined
                                    }
                                />
                            ) : (
                                <EmptyState
                                    icon={History}
                                    title="No past blocks"
                                    description="Blocks show here after they end or are ended early."
                                />
                            )
                        }
                    />

                    {tab === 'past' && past.length >= pastLimit && (
                        <p className="text-sm text-muted-foreground">
                            Showing the latest {pastLimit} past blocks.
                        </p>
                    )}
                </TabLinks>
            </div>

            <Dialog
                open={editing !== undefined}
                onOpenChange={(open) => !open && setEditing(undefined)}
            >
                <DialogContent className="max-w-full max-md:top-auto max-md:bottom-0 max-md:translate-y-0 max-md:rounded-b-none sm:max-w-none md:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editing
                                ? `Edit block on ${unitName(editing)}`
                                : 'Block room'}
                        </DialogTitle>
                        <DialogDescription>
                            A blocked room or bed cannot be sold for these
                            nights.
                        </DialogDescription>
                    </DialogHeader>
                    {editing !== undefined && (
                        <RoomBlockForm
                            key={editing?.id ?? 'new'}
                            block={editing}
                            rooms={rooms}
                            reasons={reasons}
                            today={today}
                            maxNights={maxNights}
                            onDone={() => setEditing(undefined)}
                        />
                    )}
                </DialogContent>
            </Dialog>

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

RoomBlocksIndex.layout = (props: {
    blocks: Block[];
    today: string;
    tab: Tab;
}) => ({
    breadcrumbs: withTabCrumb(
        [{ title: 'Room blocks', href: index() }],
        roomBlockTabs(
            props.blocks.filter((block) => !isPast(block, props.today)).length,
        ),
        props.tab,
    ),
});
