import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CountrySelect } from '@/components/pms/country-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { DuplicateWarning } from '@/components/pms/guests/duplicate-warning';
import { PhoneInput } from '@/components/pms/phone-input';
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
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import type { GuestMatchCriteria } from '@/hooks/use-guest-matches';
import { store } from '@/routes/guests';

/**
 * Adds a guest with the few details a front desk has at hand. Only the name is required; the rest
 * is added later on the edit page. After saving, the server sends the user to the guest's page.
 */
export function AddGuestSheet({
    open,
    onOpenChange,
    countries,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    countries: EnumOption[];
}) {
    const form = useForm({
        full_name: '',
        email: '',
        phone: '',
        nationality_code: '',
    });
    const { data, errors } = form;
    const [checked, setChecked] = useState<GuestMatchCriteria>({});
    const [confirmingDiscard, setConfirmingDiscard] = useState(false);

    // Possible duplicates are looked up when a field is left, not on every key press.
    const checkForDuplicates = () =>
        setChecked({
            name: data.full_name,
            email: data.email,
            phone: data.phone,
        });

    const close = () => {
        form.reset();
        form.clearErrors();
        setChecked({});
        setConfirmingDiscard(false);
        onOpenChange(false);
    };

    const requestClose = (next: boolean) => {
        if (next) {
            onOpenChange(true);
        } else if (form.isDirty) {
            setConfirmingDiscard(true);
        } else {
            close();
        }
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(store.url(), { onSuccess: close });
    };

    return (
        <>
            <Sheet open={open} onOpenChange={requestClose}>
                <SheetContent>
                    <SheetHeader>
                        <SheetTitle>Add guest</SheetTitle>
                        <SheetDescription>
                            Only the name is required. Add the rest later by
                            editing the guest.
                        </SheetDescription>
                    </SheetHeader>
                    <form
                        onSubmit={submit}
                        className="flex min-h-0 flex-1 flex-col"
                    >
                        <FieldGroup className="flex-1 overflow-y-auto px-4">
                            <Field data-invalid={!!errors.full_name}>
                                <FieldLabel htmlFor="add-full_name">
                                    Full name
                                </FieldLabel>
                                <Input
                                    id="add-full_name"
                                    value={data.full_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'full_name',
                                            event.target.value,
                                        )
                                    }
                                    onBlur={checkForDuplicates}
                                    maxLength={150}
                                    placeholder="Aisyah binti Rahman"
                                    autoComplete="off"
                                    autoFocus
                                    aria-invalid={!!errors.full_name}
                                />
                                <FieldError>{errors.full_name}</FieldError>
                            </Field>
                            <Field data-invalid={!!errors.phone}>
                                <FieldLabel htmlFor="add-phone">
                                    Phone
                                </FieldLabel>
                                <PhoneInput
                                    id="add-phone"
                                    value={data.phone}
                                    onChange={(value) =>
                                        form.setData('phone', value)
                                    }
                                    onBlur={checkForDuplicates}
                                    invalid={!!errors.phone}
                                />
                                <FieldDescription>
                                    Without a country code, the number is read
                                    as a local number.
                                </FieldDescription>
                                <FieldError>{errors.phone}</FieldError>
                            </Field>
                            <Field data-invalid={!!errors.email}>
                                <FieldLabel htmlFor="add-email">
                                    Email
                                </FieldLabel>
                                <Input
                                    id="add-email"
                                    type="email"
                                    value={data.email}
                                    onChange={(event) =>
                                        form.setData(
                                            'email',
                                            event.target.value,
                                        )
                                    }
                                    onBlur={checkForDuplicates}
                                    maxLength={255}
                                    placeholder="guest@example.com"
                                    autoComplete="off"
                                    aria-invalid={!!errors.email}
                                />
                                <FieldError>{errors.email}</FieldError>
                            </Field>
                            <Field data-invalid={!!errors.nationality_code}>
                                <FieldLabel htmlFor="add-nationality_code">
                                    Nationality
                                </FieldLabel>
                                <CountrySelect
                                    id="add-nationality_code"
                                    options={countries}
                                    value={data.nationality_code}
                                    onValueChange={(value) =>
                                        form.setData('nationality_code', value)
                                    }
                                    invalid={!!errors.nationality_code}
                                />
                                <FieldError>
                                    {errors.nationality_code}
                                </FieldError>
                            </Field>
                            <DuplicateWarning criteria={checked} />
                        </FieldGroup>
                        <SheetFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => requestClose(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={form.processing}
                                focusableWhenDisabled
                            >
                                {form.processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Add guest
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            <AlertDialog
                open={confirmingDiscard}
                onOpenChange={setConfirmingDiscard}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Discard this guest?</AlertDialogTitle>
                        <AlertDialogDescription>
                            The details you typed have not been saved.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep editing</AlertDialogCancel>
                        <Button variant="destructive" onClick={close}>
                            Discard
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
