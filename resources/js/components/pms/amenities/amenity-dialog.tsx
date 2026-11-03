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
 * The type is set when an amenity is added and never changes, because properties already link to it.
 */
export function AmenityDialog({
    amenity,
    typeOptions,
    categories,
    open,
    onOpenChange,
}: {
    amenity?: Amenity;
    typeOptions: EnumOption[];
    categories: string[];
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
                            : 'The new amenity goes to the end of the list. Owners can pick it at once.'}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
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
                                {!amenity && (
                                    <Field data-invalid={!!errors.type}>
                                        <FieldLabel htmlFor="amenity-type">
                                            Type
                                        </FieldLabel>
                                        <EnumSelect
                                            id="amenity-type"
                                            name="type"
                                            options={typeOptions}
                                            defaultValue={typeOptions[0]?.value}
                                            aria-invalid={!!errors.type}
                                        />
                                        <FieldError>{errors.type}</FieldError>
                                    </Field>
                                )}
                                <Field data-invalid={!!errors.category}>
                                    <FieldLabel htmlFor="amenity-category">
                                        Category
                                    </FieldLabel>
                                    <Input
                                        id="amenity-category"
                                        name="category"
                                        list="amenity-categories"
                                        defaultValue={amenity?.category ?? ''}
                                        placeholder="e.g. Outdoors"
                                        autoComplete="off"
                                        aria-invalid={!!errors.category}
                                    />
                                    <datalist id="amenity-categories">
                                        {categories.map((category) => (
                                            <option
                                                key={category}
                                                value={category}
                                            />
                                        ))}
                                    </datalist>
                                    <FieldDescription>
                                        Pickers group amenities by category.
                                    </FieldDescription>
                                    <FieldError>{errors.category}</FieldError>
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
