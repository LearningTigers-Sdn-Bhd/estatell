import { router } from '@inertiajs/react';
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
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { update as updateActive } from '@/routes/admin/webhooks/active';
import type { WebhookEndpoint } from './webhook-tabs';

/**
 * The on and off switch of one endpoint. It saves at once. Turning on an endpoint the system turned off asks first,
 * with the reason it was turned off.
 */
export function WebhookEndpointSwitch({
    endpoint,
}: {
    endpoint: WebhookEndpoint;
}) {
    const [saving, setSaving] = useState(false);
    const [confirming, setConfirming] = useState(false);

    const save = (active: boolean) =>
        router.put(
            updateActive.url(endpoint.id),
            { active },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => {
                    setSaving(false);
                    setConfirming(false);
                },
            },
        );

    return (
        <>
            <Switch
                size="sm"
                checked={endpoint.isActive}
                disabled={saving}
                aria-label={`Turn ${endpoint.name} ${endpoint.isActive ? 'off' : 'on'}`}
                onCheckedChange={(active) =>
                    active && endpoint.disabledAt
                        ? setConfirming(true)
                        : save(active)
                }
            />

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent className="md:max-w-100">
                    <DialogHeader>
                        <DialogTitle>Turn on {endpoint.name}?</DialogTitle>
                        <DialogDescription>
                            The system turned this off on {endpoint.disabledAt}
                            {endpoint.disabledReason
                                ? ` (${endpoint.disabledReason})`
                                : ''}
                            . Check that the URL works, then use Send test event
                            after turning it on.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose render={<Button variant="outline" />}>
                            Cancel
                        </DialogClose>
                        <Button
                            onClick={() => save(true)}
                            disabled={saving}
                            focusableWhenDisabled
                        >
                            {saving && <Spinner data-icon="inline-start" />}
                            Turn on
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
