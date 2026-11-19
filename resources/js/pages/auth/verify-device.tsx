import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useEffect, useState } from 'react';
import TextLink from '@/components/text-link';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { login } from '@/routes';
import { resend, store } from '@/routes/login/otp';

type Props = {
    /** The user's email with most of the name hidden, for example `a***@hotel.com`. */
    email: string;
    /** Seconds until another code can be sent. */
    resendInSeconds: number;
};

export default function VerifyDevice({ email, resendInSeconds }: Props) {
    const [code, setCode] = useState<string>('');
    const [secondsLeft, setSecondsLeft] = useState<number>(resendInSeconds);

    setLayoutProps({
        title: 'Verify new device',
        description:
            'Enter the 6-digit code sent to your email to verify this device.',
    });

    useEffect(() => {
        setSecondsLeft(resendInSeconds);
    }, [resendInSeconds]);

    useEffect(() => {
        if (secondsLeft <= 0) {
            return;
        }

        const timer = window.setTimeout(
            () => setSecondsLeft((seconds) => seconds - 1),
            1000,
        );

        return () => window.clearTimeout(timer);
    }, [secondsLeft]);

    return (
        <>
            <Head title="Verify new device" />

            <div className="flex flex-col gap-6">
                <div className="flex justify-center">
                    <Badge variant="secondary">{email}</Badge>
                </div>

                <Form
                    {...store.form()}
                    className="flex flex-col gap-6"
                    resetOnError
                    onError={() => setCode('')}
                >
                    {({ errors, processing, submit }) => (
                        <>
                            <Field data-invalid={!!errors.code}>
                                <FieldLabel htmlFor="code" className="sr-only">
                                    Verification code
                                </FieldLabel>
                                <div className="flex w-full items-center justify-center">
                                    <InputOTP
                                        id="code"
                                        name="code"
                                        maxLength={OTP_MAX_LENGTH}
                                        value={code}
                                        onChange={(value) => setCode(value)}
                                        onComplete={() => submit()}
                                        disabled={processing}
                                        pattern={REGEXP_ONLY_DIGITS}
                                        autoComplete="one-time-code"
                                        autoFocus
                                        aria-invalid={!!errors.code}
                                    >
                                        <InputOTPGroup>
                                            {Array.from(
                                                { length: OTP_MAX_LENGTH },
                                                (_, index) => (
                                                    <InputOTPSlot
                                                        key={index}
                                                        index={index}
                                                    />
                                                ),
                                            )}
                                        </InputOTPGroup>
                                    </InputOTP>
                                </div>
                                <FieldError className="text-center">
                                    {errors.code}
                                </FieldError>
                            </Field>

                            <Button
                                type="submit"
                                size="lg"
                                className="h-12 w-full rounded-full"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Verify and sign in
                            </Button>
                        </>
                    )}
                </Form>

                <div className="flex flex-col items-center gap-2 text-center text-sm text-muted-foreground">
                    <Form {...resend.form()}>
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="link"
                                className="h-auto"
                                disabled={processing || secondsLeft > 0}
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                {secondsLeft > 0
                                    ? `Resend code in ${secondsLeft}s`
                                    : 'Resend code'}
                            </Button>
                        )}
                    </Form>
                    <TextLink href={login()}>Back to login</TextLink>
                </div>
            </div>
        </>
    );
}
