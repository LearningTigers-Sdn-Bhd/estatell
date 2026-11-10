import { router } from '@inertiajs/react';
import { Check, Ellipsis, Pencil, Trash2, Undo2, Wrench } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Spinner } from '@/components/ui/spinner';
import { done as setDone } from '@/routes/boats/jobs';

type Job = App.Data.Boats.BoatJobData;

type Props = {
    jobs: Job[];
    /** Keep the tick box space of the other groups, so the titles line up. */
    alignWithTicks: boolean;
    /** Mark done and Undo. Past dates too, like the trip tick. */
    canTick: boolean;
    /** Edit and Delete, while not done and the date is not past. */
    canManage: boolean;
    onEdit: (job: Job) => void;
    onDelete: (job: Job) => void;
};

/** "MV Sand Bay 2 · Razak (in charge), Din", or "No boat or crew set". */
export function jobCrewText(job: Job): string {
    const crew = [
        job.inCharge ? `${job.inCharge} (in charge)` : null,
        ...job.assistants,
    ]
        .filter(Boolean)
        .join(', ');

    if (job.boat === null && crew === '') {
        return 'No boat or crew set';
    }

    return [job.boat ?? 'No boat', crew || null].filter(Boolean).join(' · ');
}

/** The name of a job in labels and dialogs: "11:00 Diesel". */
export function jobName(job: Job): string {
    return `${job.leavesAt} ${job.jobLabel}`;
}

/**
 * The Boat jobs group of the Boat Trips board: boats and crew going out without guests, by time. Done works like the trip
 * tick: a **Mark done** button with a confirm, then "✓ Done" with **Undo**. Edit and Delete are in the row menu. See docs/24-boats/05-boats-board.md.
 */
export function BoatJobsSection({
    jobs,
    alignWithTicks,
    canTick,
    canManage,
    onEdit,
    onDelete,
}: Props) {
    const [ask, setAsk] = useState<{ job: Job; done: boolean } | null>(null);
    const [saving, setSaving] = useState(false);
    const tick = (job: Job, done: boolean) => setAsk({ job, done });

    const save = () => {
        if (ask === null) {
            return;
        }

        router.put(
            setDone.url({ sailing: ask.job.id }),
            { done: ask.done },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSaving(true),
                onFinish: () => {
                    setSaving(false);
                    setAsk(null);
                },
            },
        );
    };

    return (
        <section className="flex flex-col overflow-hidden rounded-lg border">
            <div className="flex items-start gap-3 bg-muted/50 px-3 py-2">
                {alignWithTicks && <span className="h-5 w-4 shrink-0" />}
                <h2 className="min-w-0 flex-1 text-sm font-medium">
                    Boat jobs
                </h2>
                <span className="shrink-0 text-sm text-muted-foreground">
                    {jobs.length === 1 ? '1 job' : `${jobs.length} jobs`}
                </span>
            </div>

            <div className="hidden md:block">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Leaves</TableHead>
                            <TableHead>Job</TableHead>
                            <TableHead>Boat and crew</TableHead>
                            <TableHead>Note</TableHead>
                            <TableHead className="text-right">Done</TableHead>
                            <TableHead className="w-10">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {jobs.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={6}>
                                    <JobsEmpty />
                                </TableCell>
                            </TableRow>
                        )}
                        {jobs.map((job) => (
                            <TableRow key={job.id}>
                                <TableCell className="font-medium tabular-nums">
                                    {job.leavesAt}
                                </TableCell>
                                <TableCell>{job.jobLabel}</TableCell>
                                <TableCell>{jobCrewText(job)}</TableCell>
                                <TableCell className="min-w-48">
                                    {job.note ?? '—'}
                                </TableCell>
                                <TableCell className="text-right">
                                    <DoneButton
                                        job={job}
                                        canTick={canTick}
                                        onTick={tick}
                                    />
                                </TableCell>
                                <TableCell>
                                    <JobMenu
                                        job={job}
                                        canManage={canManage}
                                        onEdit={onEdit}
                                        onDelete={onDelete}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            {jobs.length === 0 && (
                <div className="md:hidden">
                    <JobsEmpty />
                </div>
            )}
            <ul className="flex flex-col divide-y md:hidden">
                {jobs.map((job) => (
                    <li
                        key={job.id}
                        className="flex items-center gap-3 px-3 py-2"
                    >
                        <div className="flex min-w-0 flex-1 flex-col">
                            <span className="truncate text-sm font-medium">
                                {jobName(job)}
                            </span>
                            <span className="truncate text-xs text-muted-foreground">
                                {[jobCrewText(job), job.note]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                        </div>
                        <DoneButton job={job} canTick={canTick} onTick={tick} />
                        <JobMenu
                            job={job}
                            canManage={canManage}
                            onEdit={onEdit}
                            onDelete={onDelete}
                        />
                    </li>
                ))}
            </ul>

            <AlertDialog
                open={ask !== null}
                onOpenChange={(open) => !open && setAsk(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Mark the {ask ? jobName(ask.job) : ''} job as{' '}
                            {ask?.done ? '' : 'not '}done?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {ask?.done
                                ? 'This records who marked it and when. A done job cannot be changed or deleted until you undo it.'
                                : 'This clears who marked it and when.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <Button
                            type="button"
                            disabled={saving}
                            focusableWhenDisabled
                            onClick={save}
                        >
                            {saving && <Spinner data-icon="inline-start" />}
                            {ask?.done ? 'Mark done' : 'Mark not done'}
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </section>
    );
}

function JobsEmpty() {
    return (
        <EmptyState
            icon={Wrench}
            title="No boat jobs."
            description="Add a diesel, linen, supplies, staff or maintenance run with Trip or job."
        />
    );
}

/** As the trip tick: Mark done, then "✓ Done" with Undo. Without the permission, the state as text only. */
function DoneButton({
    job,
    canTick,
    onTick,
}: {
    job: Job;
    canTick: boolean;
    onTick: (job: Job, done: boolean) => void;
}) {
    if (job.done) {
        return (
            <span className="inline-flex items-center justify-end gap-2">
                <span className="inline-flex items-center gap-1 text-sm text-success">
                    <Check aria-hidden className="size-4" />
                    Done
                </span>
                {canTick && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        aria-label={`Undo done for ${jobName(job)}`}
                        onClick={() => onTick(job, false)}
                    >
                        <Undo2 data-icon="inline-start" />
                        Undo
                    </Button>
                )}
            </span>
        );
    }

    return canTick ? (
        <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => onTick(job, true)}
        >
            <Check data-icon="inline-start" />
            Mark done
        </Button>
    ) : (
        <span className="text-sm text-muted-foreground">—</span>
    );
}

function JobMenu({
    job,
    canManage,
    onEdit,
    onDelete,
}: {
    job: Job;
    canManage: boolean;
    onEdit: (job: Job) => void;
    onDelete: (job: Job) => void;
}) {
    if (!canManage || job.done) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                render={
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={`Actions for ${jobName(job)}`}
                    />
                }
            >
                <Ellipsis />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-40">
                <DropdownMenuItem onClick={() => onEdit(job)}>
                    <Pencil />
                    Edit
                </DropdownMenuItem>
                <DropdownMenuItem
                    variant="destructive"
                    onClick={() => onDelete(job)}
                >
                    <Trash2 />
                    Delete
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
