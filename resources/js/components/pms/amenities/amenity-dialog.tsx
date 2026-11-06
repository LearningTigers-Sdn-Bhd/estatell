import { Form } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
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
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { store, update } from '@/routes/admin/amenities';

type Amenity = App.Data.BookingEngine.AmenityData;

/**
 * Adds an amenity when none is passed, and edits the name and category of the given one otherwise.
 * The categories are those of one type, so the type follows the open tab and never changes.
 */
export function AmenityDialog({
    amenity,
    categories,
    open,
    onOpenChange,
}: {
    amenity?: Amenity;
    categories: EnumOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = amenity ? update.form(amenity.id) : store.form();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {amenity ? `Edit ${amenity.name}` : 'Add amenity'}
                    </DialogTitle>
                    <DialogDescription>
                        {amenity
                            ? 'The type does not change, so properties keep what they ticked.'
                            : 'The new amenity goes to the end of its category. Owners can pick it at once.'}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...form}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <FieldGroup>
                                <Field data-invalid={!!errors.name}>
                                    <FieldLabel htmlFor="amenity-name">
                                        Name
                                    </FieldLabel>
                                    <Input
                                        id="amenity-name"
                                        name="name"
                                        defaultValue={amenity?.name ?? ''}
                                        placeholder="e.g. Swimming pool"
                                        autoComplete="off"
                                        autoFocus
                                        aria-invalid={!!errors.name}
                                    />
                                    <FieldError>{errors.name}</FieldError>
                                </Field>
                                <Field
                                    data-invalid={!!errors.amenity_category_id}
                                >
                                    <FieldLabel htmlFor="amenity-category">
                                        Category
                                    </FieldLabel>
                                    <EnumSelect
                                        id="amenity-category"
                                        name="amenity_category_id"
                                        options={categories}
                                        defaultValue={
                                            amenity
                                                ? String(amenity.categoryId)
                                                : categories[0]?.value
                                        }
                                        placeholder="Choose a category"
                                        aria-invalid={
                                            !!errors.amenity_category_id
                                        }
                                    />
                                    {categories.length === 0 && (
                                        <FieldDescription>
                                            Add a category first on the
                                            Categories tab.
                                        </FieldDescription>
                                    )}
                                    <FieldError>
                                        {errors.amenity_category_id}
                                    </FieldError>
                                </Field>
                            </FieldGroup>
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
                                    {amenity ? 'Save amenity' : 'Add amenity'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
