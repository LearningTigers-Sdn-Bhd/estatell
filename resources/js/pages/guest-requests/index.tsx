import { Form, Head, router } from '@inertiajs/react';
import { MoreHorizontal, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import {
    assign,
    cancel,
    index,
    reopen,
    resolve,
    start,
    store,
} from '@/routes/guest-requests';

type Row = App.Data.GuestRequests.GuestRequestRowData;

type Props = {
    requests: Row[];
    filters: { status: string; type: string };
    statuses: EnumOption[];
    types: EnumOption[];
    sources: EnumOption[];
    assignees: EnumOption[];
    stays: EnumOption[];
    canManage: boolean;
};

/** How long a request has waited, as "12 min", "2 h 10 min" or "3 days". */
function age(openedAt: string, closedAt: string | null): string {
    const minutes = Math.max(
        0,
        Math.round(
            ((closedAt ? new Date(closedAt) : new Date()).getTime() -
                new Date(openedAt).getTime()) /
                60000,
        ),
    );

    if (minutes < 60) {
        return `${minutes} min`;
    }

    if (minutes < 48 * 60) {
        return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
    }

    return `${Math.round(minutes / 1440)} days`;
}

const moveLabels: Record<string, string> = {
    start: 'Start',
    resolve: 'Resolve',
    cancel: 'Cancel request',
    reopen: 'Reopen',
};

/** The work queue of guest requests: the front desk records them, housekeeping and maintenance do the work. */
export default function GuestRequestsIndex({
    requests,
    filters,
    statuses,
    types,
    sources,
    assignees,
    stays,
    canManage,
}: Props) {
    const [creating, setCreating] = useState(false);
    const [noting, setNoting] = useState<{
        row: Row;
        move: 'resolve' | 'cancel';
    } | null>(null);

    function filter(changes: Partial<{ status: string; type: string }>) {
        router.get(
            index.url(),
            { ...filters, ...changes },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function move(row: Row, name: string) {
        if (name === 'resolve' || name === 'cancel') {
            setNoting({ row, move: name });

            return;
        }

        const route = name === 'start' ? start : reopen;
        router.post(
            route.url({ guestRequest: row.id }),
            {},
            { preserveScroll: true },
        );
    }

    return (
        <>
            <Head title="Requests" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Requests"
                    description="What guests ask for or complain about during a stay. The Concierge page sends requests here too."
                    actions={
                        canManage && (
                            <Button
                                type="button"
                                onClick={() => setCreating(true)}
                            >
                                <Plus data-icon="inline-start" />
                                New request
                            </Button>
                        )
                    }
                />

                <div className="flex flex-wrap gap-3">
                    <EnumSelect
                        aria-label="Status"
                        options={statuses}
                        value={filters.status}
                        onValueChange={(status) => filter({ status })}
                        className="w-52"
                    />
                    <EnumSelect
                        aria-label="Type"
                        options={types}
                        value={filters.type}
                        allLabel="All types"
                        onValueChange={(type) => filter({ type })}
                        className="w-44"
                    />
                </div>

                {requests.length === 0 ? (
                    <EmptyState
                        title="No requests"
                        description="Nothing matches these filters."
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Age</TableHead>
                                <TableHead>Room</TableHead>
                                <TableHead>Guest</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Details</TableHead>
                                <TableHead>Assigned</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-10" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {requests.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell className="whitespace-nowrap tabular-nums">
                                        {age(row.openedAt, row.resolvedAt)}
                                    </TableCell>
                                    <TableCell>
                                        {row.roomNumber ?? '–'}
                                    </TableCell>
                                    <TableCell>
                                        {row.guestName ?? '–'}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge status={row.type} />
                                    </TableCell>
                                    <TableCell className="max-w-xs">
                                        <p className="text-pretty">
                                            {row.details}
                                        </p>
                                        {row.resolutionNote && (
                                            <p className="text-xs text-muted-foreground">
                                                {row.resolutionNote}
                                            </p>
                                        )}
                                        <p className="text-xs text-muted-foreground">
                                            {row.source}
                                        </p>
                                    </TableCell>
                                    <TableCell className="w-40">
                                        {canManage ? (
                                            <EnumSelect
                                                aria-label={`Assign request ${row.id}`}
                                                options={assignees}
                                                value={
                                                    row.assignedTo
                                                        ? String(row.assignedTo)
                                                        : ''
                                                }
                                                allLabel="Unassigned"
                                                onValueChange={(userId) =>
                                                    router.put(
                                                        assign.url({
                                                            guestRequest:
                                                                row.id,
                                                        }),
                                                        { user_id: userId },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            />
                                        ) : (
                                            (row.assigneeName ?? '–')
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge status={row.status} />
                                    </TableCell>
                                    <TableCell>
                                        {row.moves.length > 0 && (
                                            <DropdownMenu>
                                                <DropdownMenuTrigger
                                                    render={
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            aria-label={`Actions for request ${row.id}`}
                                                        />
                                                    }
                                                >
                                                    <MoreHorizontal />
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    {row.moves.map((name) => (
                                                        <DropdownMenuItem
                                                            key={name}
                                                            variant={
                                                                name ===
                                                                'cancel'
                                                                    ? 'destructive'
                                                                    : undefined
                                                            }
                                                            onClick={() =>
                                                                move(row, name)
                                                            }
                                                        >
                                                            {moveLabels[name]}
                                                        </DropdownMenuItem>
                                                    ))}
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </div>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>New request</DialogTitle>
                        <DialogDescription>
                            Record what the guest asked for. The room is the
                            room the guest sleeps in.
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        action={store.url()}
                        method="post"
                        onSuccess={() => setCreating(false)}
                        resetOnSuccess
                    >
                        {({ processing, errors }) => (
                            <div className="flex flex-col gap-6">
                                <FieldGroup>
                                    <Field data-invalid={!!errors.type}>
                                        <FieldLabel htmlFor="type">
                                            Type
                                        </FieldLabel>
                                        <EnumSelect
                                            id="type"
                                            name="type"
                                            options={types}
                                            defaultValue="housekeeping"
                                        />
                                        <FieldError>{errors.type}</FieldError>
                                    </Field>
                                    <Field data-invalid={!!errors.stay_id}>
                                        <FieldLabel htmlFor="stay_id">
                                            Stay
                                        </FieldLabel>
                                        <EnumSelect
                                            id="stay_id"
                                            name="stay_id"
                                            options={stays}
                                            placeholder="Choose a room and guest"
                                        />
                                        <FieldError>
                                            {errors.stay_id ?? errors.room_id}
                                        </FieldError>
                                    </Field>
                                    <Field data-invalid={!!errors.source}>
                                        <FieldLabel htmlFor="source">
                                            How did it reach us?
                                        </FieldLabel>
                                        <EnumSelect
                                            id="source"
                                            name="source"
                                            options={sources}
                                            defaultValue="in_person"
                                        />
                                        <FieldError>{errors.source}</FieldError>
                                    </Field>
                                    <Field data-invalid={!!errors.details}>
                                        <FieldLabel htmlFor="details">
                                            Details
                                        </FieldLabel>
                                        <Textarea
                                            id="details"
                                            name="details"
                                            placeholder="e.g. Two extra towels and a pillow"
                                            maxLength={1000}
                                            required
                                        />
                                        <FieldError>
                                            {errors.details}
                                        </FieldError>
                                    </Field>
                                </FieldGroup>
                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setCreating(false)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        focusableWhenDisabled
                                    >
                                        {processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        Save request
                                    </Button>
                                </DialogFooter>
                            </div>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={noting !== null}
                onOpenChange={(open) => !open && setNoting(null)}
            >
                <DialogContent>
                    {noting && (
                        <Form
                            action={(noting.move === 'resolve'
                                ? resolve
                                : cancel
                            ).url({
                                guestRequest: noting.row.id,
                            })}
                            method="post"
                            onSuccess={() => setNoting(null)}
                            className="flex flex-col gap-6"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>
                                            {noting.move === 'resolve'
                                                ? 'Resolve request'
                                                : 'Cancel request'}
                                        </DialogTitle>
                                        <DialogDescription>
                                            {noting.move === 'resolve'
                                                ? 'Add a note about what was done, if you like.'
                                                : 'Write why it is cancelled, for example "Duplicate".'}
                                        </DialogDescription>
                                    </DialogHeader>
                                    <Field data-invalid={!!errors.note}>
                                        <FieldLabel htmlFor="note">
                                            Note
                                        </FieldLabel>
                                        <Textarea
                                            id="note"
                                            name="note"
                                            maxLength={500}
                                            required={noting.move === 'cancel'}
                                        />
                                        <FieldError>{errors.note}</FieldError>
                                    </Field>
                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setNoting(null)}
                                        >
                                            Back
                                        </Button>
                                        <Button
                                            type="submit"
                                            variant={
                                                noting.move === 'cancel'
                                                    ? 'destructive'
                                                    : 'default'
                                            }
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && (
                                                <Spinner data-icon="inline-start" />
                                            )}
                                            {noting.move === 'resolve'
                                                ? 'Resolve'
                                                : 'Cancel request'}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

GuestRequestsIndex.layout = {
    breadcrumbs: [{ title: 'Requests', href: index() }],
};
