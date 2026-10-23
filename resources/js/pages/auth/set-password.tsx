import { Form, Head } from '@inertiajs/react';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { update } from '@/routes/account/set-password';

type Props = {
    email: string;
    passwordRules: string;
};

export default function SetPassword({ email, passwordRules }: Props) {
    return (
        <>
            <Head title="Set your password" />

            <Form
                {...update.form()}
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <div className="flex flex-col gap-6">
                        <FieldGroup>
                            <Field>
                                <FieldLabel htmlFor="email">Email</FieldLabel>
                                <Input
                                    id="email"
                                    type="email"
                                    autoComplete="username"
                                    value={email}
                                    readOnly
                                />
                            </Field>

                            <Field data-invalid={!!errors.password}>
                                <FieldLabel htmlFor="password">
                                    New password
                                </FieldLabel>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    autoComplete="new-password"
                                    placeholder="New password"
                                    autoFocus
                                    passwordrules={passwordRules}
                                    aria-invalid={!!errors.password}
                                />
                                <FieldError>{errors.password}</FieldError>
                            </Field>

                            <Field>
                                <FieldLabel htmlFor="password_confirmation">
                                    Confirm password
                                </FieldLabel>
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    autoComplete="new-password"
                                    placeholder="Confirm password"
                                    passwordrules={passwordRules}
                                />
                            </Field>
                        </FieldGroup>

                        <Button
                            type="submit"
                            size="lg"
                            className="h-12 w-full rounded-full"
                            disabled={processing}
                            focusableWhenDisabled
                        >
                            {processing && <Spinner data-icon="inline-start" />}
                            Set password and continue
                        </Button>
                    </div>
                )}
            </Form>

            <div className="text-center text-sm text-muted-foreground">
                Not you?{' '}
                <TextLink href={logout()} as="button">
                    Log out
                </TextLink>
            </div>
        </>
    );
}

SetPassword.layout = {
    title: 'Set your password',
    description:
        'You signed in with a temporary password. Choose your own to continue.',
};
