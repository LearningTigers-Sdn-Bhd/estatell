import { Form, Head } from '@inertiajs/react';
import { Clock, Ellipsis, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { FacilitySessionDialog } from '@/components/pms/settings/facility-session-dialog';
import {
    FacilityShell,
    facilityBreadcrumbs,
} from '@/components/pms/settings/facility-shell';
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
import { destroy } from '@/routes/settings/facilities/sessions';

type Facility = App.Data.Facilities.FacilityData;
type Session = App.Data.Facilities.FacilitySessionData;

export default function FacilitySessions({
    facility,
    sessions,
    currency,
}: {
    facility: Facility;
    sessions: Session[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [dialog, setDialog] = useState<{
        open: boolean;
        session: Session | null;
    }>({ open: false, session: null });
    const [deleting, setDeleting] = useState<Session | null>(null);

    return (
        <>
            <Head title={`${facility.name} sessions`} />

            <FacilityShell
                facility={facility}
                activeTab="sessions"
                currency={currency}
            >
                <section className="flex flex-col gap-5">
                    <header className="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                        <div className="flex min-w-0 flex-col gap-1">
                            <h3 className="text-base font-medium">Sessions</h3>
                            <p className="text-sm text-muted-foreground">
                                Staff pick one of these when they book{' '}
                                {facility.name}.
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            onClick={() =>
                                setDialog({ open: true, session: null })
                            }
                        >
                            <Plus data-icon="inline-start" />
                            Add session
                        </Button>
                    </header>

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
                </section>
            </FacilityShell>

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

FacilitySessions.layout = (props: { facility: Facility }) => ({
    breadcrumbs: facilityBreadcrumbs(props, 'sessions'),
});
