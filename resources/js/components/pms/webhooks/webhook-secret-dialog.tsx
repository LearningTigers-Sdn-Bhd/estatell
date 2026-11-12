import { usePage } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { useState } from 'react';
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
import { toast } from '@/components/ui/toast';

type Secret = { endpointName: string; secret: string };

/**
 * Shows the signing secret once. It arrives as flash data on the redirect after Add endpoint or Rotate secret. Flash
 * data is not kept in the browser history, so Back never shows it again.
 */
export function WebhookSecretDialog() {
    const flash = usePage().flash as { webhookSecret?: Secret };
    const [dismissed, setDismissed] = useState<string | null>(null);
    const incoming = flash.webhookSecret ?? null;
    const secret = incoming && incoming.secret !== dismissed ? incoming : null;

    const copy = async () => {
        if (!secret) {
            return;
        }

        await navigator.clipboard.writeText(secret.secret);
        toast.add({ title: 'Secret copied.', type: 'success' });
    };

    return (
        <Dialog
            open={secret !== null}
            onOpenChange={(open) =>
                !open && setDismissed(secret?.secret ?? null)
            }
        >
            <DialogContent className="md:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Signing secret</DialogTitle>
                    <DialogDescription>
                        Copy this now for {secret?.endpointName}. You will not
                        see it again.
                    </DialogDescription>
                </DialogHeader>
                <div className="flex items-center gap-2">
                    <code className="min-w-0 flex-1 truncate rounded-md border bg-muted px-3 py-2 font-mono text-sm">
                        {secret?.secret}
                    </code>
                    <Button variant="outline" onClick={copy}>
                        <Copy data-icon="inline-start" />
                        Copy
                    </Button>
                </div>
                <p className="text-sm text-muted-foreground">
                    In n8n, check the X-Estatell-Signature header: the
                    HMAC-SHA256 of the X-Estatell-Timestamp header, a dot and
                    the raw body, with this secret.
                </p>
                <DialogFooter>
                    <DialogClose render={<Button />}>Done</DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
