import { Form } from '@inertiajs/react';
import { CloudUpload } from 'lucide-react';
import BookingWebsiteController from '@/actions/App/Http/Controllers/BookingEngine/BookingWebsiteController';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

function SubmitButton({
    label,
    processing,
    disabled = false,
    className,
}: {
    label: string;
    processing: boolean;
    disabled?: boolean;
    className?: string;
}) {
    return (
        <Button
            type="submit"
            className={className}
            disabled={disabled || processing}
            focusableWhenDisabled
        >
            {processing ? (
                <Spinner data-icon="inline-start" />
            ) : (
                <CloudUpload data-icon="inline-start" />
            )}
            {label}
        </Button>
    );
}

/**
 * Publish (or Go live) on the General tab. While the site takes test payments, it asks first, because guests could then
 * book without paying real money.
 */
export function PublishSiteButton({
    label,
    canPublish,
    testPayments,
}: {
    label: string;
    canPublish: boolean;
    testPayments: boolean;
}) {
    if (!testPayments) {
        return (
            <Form {...BookingWebsiteController.publish.form()}>
                {({ processing }) => (
                    <SubmitButton
                        label={label}
                        processing={processing}
                        disabled={!canPublish}
                        className="w-full"
                    />
                )}
            </Form>
        );
    }

    return (
        <AlertDialog>
            <AlertDialogTrigger
                render={
                    <Button
                        className="w-full"
                        disabled={!canPublish}
                        focusableWhenDisabled
                    />
                }
            >
                <CloudUpload data-icon="inline-start" />
                {label}
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Publish with test payments?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        Test payments are on, so guests can book without paying
                        real money. Turn off Test payments first to take real
                        payments.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <Form {...BookingWebsiteController.publish.form()}>
                    {({ processing }) => (
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <SubmitButton
                                label={`${label} anyway`}
                                processing={processing}
                            />
                        </AlertDialogFooter>
                    )}
                </Form>
            </AlertDialogContent>
        </AlertDialog>
    );
}
