import { Form } from '@inertiajs/react';
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
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { store, update } from '@/routes/admin/channels';

type Channel = App.Data.Channels.OtaChannelData;

/**
 * Adds a channel when no channel is passed, and renames the given one otherwise. The code is set from the name
 * when a channel is added and never changes after that.
 */
export function ChannelNameDialog({
    channel,
    open,
    onOpenChange,
}: {
    channel?: Channel;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = channel ? update.form(channel.id) : store.form();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {channel ? `Rename ${channel.name}` : 'Add channel'}
                    </DialogTitle>
                    <DialogDescription>
                        {channel
                            ? 'The code does not change, so existing logins keep their channel.'
                            : 'The new channel goes to the end of the list. Owners can pick it once it is on.'}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <FieldGroup>
                                <Field data-invalid={!!errors.name}>
                                    <FieldLabel htmlFor="channel-name">
                                        Channel name
                                    </FieldLabel>
                                    <Input
                                        id="channel-name"
                                        name="name"
                                        defaultValue={channel?.name ?? ''}
                                        placeholder="e.g. Trip.com"
                                        autoComplete="off"
                                        autoFocus
                                        aria-invalid={!!errors.name}
                                    />
                                    <FieldDescription>
                                        Use the name the OTA shows to owners.
                                    </FieldDescription>
                                    <FieldError>{errors.name}</FieldError>
                                </Field>
                            </FieldGroup>
                            <DialogFooter>
                                <DialogClose
                                    render={<Button variant="outline" />}
                                >
                                    Cancel
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    {channel ? 'Save name' : 'Add channel'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
