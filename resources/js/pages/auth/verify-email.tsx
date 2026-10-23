import { Form, Head } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="Email verification" />

            {status === 'verification-link-sent' && (
                <Alert role="status">
                    <CheckCircle2 />
                    <AlertDescription>
                        A new verification link has been sent to the email
                        address you provided during registration.
                    </AlertDescription>
                </Alert>
            )}

            <Form {...send.form()} className="flex flex-col gap-6 text-center">
                {({ processing }) => (
                    <>
                        <Button
                            type="submit"
                            disabled={processing}
                            focusableWhenDisabled
                            size="lg"
                            className="h-12 w-full rounded-full"
                        >
                            {processing && <Spinner data-icon="inline-start" />}
                            Resend verification email
                        </Button>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            Log out
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Email verification',
    description:
        'Please verify your email address by clicking on the link we just emailed to you.',
};
