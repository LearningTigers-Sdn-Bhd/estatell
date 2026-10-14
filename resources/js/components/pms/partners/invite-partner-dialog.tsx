import { Form } from '@inertiajs/react';
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
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { suggestPartnerPrefix } from '@/lib/partner-prefix';
import { store } from '@/routes/admin/partners/invitations';

/**
 * Invites a partner by email. The partner row is reserved at once with the prefix. The prefix follows the partner
 * name until the admin edits it.
 */
export function InvitePartnerDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [partnerName, setPartnerName] = useState('');
    const [prefix, setPrefix] = useState('');
    const [prefixEdited, setPrefixEdited] = useState(false);

    const reset = () => {
        setPartnerName('');
        setPrefix('');
        setPrefixEdited(false);
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);

                if (!next) {
                    reset();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Invite partner by email</DialogTitle>
                    <DialogDescription>
                        They get an email with a link to set their name and
                        password. The link works for 7 days. The prefix is
                        reserved until the invitation is accepted, resent or
                        revoked.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        onOpenChange(false);
                        reset();
                    }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <FieldGroup>
                                <Field data-invalid={!!errors.partner_name}>
                                    <FieldLabel htmlFor="invite-partner-name">
                                        Partner name
                                    </FieldLabel>
                                    <Input
                                        id="invite-partner-name"
                                        name="partner_name"
                                        placeholder="Andaman Stays"
                                        autoComplete="off"
                                        autoFocus
                                        value={partnerName}
                                        aria-invalid={!!errors.partner_name}
                                        onChange={(event) => {
                                            setPartnerName(event.target.value);

                                            if (!prefixEdited) {
                                                setPrefix(
                                                    suggestPartnerPrefix(
                                                        event.target.value,
                                                    ),
                                                );
                                            }
                                        }}
                                    />
                                    <FieldError>
                                        {errors.partner_name}
                                    </FieldError>
                                </Field>
                                <Field data-invalid={!!errors.code_prefix}>
                                    <FieldLabel htmlFor="invite-partner-prefix">
                                        Property code prefix
                                    </FieldLabel>
                                    <Input
                                        id="invite-partner-prefix"
                                        name="code_prefix"
                                        placeholder="AS"
                                        autoComplete="off"
                                        maxLength={2}
                                        className="w-24 uppercase"
                                        value={prefix}
                                        aria-invalid={!!errors.code_prefix}
                                        onChange={(event) => {
                                            setPrefixEdited(true);
                                            setPrefix(
                                                event.target.value.toUpperCase(),
                                            );
                                        }}
                                    />
                                    <FieldDescription>
                                        Two letters. Their properties get codes
                                        like AS10101. It never changes.
                                    </FieldDescription>
                                    <FieldError>
                                        {errors.code_prefix}
                                    </FieldError>
                                </Field>
                                <Field data-invalid={!!errors.email}>
                                    <FieldLabel htmlFor="invite-partner-email">
                                        Email
                                    </FieldLabel>
                                    <Input
                                        id="invite-partner-email"
                                        name="email"
                                        type="email"
                                        placeholder="ops@andaman.my"
                                        autoComplete="off"
                                        aria-invalid={!!errors.email}
                                    />
                                    <FieldError>{errors.email}</FieldError>
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
                                    Send invitation
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
