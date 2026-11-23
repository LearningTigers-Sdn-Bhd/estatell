import { Form, Head } from '@inertiajs/react';
import { Clock, Ellipsis, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { PageHeader } from '@/components/pms/page-header';
import { FacilitySessionDialog } from '@/components/pms/settings/facility-session-dialog';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index as facilitiesIndex } from '@/routes/settings/facilities';
import { destroy, index } from '@/routes/settings/facilities/sessions';

type Session = App.Data.Facilities.FacilitySessionData;
type FacilityRef = { id: number; name: string };

export default function FacilitySessions({
    facility,
    sessions,
}: {
    facility: FacilityRef;
    sessions: Session[];
}) {
    const [dialog, setDialog] = useState<{
        open: boolean;
        session: Session | null;
    }>({ open: false, session: null });
    const [deleting, setDeleting] = useState<Session | null>(null);

    return (
        <>
            <Head title={`${facility.name} sessions`} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Sessions"
                    description={`Staff pick one of these when they book ${facility.name}.`}
                    actions={
                        <Button
                            onClick={() =>
                                setDialog({ open: true, session: null })
                            }
                        >
                            <Plus data-icon="inline-start" />
                            Add session
                        </Button>
                    }
                />

                <div className="overflow-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>From</TableHead>
                                <TableHead>To</TableHead>
                                <TableHead className="w-12">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sessions.length === 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell colSpan={3}>
                                        <EmptyState
                                            icon={Clock}
                                            title="No sessions yet."
                                            description="Without sessions, staff type the From and To times of each booking."
                                        />
                                    </TableCell>
                                </TableRow>
                            )}
                            {sessions.map((session) => (
                                <TableRow key={session.id}>
                                    <TableCell className="tabular-nums">
                                        {session.startsAt}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {session.endsAt}
                                    </TableCell>
                                    <TableCell>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger
                                                render={
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                    />
                                                }
                                                aria-label={`Actions for ${session.startsAt} to ${session.endsAt}`}
                                            >
                                                <Ellipsis />
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent
                                                align="end"
                                                className="w-36"
                                            >
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        setDialog({
                                                            open: true,
                                                            session,
                                                        })
                                                    }
                                                >
                                                    <Pencil />
                                                    Edit
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onClick={() =>
                                                        setDeleting(session)
                                                    }
                                                >
                                                    <Trash2 />
                                                    Delete
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>

            <FacilitySessionDialog
                open={dialog.open}
                onOpenChange={(open) =>
                    setDialog((current) => ({ ...current, open }))
                }
                facilityId={facility.id}
                session={dialog.session}
            />

            <AlertDialog
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleting(null);
                    }
                }}
            >
                <AlertDialogContent>
                    {deleting && (
                        <Form
                            {...destroy.form({
                                facility: facility.id,
                                session: deleting.id,
                            })}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setDeleting(null)}
                        >
                            {({ processing }) => (
                                <>
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>
                                            Delete {deleting.startsAt} to{' '}
                                            {deleting.endsAt}?
                                        </AlertDialogTitle>
                                        <AlertDialogDescription>
                                            New bookings cannot pick this
                                            session. Saved bookings keep their
                                            times.
                                        </AlertDialogDescription>
                                    </AlertDialogHeader>
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>
                                            Cancel
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
                                            Delete
                                        </Button>
                                    </AlertDialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

FacilitySessions.layout = (props: { facility: FacilityRef }) => ({
    breadcrumbs: [
        { title: 'Facilities', href: facilitiesIndex() },
        {
            title: props.facility.name,
            href: facilitiesIndex(),
        },
        {
            title: 'Sessions',
            href: index({ facility: props.facility.id }),
        },
    ],
});
