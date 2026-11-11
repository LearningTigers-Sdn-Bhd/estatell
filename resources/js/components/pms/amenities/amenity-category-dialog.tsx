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
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { store, update } from '@/routes/admin/amenities/categories';

type Category = App.Data.BookingEngine.AmenityCategoryData;

/**
 * Adds a category when none is passed, and renames the given one otherwise.
 * The type is set when a category is added and never changes, because its amenities have that type.
 */
export function AmenityCategoryDialog({
    category,
    typeOptions,
    open,
    onOpenChange,
}: {
    category?: Category;
    typeOptions: EnumOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = category ? update.form(category.id) : store.form();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {category ? `Edit ${category.name}` : 'Add category'}
                    </DialogTitle>
                    <DialogDescription>
                        {category
                            ? 'Every amenity in this category follows the new name.'
                            : 'The new category goes to the end of its type.'}
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
                                    <FieldLabel htmlFor="amenity-category-name">
                                        Name
                                    </FieldLabel>
                                    <Input
                                        id="amenity-category-name"
                                        name="name"
                                        defaultValue={category?.name ?? ''}
                                        placeholder="e.g. Outdoors"
                                        autoComplete="off"
                                        autoFocus
                                        aria-invalid={!!errors.name}
                                    />
                                    <FieldError>{errors.name}</FieldError>
                                </Field>
                                {!category && (
                                    <Field data-invalid={!!errors.type}>
                                        <FieldLabel htmlFor="amenity-category-type">
                                            Type
                                        </FieldLabel>
                                        <EnumSelect
                                            id="amenity-category-type"
                                            name="type"
                                            options={typeOptions}
                                            defaultValue={typeOptions[0]?.value}
                                            aria-invalid={!!errors.type}
                                        />
                                        <FieldError>{errors.type}</FieldError>
                                    </Field>
                                )}
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
                                    {category
                                        ? 'Save category'
                                        : 'Add category'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
