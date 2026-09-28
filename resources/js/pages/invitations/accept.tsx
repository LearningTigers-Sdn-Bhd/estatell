import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { accept } from '@/routes/invitations';
import { login } from '@/routes';

type Props = {
    token: string;
    invitation: App.Data.Staff.InvitationPreviewData | null;
};

export default function AcceptInvitation({ token, invitation }: Props) {
    if (!invitation) {
        return (
            <>
                <Head title="Invitation not valid" />
                <div className="grid gap-6 text-center">
                    <p className="text-sm text-muted-foreground">
                        This link has expired, was replaced by a newer one, or
                        was already used. Ask the property to send you a new
                        invitation.
                    </p>
                    <Link href={login()} className={buttonVariants()}>
                        Go to log in
                    </Link>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Accept invitation" />

            <Form
                {...accept.form(token)}
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <p className="text-center text-sm text-muted-foreground">
                            Join <strong>{invitation.propertyName}</strong> as{' '}
                            <strong>{invitation.role}</strong>.
                        </p>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                value={invitation.email}
                                readOnly
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                name="name"
                                autoComplete="name"
                                autoFocus
                                aria-invalid={!!errors.name}
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                aria-invalid={!!errors.password}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                Confirm password
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                            />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                            focusableWhenDisabled
                        >
                            {processing && <Spinner data-icon="inline-start" />}
                            Accept and sign in
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

AcceptInvitation.layout = {
    title: 'Accept your invitation',
    description: 'Set your name and password to join the property.',
};
