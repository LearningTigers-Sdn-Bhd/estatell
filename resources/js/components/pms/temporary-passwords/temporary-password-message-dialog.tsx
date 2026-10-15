import { useHttp } from '@inertiajs/react';
import type { RouteDefinition } from '@/wayfinder';
import { Check, Copy } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useClipboard } from '@/hooks/use-clipboard';

type MessageData = App.Data.Staff.TemporaryPasswordMessageData;

/**
 * Loads the sign-in message for staff with a temp password and lets the owner copy it.
 * The text is fetched only when the dialog opens, so temp passwords never sit in page props.
 */
export function TemporaryPasswordMessageDialog({
    route,
    who = 'staff member',
    userIds,
    open,
    onOpenChange,
    onCopied,
}: {
    /** The route that returns the message, for example `message()` from the staff or partner temp password routes. */
    route: RouteDefinition<'post'>;
    /** Who gets the message, for the description: "staff member" or "partner". */
    who?: string;
    userIds: number[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Called when the owner copies the message. */
    onCopied?: () => void;
}) {
    const http = useHttp<{ user_ids: number[] }, MessageData>(route, {
        user_ids: userIds,
    });
    const [answer, setAnswer] = useState<MessageData | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const [copiedText, copy] = useClipboard();

    useEffect(() => {
        if (!open) {
            return;
        }

        setAnswer(null);
        setProblem(null);
        http.transform(() => ({ user_ids: userIds }));
        http.submit()
            .then((response) => setAnswer(response))
            .catch(() => setProblem('The message could not be loaded.'));
        // Load once each time the dialog opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const copied = answer !== null && copiedText === answer.text;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Sign-in message</DialogTitle>
                    <DialogDescription>
                        Send this to the {who} yourself, for example by
                        WhatsApp. The temp password works once.
                    </DialogDescription>
                </DialogHeader>

                {answer === null && problem === null && (
                    <div className="flex justify-center py-6">
                        <Spinner />
                    </div>
                )}

                {problem !== null && (
                    <p className="text-sm text-destructive">{problem}</p>
                )}

                {answer !== null && answer.count === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No unexpired temp password. Reset the temp password to
                        make a new one.
                    </p>
                )}

                {answer !== null && answer.count > 0 && answer.skipped > 0 && (
                    <p className="text-sm text-muted-foreground">
                        {answer.skipped} skipped: no temp password.
                    </p>
                )}

                {answer !== null && answer.count > 0 && (
                    <Textarea
                        readOnly
                        value={answer.text}
                        rows={Math.min(12, answer.text.split('\n').length)}
                        aria-label="Sign-in message"
                        className="font-mono text-sm"
                    />
                )}

                <DialogFooter>
                    <DialogClose render={<Button variant="outline" />}>
                        Close
                    </DialogClose>
                    <Button
                        type="button"
                        disabled={answer === null || answer.count === 0}
                        focusableWhenDisabled
                        onClick={() => {
                            if (answer) {
                                void copy(answer.text);
                                onCopied?.();
                            }
                        }}
                    >
                        {copied ? (
                            <Check data-icon="inline-start" />
                        ) : (
                            <Copy data-icon="inline-start" />
                        )}
                        {copied ? 'Copied' : 'Copy'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
