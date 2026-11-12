import { Form } from '@inertiajs/react';
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
import { destroy } from '@/routes/admin/webhooks';
import type { WebhookEndpoint } from './webhook-tabs';

/** Confirms deleting an endpoint with its delivery log. */
export function WebhookDeleteDialog({
    endpoint,
    onOpenChange,
}: {
    endpoint: WebhookEndpoint | null;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <AlertDialog open={endpoint !== null} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Delete {endpoint?.name}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        Estatell stops sending events to this URL and deletes
                        its delivery log. This cannot be undone.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {endpoint && (
                    <Form
                        {...destroy.form(endpoint.id)}
                        onSuccess={() => onOpenChange(false)}
                    >
                        {({ processing }) => (
                            <AlertDialogFooter>
                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Delete endpoint
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                )}
            </AlertDialogContent>
        </AlertDialog>
    );
}
