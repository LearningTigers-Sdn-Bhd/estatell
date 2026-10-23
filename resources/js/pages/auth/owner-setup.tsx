import { Form, Head, Link } from '@inertiajs/react';
import PasswordInput from '@/components/password-input';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';

type Props = {
    owner: App.Data.Onboarding.OwnerSetupData | null;
    actionUrl: string | null;
    passwordRules: string;
};

export default function OwnerSetup({ owner, actionUrl, passwordRules }: Props) {
    if (!owner || !actionUrl) {
        return (
            <>
                <Head title="Setup link unavailable" />
                <div className="flex flex-col gap-6 text-center">
                    <p className="text-sm text-muted-foreground">
                        This account has already been set up. Log in to
                        continue.
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
            <Head title="Set up owner account" />

            <Form
                action={actionUrl}
                method="post"
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <div className="flex flex-col gap-6">
                        <p className="text-center text-sm leading-relaxed text-pretty text-muted-foreground">
                            Set your password for{' '}
                            <strong>{owner.propertyName}</strong>.
                        </p>

                        <FieldGroup>
                            <Field>
                                <FieldLabel htmlFor="owner_name">
                                    Owner
                                </FieldLabel>
                                <Input
                                    id="owner_name"
                                    value={owner.name}
                                    readOnly
                                />
                            </Field>

                            <Field>
                                <FieldLabel htmlFor="owner_email">
                                    Email
                                </FieldLabel>
                                <Input
                                    id="owner_email"
                                    type="email"
                                    value={owner.email}
                                    readOnly
                                />
                            </Field>

                            <Field data-invalid={!!errors.password}>
                                <FieldLabel htmlFor="password">
                                    Password
                                </FieldLabel>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    autoComplete="new-password"
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
        </>
    );
}

OwnerSetup.layout = {
    title: 'Set up your owner account',
    description:
        'Choose a password to verify your email and start property setup.',
};
