import { Form } from '@inertiajs/react';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { destroy } from '@/routes/admin/enquiries';

type Props = {
    enquiry: Pick<
        App.Data.Platform.AdminContactEnquiryRowData,
        'id' | 'hotelName'
    > | null;
    onOpenChange: (open: boolean) => void;
};

export function DeleteEnquiryDialog({ enquiry, onOpenChange }: Props) {
    return (
        <AlertDialog open={enquiry !== null} onOpenChange={onOpenChange}>
            {enquiry && (
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete enquiry?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This will permanently delete the enquiry from{' '}
                            {enquiry.hotelName}, including its message and
                            contact details. This cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Form
                        {...destroy.form(enquiry.id)}
                        onSuccess={() => onOpenChange(false)}
                        disableWhileProcessing
                    >
                        {({ processing }) => (
                            <AlertDialogFooter>
                                <AlertDialogCancel disabled={processing}>
                                    Cancel
                                </AlertDialogCancel>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Delete enquiry
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                </AlertDialogContent>
            )}
        </AlertDialog>
    );
}
