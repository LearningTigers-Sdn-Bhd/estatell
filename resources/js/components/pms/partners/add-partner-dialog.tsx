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
import { store } from '@/routes/admin/partners';

/**
 * Adds a partner and its one login with a temp password. The prefix follows the partner name until the admin edits
 * it. On success the server flashes `partnerSignInMessage`, which the caller shows in a Dialog.
 */
export function AddPartnerDialog({
    open,
    onOpenChange,
    onAdded,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onAdded: (message: string) => void;
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
                    <DialogTitle>Add partner with a temp password</DialogTitle>
                    <DialogDescription>
                        No email is sent. You get a sign-in message to pass on.
                        They set their own password at first sign-in.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={(page) => {
                        const message = (
                            page.flash as
                                | { partnerSignInMessage?: string }
                                | undefined
                        )?.partnerSignInMessage;

                        onOpenChange(false);
                        reset();

                        if (message) {
                            onAdded(message);
                        }
                    }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <FieldGroup>
                                <Field data-invalid={!!errors.partner_name}>
                                    <FieldLabel htmlFor="add-partner-name">
                                        Partner name
                                    </FieldLabel>
                                    <Input
                                        id="add-partner-name"
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
                                    <FieldLabel htmlFor="add-partner-prefix">
                                        Property code prefix
                                    </FieldLabel>
                                    <Input
                                        id="add-partner-prefix"
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
                                <Field data-invalid={!!errors.name}>
                                    <FieldLabel htmlFor="add-partner-user">
                                        User name
                                    </FieldLabel>
                                    <Input
                                        id="add-partner-user"
                                        name="name"
                                        placeholder="Aina Rahman"
                                        autoComplete="off"
                                        aria-invalid={!!errors.name}
                                    />
                                    <FieldError>{errors.name}</FieldError>
                                </Field>
                                <Field data-invalid={!!errors.email}>
                                    <FieldLabel htmlFor="add-partner-email">
                                        Email
                                    </FieldLabel>
                                    <Input
                                        id="add-partner-email"
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
                                    Add partner
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
