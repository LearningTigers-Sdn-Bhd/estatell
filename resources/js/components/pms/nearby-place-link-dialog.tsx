import { Form } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import type { RouteFormDefinition } from '@/wayfinder';

/**
 * Adds a nearby place from its Google Maps link. The server reads the name and coordinates. The dialog stays open only
 * when the link cannot be read.
 */
export function NearbyPlaceLinkDialog({
    form,
    description,
    open,
    onOpenChange,
}: {
    form: RouteFormDefinition<'post'>;
    description: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add nearby place</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field data-invalid={!!errors.google_maps_url}>
                                <FieldLabel htmlFor="nearby-place-link">
                                    Google Maps link
                                </FieldLabel>
                                <Input
                                    id="nearby-place-link"
                                    name="google_maps_url"
                                    placeholder="https://www.google.com/maps/place/..."
                                    autoComplete="off"
                                    autoFocus
                                    aria-invalid={!!errors.google_maps_url}
                                />
                                <FieldDescription>
                                    Open the place on Google Maps and copy its
                                    link.
                                </FieldDescription>
                                <FieldError>
                                    {errors.google_maps_url}
                                </FieldError>
                            </Field>
                            <DialogFooter>
                                <DialogClose
                                    render={<Button variant="outline" />}
                                >
                                    Cancel
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Add place
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
