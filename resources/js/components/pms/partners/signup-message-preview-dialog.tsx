import { Check, Copy, Pencil } from 'lucide-react';
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

/**
 * Shows the message that "Copy message" just put on the clipboard, so the partner sees what they will paste. Edit
 * message closes this Dialog and asks the page to open the editor, so only one overlay is open at a time.
 */
export function SignupMessagePreviewDialog({
    open,
    onOpenChange,
    message,
    copied,
    onCopy,
    onEdit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    message: string;
    copied: boolean;
    onCopy: () => void;
    onEdit: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {copied ? 'Message copied' : 'Message'}
                    </DialogTitle>
                    <DialogDescription>
                        Paste it into WhatsApp or any chat.
                    </DialogDescription>
                </DialogHeader>
                <p
                    aria-label="Message preview"
                    className="rounded-lg border bg-muted/50 px-2.5 py-2 text-sm whitespace-pre-wrap"
                >
                    {message}
                </p>
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onEdit}>
                        <Pencil data-icon="inline-start" />
                        Edit message
                    </Button>
                    <DialogClose render={<Button variant="outline" />}>
                        Close
                    </DialogClose>
                    <Button type="button" onClick={onCopy}>
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
