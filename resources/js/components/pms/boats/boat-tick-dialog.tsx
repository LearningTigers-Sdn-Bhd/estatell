import { router } from '@inertiajs/react';
import { useState } from 'react';
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
import { Spinner } from '@/components/ui/spinner';
import { setTick } from '@/routes/boats/trips';

type Row = App.Data.Reports.BoatManifestRow;

/** "arrived" for Boat in, "left" for Boat out: the word of the trip tick. */
export function tickWord(direction: string | null): string {
    return direction === 'out' ? 'left' : 'arrived';
}

/**
 * Asks before the trip tick of one Boat Trips row changes: "Mark 102 · Hanami Saki as arrived?", or "as not arrived?"
 * for Undo. Confirming saves it with `boats.trips.set-tick`. See docs/24-boats/05-boats-board.md#layout.
 */
export function BoatTickDialog({
    ask,
    onClose,
}: {
    /** The row and the state to set, or null when closed. */
    ask: { row: Row; ticked: boolean } | null;
    onClose: () => void;
}) {
    const [saving, setSaving] = useState(false);
    const word = tickWord(ask?.row.direction ?? null);
    const name = ask === null ? '' : `${ask.row.unit} · ${ask.row.lead_guest}`;
    const action = ask?.ticked ? `Mark ${word}` : `Mark not ${word}`;

    const save = () => {
        if (ask === null) {
            return;
        }

        router.put(
            setTick.url({ trip: ask.row.key }),
            { ticked: ask.ticked },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSaving(true),
                onFinish: () => {
                    setSaving(false);
                    onClose();
                },
            },
        );
    };

    return (
        <AlertDialog
            open={ask !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Mark {name} as {ask?.ticked ? '' : 'not '}
                        {word}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {ask?.ticked
                            ? `This records who marked it and when. It does not check the guest ${ask.row.direction === 'out' ? 'out' : 'in'}.`
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
                        {action}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
