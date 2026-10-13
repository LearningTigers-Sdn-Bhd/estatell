import { Check, Copy } from 'lucide-react';
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
import { Textarea } from '@/components/ui/textarea';
import { useClipboard } from '@/hooks/use-clipboard';

/**
 * Shows the sign-in message for a new partner and lets the admin copy it. The server flashes the text once after
 * saving, so it is never part of the list's props and is gone after the next request.
 */
export function PartnerSignInMessageDialog({
    message,
    onClose,
}: {
    message: string | null;
    onClose: () => void;
}) {
    const [copiedText, copy] = useClipboard();
    const copied = message !== null && copiedText === message;

    return (
        <Dialog
            open={message !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Sign-in message</DialogTitle>
                    <DialogDescription>
                        Send this to the partner yourself, for example by
                        WhatsApp. It is shown only now.
                    </DialogDescription>
                </DialogHeader>

                <Textarea
                    readOnly
                    value={message ?? ''}
                    rows={Math.min(12, (message ?? '').split('\n').length)}
                    aria-label="Sign-in message"
                    className="font-mono text-sm"
                />

                <DialogFooter>
                    <DialogClose render={<Button variant="outline" />}>
                        Close
                    </DialogClose>
                    <Button
                        type="button"
                        onClick={() => message && void copy(message)}
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
