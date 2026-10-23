import { Form, Head } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
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
import { login } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    passwordRules: string;
    partnerName: string | null;
    notice: string | null;
};

export default function Register({
    passwordRules,
    partnerName,
    notice,
}: Props) {
    return (
        <>
            <Head title="Register" />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        {partnerName && (
                            <p className="text-sm text-muted-foreground">
                                Joining via {partnerName}
                            </p>
                        )}
                        {notice && (
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                {notice}
                            </p>
                        )}
                        <FieldGroup>
                            <Field data-invalid={!!errors.name}>
                                <FieldLabel htmlFor="name">Name</FieldLabel>
                                <Input
                                    id="name"
                                    type="text"
                                    name="name"
                                    required
                                    autoFocus
                                    aria-invalid={!!errors.name}
                                    autoComplete="name"
                                    placeholder="Full name"
                                />
                                <FieldError>{errors.name}</FieldError>
                            </Field>

                            <Field data-invalid={!!errors.property_name}>
                                <FieldLabel htmlFor="property_name">
                                    Property name
                                </FieldLabel>
                                <Input
                                    id="property_name"
                                    type="text"
                                    name="property_name"
                                    required
                                    aria-invalid={!!errors.property_name}
                                    autoComplete="organization"
                                    placeholder="e.g. Seaview Guesthouse"
                                />
                                <FieldError>{errors.property_name}</FieldError>
                            </Field>

                            <Field data-invalid={!!errors.email}>
                                <FieldLabel htmlFor="email">
                                    Email address
                                </FieldLabel>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    aria-invalid={!!errors.email}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                />
                                <FieldError>{errors.email}</FieldError>
                            </Field>

                            <Field data-invalid={!!errors.password}>
                                <FieldLabel htmlFor="password">
                                    Password
                                </FieldLabel>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    aria-invalid={!!errors.password}
                                    autoComplete="new-password"
                                    placeholder="Password"
                                    passwordrules={passwordRules}
                                />
                                <FieldError>{errors.password}</FieldError>
                            </Field>

                            <Field
                                data-invalid={!!errors.password_confirmation}
                            >
                                <FieldLabel htmlFor="password_confirmation">
                                    Confirm password
                                </FieldLabel>
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    required
                                    aria-invalid={
                                        !!errors.password_confirmation
                                    }
                                    autoComplete="new-password"
                                    placeholder="Confirm password"
                                    passwordrules={passwordRules}
                                />
                                <FieldError>
                                    {errors.password_confirmation}
                                </FieldError>
                            </Field>
                        </FieldGroup>

                        <Button
                            type="submit"
                            size="lg"
                            className="h-12 w-full rounded-full"
                            disabled={processing}
                            focusableWhenDisabled
                            data-test="register-user-button"
                        >
                            {processing && <Spinner data-icon="inline-start" />}
                            Create account
                            {!processing && (
                                <ArrowRight data-icon="inline-end" />
                            )}
                        </Button>

                        <div className="border-t border-border pt-6 text-center text-sm text-muted-foreground">
                            Already have an account?{' '}
                            <TextLink href={login()}>Log in</TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Register.layout = {
    title: 'Create an account',
    description: 'Enter your details below to create your account',
};
