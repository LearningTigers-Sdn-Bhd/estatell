import { Form, Head } from '@inertiajs/react';
import { ArrowRight, CheckCircle2 } from 'lucide-react';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Log in" />

            {status && (
                <Alert role="status">
                    <CheckCircle2 />
                    <AlertDescription>{status}</AlertDescription>
                </Alert>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <FieldGroup>
                            <Field data-invalid={!!errors.email}>
                                <FieldLabel htmlFor="email">
                                    Email address
                                </FieldLabel>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
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
                                    autoComplete="current-password"
                                    placeholder="Password"
                                />
                                <FieldError>{errors.password}</FieldError>
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="self-end text-sm"
                                    >
                                        Forgot your password?
                                    </TextLink>
                                )}
                            </Field>

                            <Field orientation="horizontal">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            event.preventDefault();
                                            event.currentTarget
                                                .closest('form')
                                                ?.requestSubmit();
                                        }
                                    }}
                                />
                                <FieldLabel htmlFor="remember">
                                    Remember me
                                </FieldLabel>
                            </Field>
                        </FieldGroup>

                        <Button
                            type="submit"
                            size="lg"
                            className="h-12 w-full rounded-full"
                            disabled={processing}
                            focusableWhenDisabled
                            data-test="login-button"
                        >
                            {processing && <Spinner data-icon="inline-start" />}
                            Log in
                            {!processing && (
                                <ArrowRight data-icon="inline-end" />
                            )}
                        </Button>

                        <div className="border-t border-border pt-6 text-center text-sm text-muted-foreground">
                            Don't have an account?{' '}
                            <TextLink href={register()}>Sign up</TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    title: 'Log in to your account',
    description: 'Enter your email and password below to log in',
};
