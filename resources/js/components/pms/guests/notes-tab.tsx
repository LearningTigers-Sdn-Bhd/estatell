import { StickyNote } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { AddNoteDialog } from '@/components/pms/guests/add-note-dialog';
import { PanelHeading } from '@/components/pms/panel-heading';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

type Guest = App.Data.Guests.GuestData;
type Note = App.Data.Guests.GuestNoteData;

/**
 * The note text, cut to three lines with a Show more button when it is longer. Line breaks are kept.
 */
function NoteBody({ body }: { body: string }) {
    const bodyRef = useRef<HTMLParagraphElement>(null);
    const [expanded, setExpanded] = useState(false);
    const [clamped, setClamped] = useState(false);

    useLayoutEffect(() => {
        const element = bodyRef.current;

        if (element && !expanded) {
            setClamped(element.scrollHeight > element.clientHeight);
        }
    }, [body, expanded]);

    return (
        <div className="flex flex-col items-start gap-1">
            <p
                ref={bodyRef}
                className={cn(
                    'wrap-anywhere whitespace-pre-wrap',
                    !expanded && 'line-clamp-3',
                )}
            >
                {body}
            </p>
            {(clamped || expanded) && (
                <Button
                    type="button"
                    variant="link"
                    size="sm"
                    className="h-auto p-0"
                    onClick={() => setExpanded((open) => !open)}
                >
                    {expanded ? 'Show less' : 'Show more'}
                </Button>
            )}
        </div>
    );
}

/**
 * The Notes tab: the heading with the add-note dialog, then the notes in a table, newest first.
 */
export function NotesTab({
    guest,
    notes,
    canAdd,
}: {
    guest: Guest;
    notes: Note[];
    canAdd: boolean;
}) {
    return (
        <div className="flex flex-col gap-4">
            <PanelHeading
                icon={StickyNote}
                title="Notes"
                description="Notes about the guest, newest first. A note cannot be edited or deleted."
                actions={canAdd ? <AddNoteDialog guest={guest} /> : undefined}
            />
            {notes.length === 0 ? (
                <EmptyState
                    icon={StickyNote}
                    title="No notes yet"
                    description="Notes about preferences or past stays show here."
                />
            ) : (
                <div className="overflow-auto rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-44">Date</TableHead>
                                <TableHead>Note</TableHead>
                                <TableHead className="w-44">By</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {notes.map((note) => (
                                <TableRow key={note.id}>
                                    <TableCell className="align-top tabular-nums">
                                        <time dateTime={note.createdAt}>
                                            {new Date(
                                                note.createdAt,
                                            ).toLocaleString('en-GB', {
                                                day: '2-digit',
                                                month: 'short',
                                                year: 'numeric',
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            })}
                                        </time>
                                    </TableCell>
                                    <TableCell className="min-w-64 align-top whitespace-normal">
                                        <NoteBody body={note.body} />
                                    </TableCell>
                                    <TableCell className="align-top">
                                        {note.authorName ??
                                            'Former staff member'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}
        </div>
    );
}
