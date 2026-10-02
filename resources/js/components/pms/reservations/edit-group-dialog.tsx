import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormError } from '@/components/pms/reservations/form-error';
import { KEEP_OPTIONS } from '@/components/pms/reservations/use-form-options';
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
import { update } from '@/routes/reservations/group';

type Reservation = App.Data.Reservations.ReservationData;

function EditGroupForm({
    reservation,
    billingModes,
    onDone,
}: {
    reservation: Reservation;
    billingModes: EnumOption[];
    onDone: () => void;
}) {
    const form = useForm({
        group_name: reservation.groupName ?? '',
        billing_mode: reservation.billingMode,
    });
    const { data, errors } = form;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(update.url({ reservation: reservation.id }), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <FieldGroup>
                <FormError errors={errors} />
                <Field data-invalid={!!errors.group_name}>
                    <FieldLabel htmlFor="group-group_name">
                        Group name
                    </FieldLabel>
                    <Input
                        id="group-group_name"
                        value={data.group_name}
                        onChange={(event) =>
                            form.setData('group_name', event.target.value)
                        }
                        maxLength={150}
                        placeholder="Tan family trip"
                        autoComplete="off"
                        autoFocus
                        aria-invalid={!!errors.group_name}
                    />
                    <FieldDescription>
                        Leave empty to show the booker's name.
                    </FieldDescription>
                    <FieldError>{errors.group_name}</FieldError>
                </Field>
                {reservation.unitCount > 1 && (
                    <Field data-invalid={!!errors.billing_mode}>
                        <FieldLabel htmlFor="group-billing_mode">
                            Who pays the room charges
                        </FieldLabel>
                        <EnumSelect
                            id="group-billing_mode"
                            options={billingModes}
                            value={data.billing_mode}
                            onValueChange={(value) =>
                                form.setData('billing_mode', value)
                            }
                            aria-invalid={!!errors.billing_mode}
                        />
                        <FieldError>{errors.billing_mode}</FieldError>
                    </Field>
                )}
            </FieldGroup>
            <DialogFooter>
                <DialogClose render={<Button variant="outline" />}>
                    Cancel
                </DialogClose>
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save group
                </Button>
            </DialogFooter>
        </form>
    );
}

/**
 * Changes the group name, and who pays the room charges when the reservation has 2 or more units.
 */
export function EditGroupDialog({
    open,
    onOpenChange,
    reservation,
    billingModes,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservation: Reservation;
    billingModes: EnumOption[];
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Edit group</DialogTitle>
                    <DialogDescription>
                        {reservation.reference}
                    </DialogDescription>
                </DialogHeader>
                <EditGroupForm
                    reservation={reservation}
                    billingModes={billingModes}
                    onDone={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}
