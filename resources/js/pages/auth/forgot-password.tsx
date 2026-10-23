import { Form, Head } from '@inertiajs/react';
import { ArrowRight, CheckCircle2 } from 'lucide-react';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <>
            <Head title="Forgot password" />

            {status && (
                <Alert role="status">
                    <CheckCircle2 />
                    <AlertDescription>{status}</AlertDescription>
                </Alert>
            )}

            <div className="flex flex-col gap-6">
                <Form {...email.form()} className="flex flex-col gap-6">
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
                                        autoComplete="off"
                                        autoFocus
                                        aria-invalid={!!errors.email}
                                        placeholder="email@example.com"
                                    />

                                    <FieldError>{errors.email}</FieldError>
                                </Field>
                            </FieldGroup>

                            <Button
                                type="submit"
                                size="lg"
                                className="h-12 w-full rounded-full"
                                disabled={processing}
                                focusableWhenDisabled
                                data-test="email-password-reset-link-button"
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Email password reset link
                                {!processing && (
                                    <ArrowRight data-icon="inline-end" />
                                )}
                            </Button>
                        </>
                    )}
                </Form>

                <div className="border-t border-border pt-6 text-center text-sm text-muted-foreground">
                    <span>Or, return to </span>
                    <TextLink href={login()}>log in</TextLink>
                </div>
            </div>
        </>
    );
}

ForgotPassword.layout = {
    title: 'Forgot password',
    description: 'Enter your email to receive a password reset link',
};
