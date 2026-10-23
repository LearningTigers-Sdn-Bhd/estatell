import { Form } from '@inertiajs/react';
import { useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
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
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/admin/properties/partner';

type Property = App.Data.Platform.AdminPropertyData;

/**
 * Admin only. A direct property moves to an Active partner; a partner's property moves back to direct. A move from
 * one partner to another goes through direct.
 */
export function MovePropertyPartner({
    property,
    partnerOptions,
}: {
    property: Property;
    partnerOptions: EnumOption[];
}) {
    const [open, setOpen] = useState(false);

    if (property.partnerId !== null) {
        return (
            <AlertDialog open={open} onOpenChange={setOpen}>
                <AlertDialogTrigger render={<Button variant="outline" />}>
                    Move to direct
                </AlertDialogTrigger>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Move {property.name} to direct?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {property.partnerName} loses access to this property
                            at once. The property gets a new ES code in place of{' '}
                            {property.code}, and the old link stops working.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Form
                        {...update.form(property.code)}
                        transform={() => ({ partner_id: null })}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setOpen(false)}
                    >
                        {({ processing }) => (
                            <AlertDialogFooter>
                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Move to direct
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                </AlertDialogContent>
            </AlertDialog>
        );
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger render={<Button variant="outline" />}>
                Move to partner
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Move {property.name} to a partner</DialogTitle>
                    <DialogDescription>
                        The partner can view this property and its staff. The
                        property gets a new code with the partner's prefix in
                        place of {property.code}, and the old link stops
                        working.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...update.form(property.code)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <Field data-invalid={!!errors.partner_id}>
                                <FieldLabel htmlFor="partner_id">
                                    Partner
                                </FieldLabel>
                                <Select
                                    name="partner_id"
                                    items={partnerOptions}
                                >
                                    <SelectTrigger
                                        id="partner_id"
                                        className="w-full"
                                        aria-invalid={!!errors.partner_id}
                                    >
                                        <SelectValue placeholder="Select a partner" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            {partnerOptions.map((option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                                <FieldError>{errors.partner_id}</FieldError>
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
                                    Move to partner
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
