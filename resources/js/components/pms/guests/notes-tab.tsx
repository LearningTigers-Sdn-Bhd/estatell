import { StickyNote } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { AddNoteDialog } from '@/components/pms/guests/add-note-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { cn } from '@/lib/utils';

type Guest = App.Data.Guests.GuestData;
type Note = App.Data.Guests.GuestNoteData;

/**
 * One note. A long note is cut to six lines with a Show more button.
 */
function NoteCard({ note }: { note: Note }) {
    const bodyRef = useRef<HTMLParagraphElement>(null);
    const [expanded, setExpanded] = useState(false);
    const [clamped, setClamped] = useState(false);

    useLayoutEffect(() => {
        const body = bodyRef.current;

        if (body && !expanded) {
            setClamped(body.scrollHeight > body.clientHeight);
        }
    }, [note.body, expanded]);

    return (
        <Card size="sm" className="h-fit">
            <CardContent className="flex flex-col gap-2">
                <p
                    ref={bodyRef}
                    className={cn(
                        'text-sm wrap-anywhere whitespace-pre-wrap',
                        !expanded && 'line-clamp-6',
                    )}
                >
                    {note.body}
                </p>
                {(clamped || expanded) && (
                    <Button
                        type="button"
                        variant="link"
                        size="sm"
                        className="h-auto self-start p-0"
                        onClick={() => setExpanded((open) => !open)}
                    >
                        {expanded ? 'Show less' : 'Show more'}
                    </Button>
                )}
            </CardContent>
            <CardFooter className="text-xs text-muted-foreground">
                {note.authorName ?? 'Former staff member'} ·{' '}
                <time dateTime={note.createdAt} className="ml-1">
                    {new Date(note.createdAt).toLocaleString('en-GB', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                    })}
                </time>
            </CardFooter>
        </Card>
    );
}

/**
 * The Notes tab: a section title with the add-note dialog, then the note cards, newest first.
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
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="font-heading text-base font-medium">Notes</h2>
                {canAdd && <AddNoteDialog guest={guest} />}
            </div>
            {notes.length === 0 ? (
                <EmptyState
                    icon={StickyNote}
                    title="No notes yet"
                    description="Notes about preferences or past stays show here."
                />
            ) : (
                <div className="grid items-start gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {notes.map((note) => (
                        <NoteCard key={note.id} note={note} />
                    ))}
                </div>
            )}
        </div>
    );
}
