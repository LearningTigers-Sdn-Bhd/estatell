import { Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { show } from '@/routes/finance/e-invoices';
import {
    type Submission,
    VISIBLE_CREDIT_NOTES,
} from '@/components/pms/einvoice/submission';

export function CreditNoteList({
    submission,
    money,
}: {
    submission: Submission;
    money: (amount: string | null) => ReactNode;
}) {
    const [showAll, setShowAll] = useState(false);
    const notes = showAll
        ? submission.credit_notes
        : submission.credit_notes.slice(0, VISIBLE_CREDIT_NOTES);
    const hidden = submission.credit_notes.length - VISIBLE_CREDIT_NOTES;

    return (
        <div className="flex flex-col divide-y rounded-lg border">
            {submission.credited !== null && (
                <p className="px-3 py-2 text-sm">
                    Credited <strong>{money(submission.credited)}</strong> of{' '}
                    {money(submission.amounts.total)}
                </p>
            )}
            <ul className="flex flex-col divide-y">
                {notes.map((note) => (
                    <li
                        key={note.id}
                        className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm"
                    >
                        <Link
                            href={show.url({ submission: note.id })}
                            className="font-medium underline-offset-2 hover:underline"
                        >
                            {note.number}
                        </Link>
                        <span className="text-muted-foreground">
                            {note.kind.label}
                        </span>
                        <span className="text-muted-foreground tabular-nums">
                            {note.issued_at}
                        </span>
                        <span className="ms-auto flex items-center gap-3">
                            {money(note.amount)}
                            <StatusBadge status={note.status} />
                        </span>
                    </li>
                ))}
            </ul>
            {hidden > 0 && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="rounded-t-none"
                    onClick={() => setShowAll(!showAll)}
                >
                    {showAll
                        ? 'Show fewer'
                        : `Show all ${submission.credit_notes.length}`}
                </Button>
            )}
        </div>
    );
}
